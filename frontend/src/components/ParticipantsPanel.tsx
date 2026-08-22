import { useEffect, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import {
  Crown, Hand, Mic, MicOff, MoreVertical, Pin, PinOff, Signal, Star, UserMinus, Video, VideoOff, Volume2,
} from 'lucide-react'
import { clsx } from 'clsx'
import type { MeetingHostAction, MeetingParticipant } from '../types'
import type { PeerStats, Quality } from '../lib/netQuality'
import { Button } from './ui'
import { Avatar } from '../lib/avatars'

const QUALITY_COLOR: Record<Quality, string> = {
  good: 'text-emerald-500',
  fair: 'text-amber-500',
  poor: 'text-red-500',
  unknown: 'text-slate-300 dark:text-slate-600',
}

export function QualityDot({ stats, className }: { stats?: PeerStats; className?: string }) {
  const q = stats?.quality ?? 'unknown'
  const title = stats && q !== 'unknown'
    ? `Connection ${q} — ${stats.lossPct.toFixed(1)}% loss, ${Math.round(stats.rttMs)} ms, ${Math.round(stats.jitterMs)} ms jitter`
    : 'Measuring connection…'

  return <Signal className={clsx('size-3.5', QUALITY_COLOR[q], className)} aria-label={title}><title>{title}</title></Signal>
}

/**
 * The roster, and every moderation control that acts on one person.
 * Non-moderators get the same list without the action menu — knowing who is
 * in the room and whether their hand is up is not a host-only privilege.
 */
export default function ParticipantsPanel({
  me,
  participants,
  canModerate,
  isHost,
  isLocked,
  spotlightUuid,
  pinnedUuid,
  quality,
  onAction,
  onPin,
  onClose,
}: {
  me: MeetingParticipant
  participants: MeetingParticipant[]
  canModerate: boolean
  isHost: boolean
  isLocked: boolean
  spotlightUuid: string | null
  pinnedUuid: string | null
  quality: Record<string, PeerStats>
  onAction: (action: MeetingHostAction, userUuid?: string) => void
  onPin: (uuid: string | null) => void
  onClose: () => void
}) {
  /*
   * Which row's menu is open, and where to draw it.
   *
   * It used to be positioned inside its own <li>, which put it inside the
   * scrolling roster — and a menu opened on anyone near the bottom was cut
   * off by that scroll container, the lower half of it simply not there. So
   * the controls that matter most on a long list were the ones you could not
   * reach. It is portalled to <body> at fixed coordinates now, the same
   * treatment UserSuggest already uses for the same reason.
   */
  const [openMenu, setOpenMenu] = useState<{ uuid: string; left: number; top: number } | null>(null)

  // Fixed coordinates do not travel with the row they were measured from, so
  // any scroll or resize leaves the menu pointing at nothing. Close it.
  useEffect(() => {
    if (!openMenu) return
    const close = () => setOpenMenu(null)
    window.addEventListener('scroll', close, true)
    window.addEventListener('resize', close)
    return () => {
      window.removeEventListener('scroll', close, true)
      window.removeEventListener('resize', close)
    }
  }, [openMenu])
  const everyone = [me, ...participants]
  const handsUp = everyone.filter((p) => p.hand_raised).length

  return (
    // Stacked under the tiles on a phone, capped so it never squeezes the
    // video into a strip; a proper side column from sm up.
    <div className="flex max-h-[45%] w-full shrink-0 flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 sm:max-h-none sm:w-72">
      <div className="flex items-center justify-between border-b border-slate-200 px-3 py-2 dark:border-slate-800">
        <p className="text-xs font-semibold">
          People ({everyone.length}){handsUp > 0 && <span className="ml-1 text-amber-500">· {handsUp} raised</span>}
        </p>
        <button className="text-xs text-slate-400 hover:text-brand-600" onClick={onClose}>Close</button>
      </div>

      {canModerate && (
        <div className="flex flex-wrap gap-1.5 border-b border-slate-200 px-3 py-2 dark:border-slate-800">
          <Button size="sm" variant="secondary" onClick={() => onAction('mute_all')} title="Mute everyone except you">
            <MicOff className="size-3.5" /> Mute all
          </Button>
          <Button
            size="sm"
            variant={isLocked ? 'danger' : 'secondary'}
            onClick={() => onAction(isLocked ? 'unlock' : 'lock')}
            title={isLocked ? 'Let people join again' : 'Stop anyone else from joining'}
          >
            {isLocked ? 'Unlock' : 'Lock'}
          </Button>
          {spotlightUuid && (
            <Button size="sm" variant="secondary" onClick={() => onAction('clear_spotlight')}>
              Clear spotlight
            </Button>
          )}
        </div>
      )}

      <ul className="scroll-pane min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
        {everyone.map((p) => {
          const isMe = p.uuid === me.uuid
          const canActOn = canModerate && !isMe && p.role !== 'host'

          return (
            <li key={p.uuid} className="relative flex items-center gap-2 px-3 py-2 text-sm">
              <Avatar name={p.name} avatar={p.avatar} size={30} />

              <span className="min-w-0 flex-1 truncate">
                {p.name}{isMe && <span className="text-slate-400"> (you)</span>}
                {p.role !== 'participant' && (
                  <span className="ml-1 inline-flex items-center gap-0.5 rounded bg-amber-100 px-1 text-[10px] font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                    <Crown className="size-2.5" />{p.role === 'host' ? 'Host' : 'Co-host'}
                  </span>
                )}
                {spotlightUuid === p.uuid && <Star className="ml-1 inline size-3 text-amber-500" />}
              </span>

              {p.hand_raised && <Hand className="size-3.5 shrink-0 text-amber-500" />}
              {!isMe && <QualityDot stats={quality[p.uuid]} />}
              {p.mic_on ? <Mic className="size-3.5 shrink-0 text-slate-400" /> : <MicOff className="size-3.5 shrink-0 text-red-500" />}
              {p.cam_on ? <Video className="size-3.5 shrink-0 text-slate-400" /> : <VideoOff className="size-3.5 shrink-0 text-red-500" />}

              {!isMe && (
                <button
                  className="shrink-0 rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"
                  title="Options"
                  onClick={(e) => setOpenMenu((m) => {
                    if (m?.uuid === p.uuid) return null
                    const r = e.currentTarget.getBoundingClientRect()
                    // Roughly the tallest the menu gets. Flipped above the
                    // button when the bottom of the window is closer than it.
                    const height = 260
                    const below = window.innerHeight - r.bottom
                    return {
                      uuid: p.uuid,
                      left: Math.max(8, Math.min(r.right - 192, window.innerWidth - 200)),
                      top: below < height && r.top > below ? Math.max(8, r.top - height) : r.bottom + 4,
                    }
                  })}
                >
                  <MoreVertical className="size-4" />
                </button>
              )}

              {openMenu?.uuid === p.uuid && createPortal(
                <>
                  {/* Tapping anywhere else puts it away. onMouseLeave alone
                      never fires on a touchscreen, and a menu floating above
                      the whole room with no way to dismiss it is worse than
                      one that was merely clipped. */}
                  <div className="fixed inset-0 z-[77]" onMouseDown={() => setOpenMenu(null)} />
                  <div
                    // z-78: above the meeting window at z-60 and its own tile
                    // menu at z-76, below dialogs at z-80 and toasts at z-100.
                    className="fixed z-[78] max-h-[70vh] w-48 overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 text-xs shadow-lg dark:border-slate-700 dark:bg-slate-900"
                    style={{ left: openMenu.left, top: openMenu.top }}
                    onMouseLeave={() => setOpenMenu(null)}
                  >
                    <MenuItem
                      icon={pinnedUuid === p.uuid ? <PinOff className="size-3.5" /> : <Pin className="size-3.5" />}
                      label={pinnedUuid === p.uuid ? 'Unpin' : 'Pin for me'}
                      onClick={() => { onPin(pinnedUuid === p.uuid ? null : p.uuid); setOpenMenu(null) }}
                    />
                    {canActOn && (
                      <>
                        <MenuItem
                          icon={<Star className="size-3.5" />}
                          label={spotlightUuid === p.uuid ? 'Remove spotlight' : 'Spotlight for everyone'}
                          onClick={() => { onAction(spotlightUuid === p.uuid ? 'clear_spotlight' : 'spotlight', p.uuid); setOpenMenu(null) }}
                        />
                        {p.mic_on ? (
                          <MenuItem
                            icon={<MicOff className="size-3.5" />}
                            label="Mute"
                            onClick={() => { onAction('mute', p.uuid); setOpenMenu(null) }}
                          />
                        ) : (
                          <MenuItem
                            icon={<Volume2 className="size-3.5" />}
                            label="Ask to unmute"
                            onClick={() => { onAction('ask_unmute', p.uuid); setOpenMenu(null) }}
                          />
                        )}
                        {p.cam_on && (
                          <MenuItem
                            icon={<VideoOff className="size-3.5" />}
                            label="Stop video"
                            onClick={() => { onAction('stop_video', p.uuid); setOpenMenu(null) }}
                          />
                        )}
                        <MenuItem
                          icon={<Crown className="size-3.5" />}
                          label={p.role === 'cohost' ? 'Remove co-host' : 'Make co-host'}
                          onClick={() => { onAction(p.role === 'cohost' ? 'demote' : 'promote', p.uuid); setOpenMenu(null) }}
                        />
                        {isHost && (
                          <MenuItem
                            icon={<Crown className="size-3.5" />}
                            label="Make host"
                            onClick={() => {
                              setOpenMenu(null)
                              if (confirm(`Hand the meeting over to ${p.name}? You become a co-host.`)) {
                                onAction('transfer_host', p.uuid)
                              }
                            }}
                          />
                        )}
                        <MenuItem
                          icon={<UserMinus className="size-3.5" />}
                          label="Remove from meeting"
                          danger
                          onClick={() => {
                            setOpenMenu(null)
                            if (confirm(`Remove ${p.name} from this meeting?`)) onAction('remove', p.uuid)
                          }}
                        />
                      </>
                    )}
                  </div>
                </>,
                document.body,
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

function MenuItem({
  icon, label, onClick, danger,
}: { icon: ReactNode; label: string; onClick: () => void; danger?: boolean }) {
  return (
    <button
      className={clsx(
        'flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-slate-100 dark:hover:bg-slate-800',
        danger && 'text-red-600 dark:text-red-400',
      )}
      onClick={onClick}
    >
      {icon} {label}
    </button>
  )
}
