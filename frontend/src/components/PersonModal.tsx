import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  Bell, BellOff, FileText, FolderOpen, Mail, MessageSquare, Phone, Pin, Star, Users,
} from 'lucide-react'
import { people } from '../api/endpoints'
import { Avatar } from '../lib/avatars'
import { desktopAlertsPossible, isWatching, toggleWatching } from '../lib/onlineAlerts'
import { Modal } from './ui'

/**
 * Who is this, and what do we already have between us?
 *
 * The first version answered only the first half — a name, a handle, an
 * email — which is the part you already knew if you were looking at their
 * profile from their own chat. What people actually open a profile for is the
 * second half: which groups we are both in, what one of us shared with the
 * other, and the messages either of us thought worth keeping.
 *
 * Laid out the way a messenger lays it out, because that is the shape people
 * already know: the picture and the name given room at the top, then the
 * quiet rows of detail beneath, then what we share.
 */
export default function PersonModal({ uuid, onClose }: { uuid: string; onClose: () => void }) {
  const { data: person, isLoading, isError } = useQuery({
    queryKey: ['person', uuid],
    queryFn: () => people.get(uuid),
  })

  // Per device, so this is localStorage rather than anything the query cache
  // knows about — see lib/onlineAlerts.ts for why it is not on the account.
  const [watched, setWatched] = useState(() => isWatching(uuid))
  const [canPopUp] = useState(desktopAlertsPossible)

  if (isLoading || isError || !person) {
    return (
      <Modal title="Profile" onClose={onClose}>
        <p className="py-10 text-center text-sm text-slate-400">
          {isLoading ? 'Loading…' : 'This profile is not available.'}
        </p>
      </Modal>
    )
  }

  const shared = person.shared
  const chat = shared?.conversation_uuid

  return (
    <Modal title={person.name} onClose={onClose}>
      <div className="-mx-4 -mb-4 divide-y divide-slate-100 dark:divide-slate-800">

        {/* The header, given room. A cramped avatar reads as a list row. */}
        <div className="flex flex-col items-center px-6 pb-6 pt-2 text-center">
          <Avatar name={person.name} photoPath={person.photo_path} avatar={person.avatar} size={104} />

          <h2 className="mt-4 text-xl font-semibold tracking-tight">{person.name}</h2>
          {person.username && (
            <p className="mt-0.5 text-sm text-slate-400">@{person.username}</p>
          )}

          {/* The line most people opened this to read. */}
          {person.status && (
            <p className="mt-3 max-w-xs text-sm text-slate-600 dark:text-slate-300">{person.status}</p>
          )}

          {!person.is_me && (
            <div className="mt-4 flex gap-2">
              {chat && (
                <Link
                  to={`/messages?conversation=${chat}`}
                  onClick={onClose}
                  className="flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                >
                  <MessageSquare className="size-4" /> Message
                </Link>
              )}
              {!person.is_connected && !person.request_status && (
                <span className="rounded-full bg-slate-100 px-4 py-2 text-sm text-slate-500 dark:bg-slate-800">
                  Not connected
                </span>
              )}
              {person.request_status === 'sent' && (
                <span className="rounded-full bg-amber-50 px-4 py-2 text-sm text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                  Request sent
                </span>
              )}
              {person.request_status === 'received' && (
                <span className="rounded-full bg-amber-50 px-4 py-2 text-sm text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                  Wants to connect
                </span>
              )}

              {/*
                * Waiting on this particular person.
                *
                * Here rather than in Settings because this is a decision about
                * somebody, and the place people are already looking when they
                * think "I need to catch them" is the profile they opened to
                * see whether they were around.
                */}
              {canPopUp && (
                <button
                  type="button"
                  onClick={() => setWatched(toggleWatching(uuid).watching.includes(uuid))}
                  title={
                    watched
                      ? 'You will get a pop-up on this computer when they come online.'
                      : 'Get a pop-up on this computer when they come online.'
                  }
                  className={
                    watched
                      ? 'flex items-center gap-1.5 rounded-full bg-brand-50 px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-300'
                      : 'flex items-center gap-1.5 rounded-full bg-slate-100 px-4 py-2 text-sm text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300'
                  }
                >
                  {watched ? <Bell className="size-4" /> : <BellOff className="size-4" />}
                  {watched ? 'Watching' : 'Tell me when online'}
                </button>
              )}
            </div>
          )}
        </div>

        {/* About, when there is one. Its own band so it can breathe. */}
        {person.bio && (
          <Section title="About">
            <p className="whitespace-pre-wrap text-sm leading-relaxed">{person.bio}</p>
          </Section>
        )}

        {/*
          * Contact.
          *
          * The server sends these only to a connection, so an empty block
          * here is a permission rather than a person who filled nothing in —
          * and the line below says which.
          */}
        <Section title="Contact">
          {person.email || person.mobile ? (
            <div className="space-y-2.5">
              {person.email && <Row icon={Mail} value={person.email} href={`mailto:${person.email}`} />}
              {person.mobile && <Row icon={Phone} value={person.mobile} href={`tel:${person.mobile}`} />}
            </div>
          ) : (
            <p className="text-sm text-slate-400">
              Connect with {person.name} to see how to reach them.
            </p>
          )}
          {person.app_id && (
            <p className="mt-3 text-xs text-slate-400">Netvork ID · {person.app_id}</p>
          )}
        </Section>

        {/* Held up by either of you, in this conversation. */}
        {!!shared?.pinned_messages.length && (
          <Section title="Pinned messages" icon={Pin}>
            <div className="space-y-2">
              {shared.pinned_messages.map((m) => (
                <Quote key={m.uuid} body={m.body} who={m.sender?.name} />
              ))}
            </div>
          </Section>
        )}

        {/* Kept by you. Never says whether they kept anything. */}
        {!!shared?.starred_messages.length && (
          <Section title="Starred by you" icon={Star}>
            <div className="space-y-2">
              {shared.starred_messages.map((m) => (
                <Quote key={m.uuid} body={m.body} who={m.sender?.name} />
              ))}
            </div>
          </Section>
        )}

        {!!shared?.groups.length && (
          <Section title={`${shared.groups.length} group${shared.groups.length === 1 ? '' : 's'} in common`} icon={Users}>
            <div className="flex flex-wrap gap-1.5">
              {shared.groups.map((g) => (
                <span
                  key={g.uuid}
                  className="rounded-full bg-slate-100 px-3 py-1 text-xs dark:bg-slate-800"
                >
                  {g.name}
                </span>
              ))}
            </div>
          </Section>
        )}

        {/*
          * Shared either way round.
          *
          * A note they shared with me belongs here exactly as much as one I
          * shared with them; showing only my own half would make the
          * relationship look one-sided.
          */}
        {!!(shared && (shared.notes.length || shared.files.length || shared.projects.length)) && (
          <Section title="Shared between you">
            <div className="space-y-1.5">
              {shared.notes.map((n) => (
                <Shared key={n.uuid} icon={FileText} label={n.title || 'Untitled note'} mine={n.mine} />
              ))}
              {shared.files.map((f) => (
                <Shared key={f.uuid} icon={FolderOpen} label={f.name} mine={f.mine} />
              ))}
              {shared.projects.map((p) => (
                <Shared key={p.uuid} icon={FolderOpen} label={p.name} mine={p.mine} />
              ))}
            </div>
          </Section>
        )}
      </div>
    </Modal>
  )
}

/** A band of the sheet: a quiet heading, then its content. */
function Section({
  title,
  icon: Icon,
  children,
}: {
  title: string
  icon?: typeof Star
  children: React.ReactNode
}) {
  return (
    <div className="px-6 py-4">
      <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
        {Icon && <Icon className="size-3.5" />}
        {title}
      </p>
      {children}
    </div>
  )
}

function Row({ icon: Icon, value, href }: { icon: typeof Mail; value: string; href: string }) {
  return (
    <a href={href} className="flex items-center gap-3 text-sm hover:underline">
      <Icon className="size-4 shrink-0 text-slate-400" />
      <span className="break-all">{value}</span>
    </a>
  )
}

/**
 * A message shown out of its thread.
 *
 * Truncated on purpose: this is a reminder of which message, not the message.
 * Whoever wrote it is named because a quote with no author reads as the
 * profile's own words.
 */
function Quote({ body, who }: { body?: string | null; who?: string | null }) {
  return (
    <div className="rounded-lg border-l-2 border-brand-500 bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
      <p className="line-clamp-2 text-sm">{body || 'Attachment'}</p>
      {who && <p className="mt-0.5 text-[11px] text-slate-400">{who}</p>}
    </div>
  )
}

function Shared({
  icon: Icon,
  label,
  mine,
}: {
  icon: typeof FileText
  label: string
  mine: boolean
}) {
  return (
    <div className="flex items-center gap-2.5 text-sm">
      <Icon className="size-4 shrink-0 text-slate-400" />
      <span className="min-w-0 flex-1 truncate">{label}</span>
      <span className="shrink-0 text-[11px] text-slate-400">
        {mine ? 'you shared' : 'shared with you'}
      </span>
    </div>
  )
}
