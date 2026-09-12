import { useQuery } from '@tanstack/react-query'
import { Lock, MessageSquare, Paperclip } from 'lucide-react'
import { clsx } from 'clsx'
import { meetings as meetingsApi } from '../api/endpoints'
import { EmptyState, Modal, Spinner } from './ui'

/**
 * What was said in a meeting, read after it.
 *
 * The chat used to live only in the tabs that were open at the time, so the
 * link somebody pasted and the figure somebody read out went when the call
 * did. This is the same conversation, kept - openable from the meeting or
 * screen share it belonged to, however long ago that was.
 *
 * Private lines come back only to the two people on them; the server
 * decides that, and marks the ones it sends so a reader can see which of
 * their own words nobody else heard.
 */
export function MeetingTranscript({ code, title, onClose }: {
  code: string
  title?: string | null
  onClose: () => void
}) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['meeting-transcript', code],
    queryFn: () => meetingsApi.transcript(code),
  })

  const lines = data?.data ?? []

  return (
    <Modal title={`Chat — ${title || data?.meeting.title || code}`} onClose={onClose} wide>
      {isLoading ? (
        <div className="flex justify-center py-12"><Spinner /></div>
      ) : error ? (
        <EmptyState
          title="Not yours to read"
          hint="Only somebody who was in the meeting can read what was said in it."
        />
      ) : lines.length === 0 ? (
        <EmptyState
          title="Nothing was said"
          hint="Nobody typed anything in this one."
        />
      ) : (
        <ul className="space-y-2">
          {lines.map((line) => (
            <li
              key={line.uuid}
              className={clsx(
                'rounded-xl px-3 py-2',
                line.is_mine ? 'bg-brand-50 dark:bg-brand-950/40' : 'bg-slate-50 dark:bg-slate-800/60',
              )}
            >
              <div className="flex flex-wrap items-baseline gap-x-2">
                <span className="text-xs font-semibold text-slate-700 dark:text-slate-200">
                  {line.is_mine ? 'You' : line.from}
                </span>
                {line.private && (
                  <span className="flex items-center gap-1 text-[11px] text-amber-600">
                    <Lock className="size-3" />
                    private{line.to && !line.is_mine ? '' : line.to ? ` to ${line.to}` : ''}
                  </span>
                )}
                <span className="text-[11px] text-slate-400">{line.at}</span>
              </div>

              {line.message && (
                <p className="mt-0.5 whitespace-pre-wrap break-words text-sm text-slate-600 dark:text-slate-300">
                  {line.message}
                </p>
              )}

              {/* The file is still where it was shared - the transcript
                  names it rather than holding a second copy. */}
              {line.file && (
                <a
                  href={meetingsApi.chatFileUrl(code, line.file.uuid)}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="mt-1 inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2 py-1 text-[11px] text-slate-600 hover:border-brand-300 hover:text-brand-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
                >
                  <Paperclip className="size-3" />
                  <span className="max-w-[16rem] truncate">{line.file.name}</span>
                  <span className="text-slate-400">{Math.max(1, Math.round(line.file.size / 1024))} KB</span>
                </a>
              )}
            </li>
          ))}
        </ul>
      )}

      {data && lines.length > 0 && (
        <p className="mt-3 flex items-center gap-1.5 border-t border-slate-100 pt-2 text-[11px] text-slate-400 dark:border-slate-800">
          <MessageSquare className="size-3" />
          {lines.length} line{lines.length === 1 ? '' : 's'}
          {data.meeting.ended_at ? ` · ended ${data.meeting.ended_at}` : ' · still running'}
        </p>
      )}
    </Modal>
  )
}
