import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Search, UserPlus, X } from 'lucide-react'
import { connections as connectionsApi, profile } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { Badge, Button, Card, EmptyState, ErrorNote, Input, Spinner } from '../components/ui'

export default function ConnectionsPage() {
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
          <h2 className="mb-2 text-sm font-semibold">My App ID</h2>
          <p className="text-2xl font-bold tracking-wide text-brand-600">{user?.app_id}</p>
          <p className="mt-1 text-xs text-slate-400">
            Share this ID so others can find and connect with you.
          </p>
          {qr && (
            <p className="mt-2 break-all rounded-lg bg-slate-50 p-2 font-mono text-[11px] text-slate-500 dark:bg-slate-800">
              {qr.payload}
            </p>
          )}
        </Card>

        <Card>
          <h2 className="mb-2 text-sm font-semibold">Find a user</h2>
          <div className="flex gap-2">
            <Input
              placeholder="MYPA-100001"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && search()}
            />
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
                  <p className="text-xs text-slate-400">{result.app_id}</p>
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
