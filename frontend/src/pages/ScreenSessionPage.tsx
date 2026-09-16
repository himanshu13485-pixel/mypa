import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Copy, Expand, Eye, EyeOff, KeyRound, Lock, LockOpen, MessageSquare, MonitorOff, MonitorUp, Pause, Play, Users, Volume2, VolumeX } from 'lucide-react'
import { Button, Card, Spinner } from '../components/ui'
import { useScreenShare } from '../components/ScreenShareManager'
import { usePrompt } from '../components/Prompt'
import { useToast } from '../components/Toast'
import { meetings as meetingsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { readGuestPass } from '../lib/guestPass'
import { useAuthStore } from '../stores/auth'
import { screenLink } from './ScreenPage'
import {
  enterFullscreen, exitFullscreen, fullscreenElement, fullscreenSupported,
} from '../lib/fullscreen'

/**
 * A screen session: the HOST captures their screen and answers offers from
 * viewers; VIEWERS connect receive-only to the host and just watch. One-way
 * fan-out on the same signalling engine as meetings.
 *
 * The session itself lives in ScreenShareManager, mounted above the router, so
 * that a host who opens another part of the app goes on sharing. This page is
 * the view onto it: it says which code to run, draws what the provider holds,
 * and takes nothing down when it goes away.
 *
 * Rendered at two addresses: /screen/session/:code for a member, and
 * /guest/screen/:code for somebody with no account who has typed the session
 * password. One component for both, exactly as the meeting room is, so that
 * what a guest watches cannot quietly drift away from what a member watches.
 */
export default function ScreenSessionPage() {
  const { code = '' } = useParams()
  const navigate = useNavigate()
  const { toast, toastError } = useToast()
  const { ask, confirm } = usePrompt()
  const {
    session, loadError, isHost, phase, errorMsg, viewers, knocks, approvalOn, paused, showPreview,
    viewerMuted, displaySurface, reconnecting, elapsed, chatOpen, chatUnread, chatMsgs, attachVideo,
    open, leavePage, stopSharing, leaveViewer, togglePause, togglePreview, toggleViewerMuted,
    setApproval, admit, setChatOpen, sendChat,
  } = useScreenShare()

  const [copied, setCopied] = useState(false)
  const [copiedBoth, setCopiedBoth] = useState(false)
  const [chatTo, setChatTo] = useState('')
  const [chatDraft, setChatDraft] = useState('')
  /**
   * The password as the host has it now.
   *
   * Held here rather than read off the session each time, because setting one
   * has to show up the instant it is saved and the session query is only
   * refetched when something else asks for it. undefined means not known:
   * either nothing has loaded, or this is not the host and never will be,
   * since only the host is sent the password itself.
   */
  const [passcode, setPasscode] = useState<string | null | undefined>(undefined)
  /** The whole session view — what fullscreen should cover. */
  const pageRef = useRef<HTMLDivElement>(null)

  /*
   * Watching on a pass rather than an account.
   *
   * Nothing on this page is decided by it except where the exits lead: a guest
   * has no Screen list to go back to and no account to sign the app in with,
   * so "back" has to mean the door they came in by. Everything host-only is
   * gated on isHost, which a guest can never be.
   */
  const account = useAuthStore((s) => s.user)
  const guestPass = useMemo(() => readGuestPass(), [])
  const isGuest = !account && guestPass?.code === code
  const exitTo = isGuest ? `/join/${code}` : '/screen'
  const exitLabel = isGuest ? 'Back to the join page' : 'Back to Screen'

  useEffect(() => {
    open(code)
  }, [code, open])

  // If the browser blocked playback, the first click anywhere resumes it.
  useEffect(() => {
    const resume = () => {
      document.querySelectorAll('video, audio').forEach((el) => {
        const media = el as HTMLMediaElement
        if (media.paused && media.srcObject) media.play().catch(() => undefined)
      })
    }
    document.addEventListener('click', resume)
    return () => document.removeEventListener('click', resume)
  }, [])

  /*
   * Walking away from the picture is not walking away from the share: the
   * provider decides what that means, and for a host it means carrying on.
   */
  useEffect(() => {
    return () => leavePage()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // The server is the authority on the password, so every fresh copy of the
  // session wins over whatever is on screen.
  useEffect(() => {
    if (!session) return
    setPasscode(session.is_host ? session.passcode ?? null : undefined)
  }, [session])

  const submitChat = () => {
    const text = chatDraft.trim()
    if (!text) return
    setChatDraft('')
    sendChat(text, chatTo)
  }

  /**
   * Set or change the session password.
   *
   * It is the one control that decides whether the link works for people
   * without an account: the engine has no second switch, and inventing one
   * here would only be a lie about what the server actually checks.
   */
  const changePasscode = async () => {
    const next = await ask({
      title: passcode ? 'Change the session password' : 'Add a session password',
      message:
        'Anyone without a Netvork account is asked for this before they can watch, and stays 30 '
        + 'minutes. Signed-in members never need it. 4–12 letters or digits.',
      value: passcode ?? '',
      placeholder: 'e.g. open1234',
      actionLabel: 'Save',
    })
    if (next === null) return

    const clean = next.replace(/[^a-zA-Z0-9]/g, '')
    if (clean.length < 4) {
      toastError('A password needs at least 4 letters or digits.')
      return
    }

    try {
      const res = await meetingsApi.setPasscode(code, clean)
      setPasscode(res.data.passcode)
      toast(res.message, 'success')
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  /** Take the password off, which also shuts the door on people with no account. */
  const clearPasscode = async () => {
    const sure = await confirm({
      title: 'Remove the password?',
      message: 'Anyone without a Netvork account will no longer be able to watch with the link. '
        + 'People already watching stay.',
      actionLabel: 'Remove it',
      danger: true,
    })
    if (!sure) return

    try {
      const res = await meetingsApi.setPasscode(code, null)
      setPasscode(null)
      toast(res.message, 'success')
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  const copy = (text: string, mark: (on: boolean) => void) => {
    navigator.clipboard.writeText(text).then(
      () => {
        mark(true)
        setTimeout(() => mark(false), 2000)
      },
      () => prompt('Copy this:', text),
    )
  }

  /*
   * Stop watching.
   *
   * leaveViewer walks a member back to the Screen list itself, but only from
   * the member's own route, so a guest is shown out by hand rather than left
   * looking at a page with nothing on it.
   */
  const stopWatching = () => {
    leaveViewer()
    if (isGuest) navigate(exitTo, { replace: true })
  }

  const fullscreen = () => {
    // The page, not the <video>: fullscreening the picture alone put the
    // controls outside the fullscreen element, so stop-sharing and leave
    // vanished until you found Escape. The same fault meetings had.
    if (fullscreenElement()) {
      void exitFullscreen()
      return
    }
    void enterFullscreen(pageRef.current)
  }

  const fmt = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`

  if (phase === 'waiting') {
    return (
      <Card className="mx-auto mt-10 max-w-md text-center">
        <p className="text-sm font-semibold">Asking the sharer to let you in…</p>
        <p className="mt-1 text-xs text-slate-400">
          You will start watching automatically the moment they admit you — keep this page open.
        </p>
        <Button className="mt-4" variant="secondary" onClick={() => navigate(exitTo)}>Cancel</Button>
      </Card>
    )
  }

  if (phase === 'denied') {
    return (
      <Card className="mx-auto mt-10 max-w-md text-center">
        <p className="text-sm font-semibold">The sharer did not admit you</p>
        <Button className="mt-4" onClick={() => navigate(exitTo)}>{exitLabel}</Button>
      </Card>
    )
  }

  if (loadError || phase === 'error' || phase === 'ended') {
    return (
      <Card className="mx-auto mt-10 max-w-md text-center">
        <p className="text-sm font-semibold">
          {phase === 'ended' ? 'Session ended' : 'Could not open this session'}
        </p>
        <p className="mt-1 text-xs text-slate-400">
          {phase === 'ended' ? 'The host has stopped sharing.' : errorMsg || 'Check the code and try again.'}
        </p>
        <Button className="mt-4" onClick={() => navigate(exitTo)}>{exitLabel}</Button>
      </Card>
    )
  }

  return (
    <div ref={pageRef} className="flex h-full flex-col gap-3 bg-slate-100 dark:bg-slate-950">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-base font-semibold">
            {isHost ? 'Sharing your screen' : `${session?.host.name ?? ''}'s screen`}
          </h1>
          {/* Wrapping, because the password chip makes this row long enough to
              run off a narrow screen otherwise. */}
          <p className="flex flex-wrap items-center gap-2 text-xs text-slate-400">
            <span className="font-mono">{code}</span>
            {phase === 'live' ? <span className="text-emerald-600">{fmt(elapsed)}</span> : 'Connecting…'}
            {isHost && (
              <span className="flex items-center gap-1">
                <Users className="size-3" /> {viewers.length} watching
                {viewers.length > 0 && `: ${viewers.map((v) => v.name).join(', ')}`}
              </span>
            )}
            {/* The password IS the guest switch, the engine having no other,
                so this is where a host decides whether the link is worth
                anything to somebody without an account. Viewers never see it:
                only the host is sent the password at all. */}
            {isHost && passcode !== undefined && (
              <span className="flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                <KeyRound className="size-3" />
                {passcode ? (
                  <>Password: <span className="select-all font-mono">{passcode}</span></>
                ) : (
                  'Members only'
                )}
                <button className="hover:text-brand-600" onClick={changePasscode}>
                  {passcode ? 'change' : 'add a password'}
                </button>
                {passcode && (
                  <button className="hover:text-red-600" onClick={clearPasscode}>remove</button>
                )}
              </span>
            )}
            {isHost && approvalOn !== null && (
              <button
                className="flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 hover:text-brand-600 dark:bg-slate-800 dark:text-slate-400"
                title={approvalOn ? 'Approval required — click for open access' : 'Open access — click to require your approval'}
                onClick={() => setApproval(!approvalOn)}
              >
                {approvalOn ? <Lock className="size-3" /> : <LockOpen className="size-3" />}
                {approvalOn ? 'Approval required' : 'Open access'}
              </button>
            )}
          </p>
          {/* What that switch actually does, in the server's own terms. */}
          {isHost && passcode !== undefined && (
            <p className="mt-1 text-[11px] text-slate-400">
              {passcode
                ? 'With a password set, anyone with the link can watch without a Netvork account.'
                : 'Without a password, only signed-in Netvork members can watch.'}
            </p>
          )}
          {isHost && knocks.length > 0 && (
            <div className="mt-1.5 space-y-1">
              {knocks.map((k) => (
                <div key={k.uuid} className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs dark:border-amber-900 dark:bg-amber-950">
                  <span className="font-medium">{k.name}</span> wants to watch
                  <Button size="sm" onClick={() => admit(k.uuid, true)}>
                    Allow
                  </Button>
                  <Button size="sm" variant="secondary" onClick={() => admit(k.uuid, false)}>
                    Deny
                  </Button>
                </div>
              ))}
            </div>
          )}
        </div>
        <div className="flex gap-1.5">
          <Button size="sm" variant="secondary" onClick={() => copy(screenLink(code), setCopied)}>
            <Copy className="size-3.5" /> {copied ? 'Copied ✓' : 'Copy link'}
          </Button>
          {/* Both halves in one go, because with a password set the link alone
              is only half of what the person on the other end needs, and two
              separate copies means two separate pastes in the right order. */}
          {isHost && passcode && (
            <Button
              size="sm"
              variant="secondary"
              title="The link and the password, ready to paste"
              onClick={() => copy(`${screenLink(code)}\nPassword: ${passcode}`, setCopiedBoth)}
            >
              <KeyRound className="size-3.5" /> {copiedBoth ? 'Copied ✓' : 'Copy link + password'}
            </Button>
          )}
        </div>
      </div>

      {/* Browser behaviour, not a fault of ours and not fixable from here: a
          captured tab stops producing frames while the host is looking at a
          different one. Said quietly, and only when it actually applies. */}
      {isHost && displaySurface === 'browser' && (
        <p className="rounded-lg bg-slate-100 px-2.5 py-1.5 text-[11px] leading-snug text-slate-500 dark:bg-slate-800 dark:text-slate-400">
          You are sharing one browser tab, so the picture freezes for everyone watching whenever you
          look at another tab. Sharing a window or your whole screen instead keeps it moving.
        </p>
      )}

      <div className="relative flex-1 overflow-hidden rounded-xl bg-slate-950">
        {(phase === 'starting' || phase === 'idle') && (
          <div className="flex h-full items-center justify-center">
            <Spinner />
          </div>
        )}
        <video ref={attachVideo} autoPlay playsInline muted={isHost || viewerMuted} className="h-full w-full object-contain" />
        {isHost && !showPreview && phase === 'live' && (
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 text-sm text-white">
            <MonitorUp className="size-8 text-emerald-400" />
            <p className="font-semibold">Your screen is being shared</p>
            <p className="text-xs text-slate-400">
              Preview is off to avoid the mirror-tunnel effect. {viewers.length} watching.
            </p>
            <p className="text-[11px] text-slate-500">
              Tip: share a single window instead of the entire screen for the cleanest result.
            </p>
          </div>
        )}
        {isHost && paused && (
          <div className="absolute inset-0 flex items-center justify-center bg-black/70 text-sm text-white">
            Sharing paused — viewers see a frozen frame
          </div>
        )}
        {/* A frozen picture says nothing. Over it, this does: the last frame is
            still underneath, so a blip that heals in a second or two leaves
            nothing but a moment's notice behind. */}
        {!isHost && reconnecting && (
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-black/70 text-sm text-white">
            <Spinner />
            <p className="font-semibold">Reconnecting…</p>
            <p className="text-xs text-slate-400">The picture is frozen while the connection comes back.</p>
          </div>
        )}
      </div>

      {chatOpen && (
        <div className="flex max-h-56 flex-col rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
          <div className="flex-1 space-y-1 overflow-y-auto">
            {!chatMsgs.length ? (
              <p className="text-center text-xs text-slate-400">No messages yet. Chat disappears when the session ends.</p>
            ) : (
              chatMsgs.map((m, i) => (
                <p key={i} className="text-sm">
                  <span className={m.me ? 'font-semibold text-brand-600' : 'font-semibold'}>{m.name}</span>
                  {m.priv && <span className="ml-1 rounded bg-amber-100 px-1 text-[10px] font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-300">private</span>}
                  <span className="ml-1.5">{m.text}</span>
                </p>
              ))
            )}
          </div>
          <div className="mt-2 flex gap-1.5">
            <select
              className="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-900"
              value={chatTo}
              onChange={(e) => setChatTo(e.target.value)}
            >
              <option value="">To everyone</option>
              {isHost
                ? viewers.map((v) => <option key={v.uuid} value={v.uuid}>Privately to {v.name}</option>)
                : session && <option value={session.host.uuid}>Privately to {session.host.name}</option>}
            </select>
            <input
              className="flex-1 rounded-lg border border-slate-200 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
              placeholder={chatTo ? 'Private message…' : 'Message everyone…'}
              value={chatDraft}
              onChange={(e) => setChatDraft(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && submitChat()}
            />
            <Button size="sm" onClick={submitChat} disabled={!chatDraft.trim()}>Send</Button>
          </div>
        </div>
      )}

      <div className="flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
        <div className="relative">
          <Button size="sm" variant={chatOpen ? 'primary' : 'secondary'} title="Session chat" onClick={() => setChatOpen(!chatOpen)}>
            <MessageSquare className="size-4" />
          </Button>
          {chatUnread > 0 && !chatOpen && (
            <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
              {chatUnread}
            </span>
          )}
        </div>
        {isHost ? (
          <>
            <Button size="sm" variant="secondary" onClick={togglePreview} title={showPreview ? 'Hide my preview' : 'Preview what viewers see'}>
              {showPreview ? <EyeOff className="size-4" /> : <Eye className="size-4" />} Preview
            </Button>
            <Button size="sm" variant="secondary" onClick={togglePause} title={paused ? 'Resume' : 'Pause sharing'}>
              {paused ? <Play className="size-4" /> : <Pause className="size-4" />} {paused ? 'Resume' : 'Pause'}
            </Button>
            <Button size="sm" variant="danger" onClick={stopSharing}>
              <MonitorOff className="size-4" /> Stop sharing
            </Button>
          </>
        ) : (
          <>
            <Button
              size="sm"
              variant="secondary"
              onClick={toggleViewerMuted}
              title={viewerMuted ? 'Unmute shared audio' : 'Mute'}
            >
              {viewerMuted ? <VolumeX className="size-4" /> : <Volume2 className="size-4" />}
            </Button>
            {fullscreenSupported() && (
              <Button size="sm" variant="secondary" onClick={fullscreen} title="Fullscreen">
                <Expand className="size-4" /> Fullscreen
              </Button>
            )}
            <Button size="sm" variant="danger" onClick={stopWatching}>
              <MonitorUp className="size-4" /> Stop viewing
            </Button>
          </>
        )}
      </div>
    </div>
  )
}
