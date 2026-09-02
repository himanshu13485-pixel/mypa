import { useQuery } from '@tanstack/react-query'
import { Mail, Phone, UserPlus } from 'lucide-react'
import { people } from '../api/endpoints'
import { Avatar } from '../lib/avatars'
import { Badge, Modal } from './ui'

/**
 * Who is this?
 *
 * A name in a chat header or a connections row was the end of the line —
 * there was nowhere to tap. Everything here already existed somewhere in the
 * app; what was missing was one screen that answers the question.
 *
 * One component, opened from wherever a person's name appears, so the answer
 * is the same in the chat as in the address book.
 */
export default function PersonModal({ uuid, onClose }: { uuid: string; onClose: () => void }) {
  const { data: person, isLoading, isError } = useQuery({
    queryKey: ['person', uuid],
    queryFn: () => people.get(uuid),
  })

  return (
    <Modal title={person?.name ?? 'Profile'} onClose={onClose}>
      {isLoading ? (
        <p className="py-6 text-center text-sm text-slate-400">Loading…</p>
      ) : isError || !person ? (
        <p className="py-6 text-center text-sm text-slate-400">
          This profile is not available.
        </p>
      ) : (
        <div className="space-y-4">
          <div className="flex flex-col items-center text-center">
            <Avatar name={person.name} photoPath={person.photo_path} avatar={person.avatar} size={88} />
            <h2 className="mt-3 text-lg font-semibold">{person.name}</h2>
            {person.username && <p className="text-sm text-slate-400">@{person.username}</p>}
            {person.app_id && <p className="text-xs text-slate-400">{person.app_id}</p>}

            {/* The line most people opened this to read. */}
            {person.status && (
              <p className="mt-2 rounded-full bg-slate-100 px-3 py-1 text-sm dark:bg-slate-800">
                {person.status}
              </p>
            )}

            <div className="mt-2 flex items-center gap-2">
              {person.is_me ? (
                <Badge value="you" />
              ) : person.is_connected ? (
                <Badge value="accepted" />
              ) : person.request_status === 'sent' ? (
                <Badge value="pending" />
              ) : person.request_status === 'received' ? (
                <Badge value="pending" />
              ) : (
                <span className="flex items-center gap-1 text-xs text-slate-400">
                  <UserPlus className="size-3.5" /> Not connected
                </span>
              )}
            </div>
          </div>

          {person.bio && (
            <div>
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">About</p>
              <p className="mt-1 whitespace-pre-wrap text-sm">{person.bio}</p>
            </div>
          )}

          {/*
            * Contact details are the part of a profile only ever shared on
            * purpose, so the server sends them only to a connection. The line
            * below says why they are missing rather than looking like a
            * person who never filled anything in.
            */}
          {person.email || person.mobile ? (
            <div className="space-y-1">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Contact</p>
              {person.email && (
                <p className="flex items-center gap-2 break-all text-sm">
                  <Mail className="size-3.5 shrink-0 text-slate-400" /> {person.email}
                </p>
              )}
              {person.mobile && (
                <p className="flex items-center gap-2 text-sm">
                  <Phone className="size-3.5 shrink-0 text-slate-400" /> {person.mobile}
                </p>
              )}
            </div>
          ) : !person.is_me && !person.is_connected ? (
            <p className="text-xs text-slate-400">
              Connect with {person.name} to see how to reach them.
            </p>
          ) : null}

          {person.country && (
            <p className="text-xs text-slate-400">{person.country}</p>
          )}
        </div>
      )}
    </Modal>
  )
}
