import { useEffect } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { PhoneIncoming, PhoneMissed, PhoneOutgoing, Video } from 'lucide-react'
import { format } from 'date-fns'
import { badges as badgesApi, calls } from '../api/endpoints'
import { Badge, Card, EmptyState, Spinner } from '../components/ui'

export default function CallsPage() {
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['calls-history'], queryFn: calls.history })

  // Opening the page "attends" missed calls — the sidebar badge clears.
  useEffect(() => {
    badgesApi.markCallsSeen().then(() => queryClient.invalidateQueries({ queryKey: ['badges'] }))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <div className="space-y-4">
      <h1 className="text-lg font-semibold">Calls</h1>

      {isLoading ? (
        <Spinner />
      ) : !data?.data.length ? (
        <Card>
          <EmptyState title="No calls yet" hint="Start an audio or video call from any conversation." />
        </Card>
      ) : (
        <div className="space-y-1.5">
          {data.data.map((call) => (
            <Card key={call.uuid} className="flex items-center gap-3 p-3">
              <div className="flex size-9 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                {call.is_missed ? (
                  <PhoneMissed className="size-4 text-red-500" />
                ) : call.type === 'video' ? (
                  <Video className="size-4 text-brand-500" />
                ) : call.is_outgoing ? (
                  <PhoneOutgoing className="size-4 text-emerald-500" />
                ) : (
                  <PhoneIncoming className="size-4 text-brand-500" />
                )}
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">{call.other_user?.name ?? 'Unknown'}</p>
                <p className="text-xs text-slate-400">
                  {call.is_outgoing ? 'Outgoing' : 'Incoming'} {call.type} call
                  {call.started_at ? ` · ${format(new Date(call.started_at), 'd MMM, HH:mm')}` : ''}
                  {call.duration_seconds != null
                    ? ` · ${Math.floor(call.duration_seconds / 60)}:${String(call.duration_seconds % 60).padStart(2, '0')}`
                    : ''}
                </p>
              </div>
              <Badge value={call.status} />
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}
