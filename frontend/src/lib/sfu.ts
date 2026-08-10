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
  disconnect: () => void
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
      // A fresh MediaStream each time would restart playback on every roster
      // change; the room compares by uuid and only re-attaches when the track
      // set actually differs.
      stream: tracks.length ? new MediaStream(tracks) : null,
    }
  })
}

export async function joinSfu(
  url: string,
  token: string,
  local: MediaStream | null,
  callbacks: SfuCallbacks,
): Promise<SfuSession> {
  // Imported here rather than at module scope so the mesh path never pays for
  // the SDK's weight.
  const { Room: LiveKitRoom, RoomEvent, ConnectionState } = await import('livekit-client')

  const options: RoomOptions = {
    // The SFU forwards whichever layer suits each receiver, so the sender
    // publishes several and stops having to guess. This is the thing a mesh
    // fundamentally cannot do, and the reason a big room works at all.
    publishDefaults: { simulcast: true },
    // Sends only what is actually on screen. A gallery of thirty tiles has no
    // use for thirty full-resolution streams.
    adaptiveStream: true,
    dynacast: true,
  }

  const room = new LiveKitRoom(options)
  const announce = () => callbacks.onPeers(peersFrom(room))

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
    disconnect: () => {
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
