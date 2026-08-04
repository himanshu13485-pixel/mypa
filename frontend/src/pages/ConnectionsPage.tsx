import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Flag, Search, UserPlus, X } from 'lucide-react'
import { badges as badgesApi, connections as connectionsApi, profile, reportsApi } from '../api/endpoints'
import { REPORT_REASONS } from '../types'
import { errorMessage } from '../api/client'
import { useToast } from '../components/Toast'
import UserSuggest from '../components/UserSuggest'
import { useAuthStore } from '../stores/auth'
import { Badge, Button, Card, EmptyState, ErrorNote, Spinner } from '../components/ui'

export default function ConnectionsPage() {
  const { toastError } = useToast()
  const queryClient = useQueryClient()
  const user = useAuthStore((s) => s.user)
  const [query, setQuery] = useState('')
  const [result, setResult] = useState<Awaited<ReturnType<typeof connectionsApi.search>> | null>(null)
  const [searchError, setSearchError] = useState<string | null>(null)

  const { data: list, isLoading } = useQuery({
    queryKey: ['connections'],
    queryFn: () => connectionsApi.list(),
  })
  const { data: qr } = useQuery({ queryKey: ['my-qr'], queryFn: profile.myQr })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['connections'] })

  // Attending this section clears connection notifications + badge.
  useEffect(() => {
    badgesApi.readKinds(['connection_request', 'connection_accepted']).then(() => {
      queryClient.invalidateQueries({ queryKey: ['notifications-count'] })
      queryClient.invalidateQueries({ queryKey: ['badges'] })
    }).catch(() => undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const reportUser = (identifier: string, name: string) => {
    const reason = prompt(
      `Report ${name} — reason (${REPORT_REASONS.join(', ')}):`,
      'spam',
    )?.trim().toLowerCase()
    if (!reason) return
    if (!REPORT_REASONS.includes(reason as (typeof REPORT_REASONS)[number])) {
      alert('Please use one of: ' + REPORT_REASONS.join(', '))
      return
    }
    const details = prompt('Details (optional):') ?? undefined
    reportsApi.fileUser(identifier, reason, details)
      .then((res) => alert((res as { message?: string }).message ?? 'Reported.'))
      .catch((err) => toastError(errorMessage(err)))
  }

  const search = async () => {
    setSearchError(null)
    setResult(null)
    try {
      setResult(await connectionsApi.search(query))
    } catch (err) {
      setSearchError(errorMessage(err))
    }
  }

  const sendMutation = useMutation({
    mutationFn: (app_id: string) => connectionsApi.send(app_id),
    onSuccess: () => {
      invalidate()
      setResult(null)
      setQuery('')
    },
    onError: (err) => setSearchError(errorMessage(err)),
  })

  const respondMutation = useMutation({
    mutationFn: ({ uuid, action }: { uuid: string; action: 'accept' | 'decline' }) =>
      connectionsApi.respond(uuid, action),
    onSuccess: invalidate,
  })

  const pending = list?.data.filter((c) => c.status === 'pending') ?? []
  const accepted = list?.data.filter((c) => c.status === 'accepted') ?? []

  return (
    <div className="space-y-6">
      <h1 className="text-lg font-semibold">Connections</h1>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <h2 className="mb-2 text-sm font-semibold">My handle</h2>
          <p className="text-2xl font-bold tracking-wide text-brand-600">@{user?.username}</p>
          <p className="mt-1 text-xs text-slate-400">
            Share your username or email ({user?.email ?? 'no email yet'}) so others can find and
            connect with you.
          </p>
          <p className="mt-1 text-[11px] text-slate-300 dark:text-slate-600">Internal ID: {user?.app_id}</p>
          {qr && (
            <p className="mt-2 break-all rounded-lg bg-slate-50 p-2 font-mono text-[11px] text-slate-500 dark:bg-slate-800">
              {qr.payload}
            </p>
          )}
        </Card>

        <Card>
          <h2 className="mb-2 text-sm font-semibold">Find a user</h2>
          <div className="flex gap-2">
            <div className="flex-1">
              <UserSuggest
                placeholder="username or email"
                value={query}
                onChange={setQuery}
                onEnter={search}
              />
            </div>
            <Button onClick={search}>
              <Search className="size-4" />
            </Button>
          </div>
          <div className="mt-3">
            <ErrorNote message={searchError} />
            {result && (
              <div className="flex items-center justify-between rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                <div>
                  <p className="text-sm font-medium">{result.name}</p>
                  <p className="text-xs text-slate-400">@{(result as { username?: string }).username ?? result.app_id}</p>
                </div>
                {result.is_connected ? (
                  <Badge value="accepted" />
                ) : (
                  <Button size="sm" onClick={() => sendMutation.mutate(result.app_id)} disabled={sendMutation.isPending}>
                    <UserPlus className="size-3.5" /> Connect
                  </Button>
                )}
              </div>
            )}
          </div>
        </Card>
      </div>

      {isLoading ? (
        <Spinner />
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <h2 className="mb-2 text-sm font-semibold">Pending requests</h2>
            {pending.length === 0 ? (
              <EmptyState title="No pending requests" />
            ) : (
              <div className="space-y-2">
                {pending.map((c) => (
                  <div key={c.uuid} className="flex items-center justify-between rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <div>
                      <p className="text-sm font-medium">{c.user?.name}</p>
                      <p className="text-xs text-slate-400">
                        {c.user?.app_id} · {c.direction === 'sent' ? 'you sent this request' : 'sent you a request'}
                      </p>
                      {c.message && <p className="mt-0.5 text-xs italic text-slate-500">“{c.message}”</p>}
                    </div>
                    {c.direction === 'received' ? (
                      <div className="flex gap-1">
                        <Button size="sm" onClick={() => respondMutation.mutate({ uuid: c.uuid, action: 'accept' })}>
                          <Check className="size-3.5" />
                        </Button>
                        <Button size="sm" variant="secondary" onClick={() => respondMutation.mutate({ uuid: c.uuid, action: 'decline' })}>
                          <X className="size-3.5" />
                        </Button>
                      </div>
                    ) : (
                      <Badge value="pending" />
                    )}
                  </div>
                ))}
              </div>
            )}
          </Card>

          <Card>
            <h2 className="mb-2 text-sm font-semibold">My connections</h2>
            {accepted.length === 0 ? (
              <EmptyState title="No connections yet" hint="Search an App ID to connect with someone." />
            ) : (
              <div className="space-y-2">
                {accepted.map((c) => (
                  <div key={c.uuid} className="flex items-center justify-between rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <div>
                      <p className="text-sm font-medium">{c.user?.name}</p>
                      <p className="text-xs text-slate-400">{c.user?.app_id}</p>
                    </div>
                    {c.user?.app_id && (
                      <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => { window.location.href = `/messages?start=${c.user!.app_id}` }}
                      >
                        Message
                      </Button>
                    )}
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => {
                        if (confirm(`Remove connection with ${c.user?.name}?`)) {
                          connectionsApi.remove(c.uuid).then(invalidate)
                        }
                      }}
                    >
                      Remove
                    </Button>
                    {c.user?.app_id && (
                      <button
                        className="rounded p-1.5 text-slate-300 hover:text-red-600"
                        title="Report this user"
                        onClick={() => reportUser(c.user!.app_id!, c.user!.name)}
                      >
                        <Flag className="size-3.5" />
                      </button>
                    )}
                  </div>
                ))}
              </div>
            )}
          </Card>
        </div>
      )}
    </div>
  )
}
