import { useEffect, useRef, useState } from 'react'
import { Mic, MicOff, RefreshCw, SwitchCamera, Video, VideoOff } from 'lucide-react'
import { clsx } from 'clsx'
import {
  openMedia,
  AUDIO_CONSTRAINTS, VIDEO_CONSTRAINTS, loadDeviceChoice, nextCamera, saveDeviceChoice,
  speakerSelectionSupported, useDevices, useMicLevel, type DeviceChoice,
} from '../lib/devices'
import { Button, Card, ErrorNote, Input, Label, Select } from './ui'
import { Avatar } from '../lib/avatars'
import { useAuthStore } from '../stores/auth'
import { useIsPhone } from '../lib/useMediaQuery'

export interface LobbyResult {
  displayName: string
  micOn: boolean
  camOn: boolean
  devices: DeviceChoice
  /**
   * How the media should travel, if this lobby is the one that creates the
   * meeting. Null means no opinion, which is both the default and what an
   * existing meeting always sends — its transport was settled by whoever made
   * it, and the person joining is in no position to change it.
   */
  transport: 'mesh' | 'sfu' | null
}

/**
 * The room before the room. Zoom and Teams both make you check yourself
 * before anyone can see you, and it doubles as the place to fix a dead
 * microphone without a roomful of people watching you do it.
 */
export default function MeetingLobby({
  title,
  hostName,
  defaultName,
  audioOnly,
  busy,
  error,
  choosesTransport,
  onJoin,
  onCancel,
}: {
  title: string
  hostName?: string
  defaultName: string
  audioOnly?: boolean
  busy?: boolean
  error?: string | null
  /**
   * Whether walking in from here is what creates the meeting — "New meeting"
   * opens a lobby for a room that does not exist yet. Only then is there a
   * transport to choose: for a meeting that already exists the question was
   * answered when it was made, and the answer is not the joiner's to revisit.
   */
  choosesTransport?: boolean
  onJoin: (result: LobbyResult) => void
  onCancel: () => void
}) {
  // The lobby is only ever shown before joining, so this is always you —
  // a guest simply has no account and falls back to their initial.
  const me = useAuthStore((s) => s.user)
  const stored = loadDeviceChoice()
  const [displayName, setDisplayName] = useState(defaultName)
  const [micOn, setMicOn] = useState(true)
  const [camOn, setCamOn] = useState(!audioOnly)
  const [choice, setChoice] = useState<DeviceChoice>(stored)
  // '' is the absence of a choice rather than a third mode — most people
  // should not have to have an opinion about how their video gets there.
  const [transport, setTransport] = useState<'' | 'mesh' | 'sfu'>('')
  const [preview, setPreview] = useState<MediaStream | null>(null)
  const [mediaError, setMediaError] = useState<string | null>(null)

  const videoRef = useRef<HTMLVideoElement>(null)
  const streamRef = useRef<MediaStream | null>(null)
  /*
   * What is currently switched on, readable from the effects below without
   * making them re-run. Reopening on a device change must not turn a camera
   * back on that was deliberately switched off, and the reconcile below must
   * not fire again every time a device is picked.
   */
  const micOnRef = useRef(micOn)
  const camOnRef = useRef(camOn)
  const choiceRef = useRef(choice)
  micOnRef.current = micOn
  camOnRef.current = camOn
  choiceRef.current = choice
  const { cameras, mics, speakers, refresh } = useDevices()
  // Same reasoning as the call window: a phone has two cameras whether or not
  // enumerateDevices has got round to saying so.
  const isPhone = useIsPhone()
  const level = useMicLevel(preview)

  // (Re)open the preview whenever the chosen device changes. Labels only
  // appear after the first successful getUserMedia, hence the refresh().
  useEffect(() => {
    let cancelled = false

    const open = async () => {
      setMediaError(null)
      const wantVideo = camOnRef.current && !audioOnly

      // Nothing switched on: there is no preview to open, and asking for one
      // would light up the very devices that were just turned off.
      if (!micOnRef.current && !wantVideo) {
        streamRef.current?.getTracks().forEach((t) => t.stop())
        streamRef.current = new MediaStream()
        setPreview(null)
        return
      }

      try {
        /*
         * Let the old preview go before asking for a new one.
         *
         * This used to acquire first and release after, to avoid a gap in the
         * picture — but a camera on Windows is usually exclusive, so asking
         * for the device this page is already holding fails outright, and the
         * lobby reported "Camera or microphone is busy" about itself. openMedia
         * covers the other side of that trade: the device is not free the
         * instant stop() returns, so it retries.
         */
        streamRef.current?.getTracks().forEach((t) => t.stop())
        const stream = await openMedia({
          audio: micOnRef.current
            ? { ...AUDIO_CONSTRAINTS, ...(choice.micId ? { deviceId: { exact: choice.micId } } : {}) }
            : false,
          video: !wantVideo
            ? false
            // deviceId is exact and wins; facingMode is what a phone has, and
            // without it here the reverse button below would change nothing.
            : {
                ...VIDEO_CONSTRAINTS,
                ...(choice.cameraId
                  ? { deviceId: { exact: choice.cameraId } }
                  : choice.facing
                    ? { facingMode: { ideal: choice.facing } }
                    : {}),
              },
        })
        if (cancelled) {
          stream.getTracks().forEach((t) => t.stop())
          return
        }
        streamRef.current = stream
        setPreview(stream)
        if (videoRef.current) videoRef.current.srcObject = stream
        refresh()
      } catch (err) {
        if (cancelled) return
        setMediaError(
          /notallowed|permission|denied/i.test(String(err))
            ? 'Camera and microphone are blocked. Allow them in your browser, then reload.'
            : 'Camera or microphone is busy — close any other app or tab using it.',
        )
      }
    }

    void open()
    return () => {
      cancelled = true
    }
  }, [choice.cameraId, choice.facing, choice.micId, audioOnly, refresh])

  // Release the preview when we leave the lobby, either way.
  useEffect(() => () => streamRef.current?.getTracks().forEach((t) => t.stop()), [])

  /*
   * Turning a device off here releases it, rather than muting it.
   *
   * This used to set track.enabled = false. That stops the data but holds the
   * device open, so the camera light stayed on next to a preview reading
   * "Camera is off", and the microphone was still live — for however long
   * somebody sat in the lobby before pressing Join. Off should mean off.
   *
   * The other track is left alone, so muting does not make the camera blink.
   */
  useEffect(() => {
    const stream = streamRef.current
    if (!stream) return
    let cancelled = false

    const wantVideo = camOn && !audioOnly
    const release = (tracks: MediaStreamTrack[]) => tracks.forEach((t) => {
      t.stop()
      stream.removeTrack(t)
    })

    const sync = async () => {
      if (!micOn) release(stream.getAudioTracks())
      if (!wantVideo) release(stream.getVideoTracks())

      // Asked for again: the device has to be reopened, since the old track
      // was stopped and a stopped track never comes back.
      const picked = choiceRef.current
      const need: MediaStreamConstraints = {}
      if (micOn && !stream.getAudioTracks().length) {
        need.audio = { ...AUDIO_CONSTRAINTS, ...(picked.micId ? { deviceId: { exact: picked.micId } } : {}) }
      }
      if (wantVideo && !stream.getVideoTracks().length) {
        need.video = {
          ...VIDEO_CONSTRAINTS,
          ...(picked.cameraId
            ? { deviceId: { exact: picked.cameraId } }
            : picked.facing
              ? { facingMode: { ideal: picked.facing } }
              : {}),
        }
      }

      if (need.audio || need.video) {
        try {
          const extra = await navigator.mediaDevices.getUserMedia(need)
          if (cancelled || streamRef.current !== stream) {
            extra.getTracks().forEach((t) => t.stop())
            return
          }
          extra.getTracks().forEach((t) => stream.addTrack(t))
        } catch {
          if (!cancelled) setMediaError('Camera or microphone is busy — close any other app or tab using it.')
          return
        }
      }

      if (cancelled) return
      if (videoRef.current) videoRef.current.srcObject = stream
      // A fresh handle so the level meter and anything else watching the
      // preview notice that its tracks changed.
      setPreview(new MediaStream(stream.getTracks()))
    }

    void sync()
    return () => {
      cancelled = true
    }
  }, [micOn, camOn, audioOnly])

  const pick = (patch: DeviceChoice) => setChoice(saveDeviceChoice(patch))

  const join = () => {
    // Hand the preview back so the room doesn't reopen the camera and make
    // the user watch it blink off and on again.
    streamRef.current?.getTracks().forEach((t) => t.stop())
    onJoin({
      displayName: displayName.trim() || defaultName,
      micOn,
      camOn,
      devices: choice,
      transport: choosesTransport ? (transport || null) : null,
    })
  }

  return (
    <Card className="mx-auto mt-6 w-full max-w-3xl">
      <div className="grid gap-5 sm:grid-cols-[minmax(0,1fr)_240px]">
        {/* Preview */}
        <div>
          <div className="relative aspect-video overflow-hidden rounded-xl bg-slate-900">
            {!audioOnly && (
              <video
                ref={videoRef}
                autoPlay
                playsInline
                muted
                className={clsx('h-full w-full -scale-x-100 object-cover', !camOn && 'invisible')}
              />
            )}
            {(!camOn || audioOnly) && (
              <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 text-slate-300">
                <Avatar
                  name={displayName || defaultName}
                  photoPath={me?.profile?.photo_path}
                  avatar={me?.profile?.avatar}
                  gender={me?.profile?.gender}
                  size={72}
                />
                <span className="text-xs">{audioOnly ? 'Audio-only meeting' : 'Camera is off'}</span>
              </div>
            )}

            <div className="absolute inset-x-0 bottom-0 flex items-center justify-center gap-2 bg-gradient-to-t from-black/70 to-transparent p-2">
              <Button size="sm" variant={micOn ? 'secondary' : 'danger'} onClick={() => setMicOn((m) => !m)}>
                {micOn ? <Mic className="size-4" /> : <MicOff className="size-4" />}
              </Button>
              {!audioOnly && (
                <Button size="sm" variant={camOn ? 'secondary' : 'danger'} onClick={() => setCamOn((c) => !c)}>
                  {camOn ? <Video className="size-4" /> : <VideoOff className="size-4" />}
                </Button>
              )}
              {/* Checking how you look is the whole point of this screen, so
                  the wrong camera is exactly the thing to fix here rather than
                  after everyone can see you. */}
              {!audioOnly && (isPhone || cameras.length > 1 || choice.facing) && (
                <Button
                  size="sm"
                  variant="secondary"
                  title="Switch camera (front/back)"
                  onClick={() => setChoice((c) => saveDeviceChoice(nextCamera(cameras, { deviceId: c.cameraId, facing: c.facing })))}
                >
                  <SwitchCamera className="size-4" />
                </Button>
              )}
            </div>
          </div>

          {/* Mic meter — proof the microphone is actually picking you up. */}
          <div className="mt-2 flex items-center gap-2">
            <Mic className="size-3.5 shrink-0 text-slate-400" />
            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
              <div
                className={clsx('h-full rounded-full transition-[width] duration-75', micOn ? 'bg-emerald-500' : 'bg-slate-400')}
                style={{ width: `${Math.round((micOn ? level : 0) * 100)}%` }}
              />
            </div>
            <span className="w-24 shrink-0 text-right text-[11px] text-slate-400">
              {micOn ? 'Say something' : 'Muted'}
            </span>
          </div>
        </div>

        {/* Settings */}
        <div className="space-y-3">
          <div>
            <h2 className="text-sm font-semibold">{title}</h2>
            {hostName && <p className="text-xs text-slate-400">Hosted by {hostName}</p>}
          </div>

          <ErrorNote message={mediaError ?? error ?? null} />

          <div>
            <Label>Your name in this meeting</Label>
            <Input value={displayName} onChange={(e) => setDisplayName(e.target.value)} maxLength={50} />
          </div>

          {/* No password box: whoever reaches this screen has already proved
              who they are — a member by being signed in, a guest by typing the
              meeting password at the door. Asking again made one join take two
              entries of the same thing. */}

          {!audioOnly && cameras.length > 0 && (
            <div>
              <Label>Camera</Label>
              <Select value={choice.cameraId ?? ''} onChange={(e) => pick({ cameraId: e.target.value || undefined })}>
                <option value="">Default camera</option>
                {cameras.map((c) => (
                  <option key={c.deviceId} value={c.deviceId}>{c.label}</option>
                ))}
              </Select>
            </div>
          )}

          {mics.length > 0 && (
            <div>
              <Label>Microphone</Label>
              <Select value={choice.micId ?? ''} onChange={(e) => pick({ micId: e.target.value || undefined })}>
                <option value="">Default microphone</option>
                {mics.map((m) => (
                  <option key={m.deviceId} value={m.deviceId}>{m.label}</option>
                ))}
              </Select>
            </div>
          )}

          {speakerSelectionSupported() && speakers.length > 0 && (
            <div>
              <Label>Speaker</Label>
              <Select value={choice.speakerId ?? ''} onChange={(e) => pick({ speakerId: e.target.value || undefined })}>
                <option value="">Default speaker</option>
                {speakers.map((s) => (
                  <option key={s.deviceId} value={s.deviceId}>{s.label}</option>
                ))}
              </Select>
            </div>
          )}

          {/*
            * Only when walking in from here is what creates the meeting.
            * Named for what it does to the person choosing rather than for the
            * architecture: nobody outside the codebase knows what an SFU is,
            * and "direct" versus "through the server" is the whole difference.
            */}
          {choosesTransport && (
            <div>
              <Label>How it connects</Label>
              <div className="mt-1 grid grid-cols-3 gap-1">
                {([
                  { value: '', label: 'Auto' },
                  { value: 'mesh', label: 'Direct' },
                  { value: 'sfu', label: 'Server' },
                ] as const).map((option) => (
                  <button
                    key={option.value}
                    type="button"
                    onClick={() => setTransport(option.value)}
                    className={clsx(
                      'rounded-lg border px-2 py-1.5 text-xs font-medium transition',
                      transport === option.value
                        ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-500/10'
                        : 'border-slate-200 hover:border-slate-300 dark:border-slate-700 dark:hover:border-slate-600',
                    )}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
              {/*
                * The Direct warning is the one thing a person cannot work out
                * for themselves: everybody sends their own picture to everybody
                * else, so each person's upload grows with the room. That is
                * invisible until it is six people and somebody's phone is hot.
                * Nothing stops the meeting growing — so this has to be said
                * beforehand rather than enforced afterwards.
                */}
              <p className="mt-1 text-[11px] text-slate-400">
                {transport === 'mesh'
                  ? 'Straight between everyone, no server. Best up to about 6 — bigger meetings strain phones.'
                  : transport === 'sfu'
                    ? 'Everything through the server. Steady at any size.'
                    : 'Direct while the meeting is small, through the server once it grows.'}
              </p>
            </div>
          )}

          <div className="flex items-center gap-2 pt-1">
            <Button className="flex-1" onClick={join} disabled={busy}>
              {busy ? 'Joining…' : 'Join now'}
            </Button>
            <Button variant="secondary" onClick={onCancel}>Cancel</Button>
          </div>
          <button
            className="flex items-center gap-1 text-[11px] text-slate-400 hover:text-brand-600"
            onClick={refresh}
          >
            <RefreshCw className="size-3" /> Rescan devices
          </button>
        </div>
      </div>
    </Card>
  )
}
