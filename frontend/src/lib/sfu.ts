import type {
  Participant, RemoteTrack, RemoteTrackPublication, Room, RoomOptions,
} from 'livekit-client'

/**
 * The SFU half of a meeting.
 *
 * A mesh has every browser talking to every other, so each person uploads
 * their own picture once per participant and the cost grows with the room —
 * which is why it runs out at six or eight people. An SFU inverts that: one
 * stream up to the server, and the server does the copying.
 *
 * This deliberately hands back the same shapes the mesh path already produces
 * — a uuid, a name, a MediaStream per peer — so the room's tiles, roster,
 * layouts, pinning and host controls do not need to know which transport they
 * are being fed by. The two differ in how media arrives and in nothing else.
 *
 * Loaded on demand: livekit-client is a few hundred kilobytes, and a meeting
 * on the mesh should not pay for it.
 */

export interface SfuPeer {
  uuid: string
  name: string
  stream: MediaStream | null
}

export interface SfuCallbacks {
  onPeers: (peers: SfuPeer[]) => void
  /** Connecting, reconnecting, or gone — the room shows this the same way it
      shows a mesh connection going bad. */
  onState: (state: 'connecting' | 'connected' | 'reconnecting' | 'closed') => void
  onError: (message: string) => void
}

export interface SfuSession {
  room: Room
  /** Swap what we publish — the same job replaceTrack does on the mesh. */
  publish: (stream: MediaStream) => Promise<void>
  setMicEnabled: (on: boolean) => Promise<void>
  setCameraEnabled: (on: boolean) => Promise<void>
  /**
   * Who is on screen large, best first — from the room's own tile ranking, so
   * the server is asked for full quality exactly where it will be seen.
   */
  setFocus: (uuids: string[]) => void
  disconnect: () => void
}

/**
 * One MediaStream per participant, kept for as long as they are in the room.
 *
 * This has to be the same object every time or the room flickers. A tile
 * attaches by assigning to video.srcObject and skips the work if it is already
 * holding that stream — an identity check, because there is no cheap way to
 * compare two MediaStreams by value. Handing out a new object with the same
 * tracks inside therefore reads as "different stream": every video element
 * re-attaches and restarts playback.
 *
 * And this is recomputed on every LiveKit event, including someone muting.
 * So one person pressing mute made everybody's video blink, on every device in
 * the room, which is not what anyone would guess mute does.
 *
 * Tracks are added and removed in place instead. The stream survives; only its
 * contents change, which is what actually happened.
 */
const streams = new WeakMap<Room, Map<string, MediaStream>>()

function streamFor(room: Room, uuid: string, tracks: MediaStreamTrack[]): MediaStream | null {
  let byUuid = streams.get(room)
  if (!byUuid) streams.set(room, byUuid = new Map())

  if (!tracks.length) {
    // Kept rather than deleted: somebody who turns their camera off and on
    // again should get their existing stream back, not a new one.
    const empty = byUuid.get(uuid)
    empty?.getTracks().forEach((t) => empty.removeTrack(t))

    return null
  }

  let stream = byUuid.get(uuid)
  if (!stream) {
    stream = new MediaStream(tracks)
    byUuid.set(uuid, stream)

    return stream
  }

  const want = new Set(tracks)
  for (const had of stream.getTracks()) if (!want.has(had)) stream.removeTrack(had)
  const have = new Set(stream.getTracks())
  for (const track of tracks) if (!have.has(track)) stream.addTrack(track)

  return stream
}

/**
 * Identity is the participant's uuid, set when the token was signed. Using the
 * same one the roster and host controls already use means LiveKit's view of
 * who is in the room and ours cannot drift apart.
 */
function peersFrom(room: Room): SfuPeer[] {
  return [...room.remoteParticipants.values()].map((p) => {
    const tracks = [...p.trackPublications.values()]
      .map((pub) => pub.track?.mediaStreamTrack)
      .filter((t): t is MediaStreamTrack => !!t)

    return {
      uuid: p.identity,
      name: p.name || p.identity,
      stream: streamFor(room, p.identity, tracks),
    }
  })
}

/**
 * What one uplink is asked to carry, whatever the room size.
 *
 * The same idea as the mesh's budget and a different sum. On a mesh the budget
 * is divided by the number of peers, because you send a separate copy to each
 * of them. Here you send once, so this is simply what you send — and it does
 * not move when somebody joins. That is the whole difference between six
 * people being the limit and fifty being possible.
 *
 * A phone gets less: it is usually on an uplink measured in single-digit
 * megabits and it is encoding every layer itself, so it runs hot long before
 * it runs out of bandwidth.
 */
function publishBudget(mobile: boolean) {
  return mobile
    ? { maxBitrate: 600_000, maxFramerate: 24 }
    // 2 Mbps rather than 1.5: this is the top simulcast layer, so it is what a
    // room small enough to ask for full quality actually receives, and 720p at
    // 1.5 was visibly soft on a large tile.
    : { maxBitrate: 2_000_000, maxFramerate: 30 }
}

export type ReceiveQuality = 'high' | 'medium' | 'low'

/** How many tiles are worth full quality at once. */
export const FOCUS_LIMIT = 4

/**
 * How long somebody keeps full quality after dropping out of the focus.
 *
 * The focus is ranked by who is speaking, so without this a conversation
 * between three people would re-request layers every time the speaker changed
 * — and every layer change costs a keyframe, which is the flicker. Holding the
 * old quality briefly means a normal back-and-forth costs nothing at all.
 */
export const DEMOTE_AFTER_MS = 12_000

/**
 * What to ask the server for, per participant.
 *
 * This is the job LiveKit's adaptiveStream does, driven by something that does
 * not wobble. Element size is the more precise signal and it is why the picture
 * flickered: adaptiveStream re-decides whenever an element resizes, switching
 * layer needs a fresh keyframe, and a tile sitting near one of its thresholds
 * flips back and forth for ever. Dragging a window, opening devtools and
 * certain phones all set it off, because all of them change how large a tile
 * renders.
 *
 * The room's own ranking does not wobble like that. It already decides who is
 * shown large — pinned first, then spotlit, then whoever is speaking — so the
 * top few get everything and the rest get the small layer they are being drawn
 * at anyway.
 *
 * @param order    from rankTiles, best first.
 * @param present  everyone the server has for us.
 * @param highSince when each participant last became full quality. Mutated:
 *   it is the memory that makes the hold above work across calls.
 */
export function focusQualities(
  order: string[],
  present: string[],
  highSince: Map<string, number>,
  now: number,
): Map<string, ReceiveQuality> {
  const focused = new Set(order.slice(0, FOCUS_LIMIT))
  const out = new Map<string, ReceiveQuality>()

  for (const uuid of present) {
    if (focused.has(uuid)) {
      if (!highSince.has(uuid)) highSince.set(uuid, now)
      out.set(uuid, 'high')
      continue
    }

    const since = highSince.get(uuid)
    if (since !== undefined && now - since < DEMOTE_AFTER_MS) {
      out.set(uuid, 'high')
      continue
    }

    highSince.delete(uuid)
    out.set(uuid, 'low')
  }

  // Anyone gone is gone; leaving them here would hold a seat in the map for
  // the rest of the meeting and give them full quality if they came back.
  for (const uuid of [...highSince.keys()]) {
    if (!present.includes(uuid)) highSince.delete(uuid)
  }

  return out
}

export async function joinSfu(
  url: string,
  token: string,
  local: MediaStream | null,
  callbacks: SfuCallbacks,
  { mobile = false }: { mobile?: boolean } = {},
): Promise<SfuSession> {
  // Imported here rather than at module scope so the mesh path never pays for
  // the SDK's weight.
  const { Room: LiveKitRoom, RoomEvent, ConnectionState } = await import('livekit-client')

  const options: RoomOptions = {
    publishDefaults: {
      /*
       * Publish several qualities at once and let the server hand each viewer
       * whichever fits their tile and their connection.
       *
       * This is the mesh's adaptive bitrate done properly. There, one number
       * had to serve everybody, so it was set by the worst case and the room
       * as a whole got quieter as it filled. Here the choice is made per
       * viewer, downstream, by something that knows how big your tile is on
       * their screen — so the person watching you full-screen gets the sharp
       * layer while the gallery gets a thumbnail, at the same time.
       */
      simulcast: true,
      // Named rather than left to the SDK's default, so what a participant
      // uploads is a number we chose and can be held to.
      videoEncoding: publishBudget(mobile),
    },
    /*
     * Both off, deliberately, and both would be worth having in a bigger room.
     *
     * adaptiveStream picks a simulcast layer from how large each video element
     * renders, so a gallery of thirty tiles need not carry thirty full
     * streams. The catch is that it re-decides whenever an element resizes,
     * and switching layer needs a fresh keyframe — visible as a flicker. A
     * tile whose size sits near one of its thresholds flips back and forth and
     * flickers continuously, which is what happens when the window is not
     * maximised, when devtools open, and on some phones and not others,
     * because all of those change how big a tile renders.
     *
     * dynacast stops forwarding layers nobody is watching, and pays for it
     * with the same keyframe stall when somebody starts watching again.
     *
     * This app shows at most nine tiles (see videoLayout), so the saving is
     * bounded and the flicker is not. If rooms ever get much larger these
     * should come back — the flicker is a real cost, but so is a phone
     * decoding thirty streams.
     */
    adaptiveStream: false,
    dynacast: false,
  }

  const room = new LiveKitRoom(options)

  /*
   * Ask for each participant's quality, and only where it has changed.
   *
   * Re-requesting the layer we are already receiving is not free — the server
   * answers with a keyframe — so asking for everything on every event would
   * reintroduce the flicker this replaced.
   */
  let order: string[] = []
  const highSince = new Map<string, number>()
  const applied = new Map<string, ReceiveQuality>()

  const applyQuality = async () => {
    const present = [...room.remoteParticipants.keys()]
    const want = focusQualities(order, present, highSince, Date.now())
    const changed = [...want].filter(([uuid, q]) => applied.get(uuid) !== q)
    if (!changed.length) return

    const { VideoQuality } = await import('livekit-client')
    for (const [uuid, q] of changed) {
      const participant = room.remoteParticipants.get(uuid)
      if (!participant) continue
      for (const pub of participant.trackPublications.values()) {
        if (pub.kind === 'video') pub.setVideoQuality(q === 'high' ? VideoQuality.HIGH : VideoQuality.LOW)
      }
      applied.set(uuid, q)
    }
    for (const uuid of [...applied.keys()]) if (!want.has(uuid)) applied.delete(uuid)
    console.info('[meeting] quality', changed.map(([u, q]) => `${u.slice(0, 8)}:${q}`).join(' '))
  }

  const announce = () => {
    void applyQuality()
    callbacks.onPeers(peersFrom(room))
  }

  /*
   * Nothing may happen for a while after somebody stops speaking, and a
   * demotion that is waiting out its hold needs something to come back and
   * carry it out. Cheap: it does nothing unless an answer has actually
   * changed.
   */
  const sweep = window.setInterval(() => void applyQuality(), 5_000)

  room
    .on(RoomEvent.ParticipantConnected, announce)
    .on(RoomEvent.ParticipantDisconnected, announce)
    .on(RoomEvent.TrackSubscribed, (_t: RemoteTrack, _p: RemoteTrackPublication, _who: Participant) => announce())
    .on(RoomEvent.TrackUnsubscribed, () => announce())
    .on(RoomEvent.TrackMuted, announce)
    .on(RoomEvent.TrackUnmuted, announce)
    .on(RoomEvent.ConnectionStateChanged, (state: unknown) => {
      if (state === ConnectionState.Connected) callbacks.onState('connected')
      else if (state === ConnectionState.Reconnecting) callbacks.onState('reconnecting')
      else if (state === ConnectionState.Disconnected) callbacks.onState('closed')
    })
    .on(RoomEvent.Disconnected, () => callbacks.onState('closed'))

  callbacks.onState('connecting')
  try {
    await room.connect(url, token)
  } catch (err) {
    callbacks.onError(
      err instanceof Error && /token|permission|unauthor/i.test(err.message)
        ? 'This meeting would not let us in. Try leaving and joining again.'
        : 'Could not reach the meeting server.',
    )
    throw err
  }

  const publish = async (stream: MediaStream) => {
    // Unpublish first: publishing a second camera track leaves both live, and
    // everyone sees whichever the SFU happens to forward.
    await Promise.all(
      [...room.localParticipant.trackPublications.values()]
        .filter((pub) => pub.track)
        .map((pub) => room.localParticipant.unpublishTrack(pub.track!)),
    )
    for (const track of stream.getTracks()) {
      await room.localParticipant.publishTrack(track)
    }
  }

  if (local) await publish(local)
  announce()

  return {
    room,
    publish,
    setMicEnabled: (on) => room.localParticipant.setMicrophoneEnabled(on).then(() => undefined),
    setCameraEnabled: (on) => room.localParticipant.setCameraEnabled(on).then(() => undefined),
    setFocus: (uuids) => {
      // Cheap enough to call on every render of the tile grid: applyQuality
      // does nothing unless an answer has actually moved.
      order = uuids
      void applyQuality()
    },
    disconnect: () => {
      window.clearInterval(sweep)
      room.removeAllListeners()
      void room.disconnect()
    },
  }
}

/** Screen share, which on an SFU is just another published track. */
export async function publishScreen(session: SfuSession, track: MediaStreamTrack): Promise<void> {
  // Also from the dynamic import: a top-level one would pull the whole SDK
  // into the main bundle for every page that never opens a meeting.
  const { Track } = await import('livekit-client')
  await session.room.localParticipant.publishTrack(track, { source: Track.Source.ScreenShare })
}
