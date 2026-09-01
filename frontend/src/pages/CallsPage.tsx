import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useConnectBase } from '../lib/connectBase'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { MessageCircle, Phone, PhoneIncoming, PhoneMissed, PhoneOutgoing, Users, Video } from 'lucide-react'
import { format } from 'date-fns'
import { badges as badgesApi, calls } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { useCalls } from '../components/CallManager'
import { useToast } from '../components/Toast'
import { Badge, Button, Card, EmptyState, LoadError, Pager, SkeletonList } from '../components/ui'

export default function CallsPage() {
  const queryClient = useQueryClient()
  const { activeCall, joinCall, startCall } = useCalls()
  const { toastError } = useToast()
  const navigate = useNavigate()
  const connectBase = useConnectBase()
  const [page, setPage] = useState(1)
  const [joining, setJoining] = useState<string | null>(null)
  const [params, setParams] = useSearchParams()
  const answered = useRef<string | null>(null)

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['calls-history', page],
    queryFn: () => calls.history(page),
    // A call in progress changes from under you — someone joins, it ends.
    refetchInterval: 15_000,
  })

  // Opening the page "attends" missed calls — the sidebar badge clears.
  useEffect(() => {
    badgesApi.markCallsSeen().then(() => queryClient.invalidateQueries({ queryKey: ['badges'] }))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  /**
   * Walk back into a call that is still going. The API has always allowed a
   * late joiner — accepting an ongoing call is the same call as accepting a
   * ringing one — but nothing ever offered it, so a dropped connection or a
   * closed tab meant the call was simply gone for you.
   */
  const rejoin = async (uuid: string, type: 'audio' | 'video', label: string) => {
    setJoining(uuid)
    try {
      // Through CallManager, not the API directly — it owns the media and the
      // peer connections that actually make the call happen.
      await joinCall(uuid, type, label)
      queryClient.invalidateQueries({ queryKey: ['calls-history'] })
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setJoining(null)
    }
  }

  /*
   * Answering from the lock screen.
   *
   * The push notification opens /calls?join=<uuid>. Walking in that way is the
   * same as pressing Join on a call that is still ringing, so it reuses the
   * same path — but only once, and only while the call is genuinely live, so a
   * stale notification tapped ten minutes later does not drag anyone into a
   * call that ended.
   */
  useEffect(() => {
    const wanted = params.get('join')
    if (!wanted || activeCall || answered.current === wanted) return
    const call = data?.data.find((c) => c.uuid === wanted)
    if (!call) return

    answered.current = wanted
    setParams(new URLSearchParams(), { replace: true })
    if (call.is_active) {
      void rejoin(call.uuid, call.type, call.other_user?.name ?? call.group_name ?? 'Call')
    } else {
      toastError('That call has already ended.')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params, data, activeCall])

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold tracking-tight">Calls</h1>

      {isLoading ? (
        <SkeletonList rows={8} />
      ) : isError ? (
        <Card>
          <LoadError what="your calls" message={errorMessage(error)} onRetry={() => refetch()} />
        </Card>
      ) : !data?.data.length ? (
        <Card>
          <EmptyState title="No calls yet" hint="Start an audio or video call from any conversation." />
        </Card>
      ) : (
        <div className="space-y-1.5">
          {data.data.map((call) => {
            const live = !!call.is_active
            const inThisCall = activeCall?.uuid === call.uuid

            return (
              /*
               * Wrapping, with a floor under the name. The row was a rigid
               * line: four fixed-width controls that never shrink and a name
               * column with plain flex-1, so the name was the only thing that
               * could give — and on a phone it gave everything, down to "L…".
               * A basis it can insist on, plus permission for the row to wrap,
               * puts the controls on a second line instead.
               */
              <Card key={call.uuid} className="flex flex-wrap items-center gap-3 p-3">
                <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
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

                <div className="min-w-0 flex-[1_1_11rem]">
                  <p className="flex items-center gap-2 text-sm font-medium">
                    <span className="truncate">
                      {call.group_name ?? call.other_user?.name ?? 'Unknown'}
                    </span>
                    {live && (
                      <span className="flex shrink-0 items-center gap-1 rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                        <span className="relative flex size-1.5">
                          <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-75" />
                          <span className="relative inline-flex size-1.5 rounded-full bg-emerald-600" />
                        </span>
                        Live
                      </span>
                    )}
                  </p>
                  <p className="truncate text-xs text-slate-400">
                    {call.is_outgoing ? 'Outgoing' : 'Incoming'} {call.type} call
                    {call.started_at ? ` · ${format(new Date(call.started_at), 'd MMM, HH:mm')}` : ''}
                    {call.duration_seconds != null
                      ? ` · ${Math.floor(call.duration_seconds / 60)}:${String(call.duration_seconds % 60).padStart(2, '0')}`
                      : ''}
                  </p>
                  {/* Who is actually in it — the list used to say "ongoing"
                      and nothing else, so you could not tell whether anyone
                      was still there. */}
                  {live && !!call.joined_count && (
                    <p className="mt-0.5 flex items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
                      <Users className="size-3" />
                      {call.joined_count} in the call
                      {call.joined_names?.length ? ` · ${call.joined_names.join(', ')}` : ''}
                    </p>
                  )}
                </div>

                {live && !inThisCall && (
                  <Button
                    size="sm"
                    disabled={!!joining || !!activeCall}
                    title={activeCall ? 'You are already in a call' : 'Join this call'}
                    onClick={() => rejoin(call.uuid, call.type, call.group_name ?? call.other_user?.name ?? 'Call')}
                  >
                    {joining === call.uuid ? 'Joining…' : 'Join'}
                  </Button>
                )}
                {inThisCall && <Badge value="active" />}
                {!live && <Badge value={call.status} />}
                {/* Call back, or write instead, without hunting for the
                    conversation first — the log already knows which one it
                    was. Not offered for a call still running: Join above is
                    the thing to press then, and a group has no one person to
                    ring back. */}
                {!live && !call.is_group && call.conversation_uuid && (
                  <div className="flex shrink-0 items-center gap-1.5">
                    <Button
                      size="sm"
                      variant="secondary"
                      title={`Audio call ${call.other_user?.name ?? ''}`.trim()}
                      disabled={!!activeCall}
                      onClick={() => startCall(call.conversation_uuid, 'audio', call.other_user?.name ?? 'Call')}
                    >
                      <Phone className="size-3.5" />
                    </Button>
                    <Button
                      size="sm"
                      variant="secondary"
                      title={`Video call ${call.other_user?.name ?? ''}`.trim()}
                      disabled={!!activeCall}
                      onClick={() => startCall(call.conversation_uuid, 'video', call.other_user?.name ?? 'Call')}
                    >
                      <Video className="size-3.5" />
                    </Button>
                    <Button
                      size="sm"
                      variant="secondary"
                      title="Open the conversation"
                      onClick={() => navigate(`${connectBase}/messages?conversation=${call.conversation_uuid}`)}
                    >
                      <MessageCircle className="size-3.5" />
                    </Button>
                  </div>
                )}
              </Card>
            )
          })}
          <Pager resp={data} onPage={setPage} />
        </div>
      )}
    </div>
  )
}
