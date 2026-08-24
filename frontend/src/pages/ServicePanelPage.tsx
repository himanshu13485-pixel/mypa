import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, Eye, KeyRound, LogOut, Plug, Trash2 } from 'lucide-react'
import { service as serviceApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { disconnectEcho } from '../lib/echo'
import {
  Badge, Button, Card, EmptyState, ErrorNote, Input, Label, LoadError, Spinner,
} from '../components/ui'

/**
 * What an application sees instead of the app.
 *
 * A service account has no use for notes, habits or meetings, and showing it
 * those would invite treating it as a person's account — someone would
 * eventually put real work in one. It gets the three things that are actually
 * its own: who may hear from it, what may sign in as it, and whether anything
 * is going out.
 *
 * Deliberately one page. There is not enough here to navigate, and a sidebar
 * would be four links to nothing.
 */
export default function ServicePanelPage() {
  const queryClient = useQueryClient()
  const clearAuth = useAuthStore((s) => s.clear)
  const [error, setError] = useState<string | null>(null)
  const [tokenName, setTokenName] = useState('')
  /** The token just issued, shown large. Older ones are read back on request. */
  const [freshToken, setFreshToken] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)

  const overview = useQuery({ queryKey: ['service-overview'], queryFn: serviceApi.overview })
  const tokens = useQuery({ queryKey: ['service-tokens'], queryFn: serviceApi.tokens })
  const connections = useQuery({ queryKey: ['service-connections'], queryFn: serviceApi.connections })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['service-overview'] })
    queryClient.invalidateQueries({ queryKey: ['service-tokens'] })
    queryClient.invalidateQueries({ queryKey: ['service-connections'] })
  }

  const issue = useMutation({
    mutationFn: () => serviceApi.issueToken(tokenName.trim()),
    onSuccess: (data) => {
      setFreshToken(data.token)
      setTokenName('')
      setCopied(false)
      refresh()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  /** Tokens read back on request, keyed by id — never all at once. */
  const [revealed, setRevealed] = useState<Record<number, string>>({})

  const reveal = useMutation({
    mutationFn: (id: number) => serviceApi.revealToken(id).then((token) => ({ id, token })),
    onSuccess: ({ id, token }) => setRevealed((r) => ({ ...r, [id]: token })),
    onError: (err) => setError(errorMessage(err)),
  })

  const revoke = useMutation({
    mutationFn: (id: number) => serviceApi.revokeToken(id),
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err)),
  })

  const disconnect = useMutation({
    mutationFn: (uuid: string) => serviceApi.disconnect(uuid),
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err)),
  })

  const signOut = () => {
    disconnectEcho()
    clearAuth()
  }

  if (overview.isError) {
    return <LoadError onRetry={() => overview.refetch()} />
  }

  const me = overview.data

  return (
    <div className="mx-auto max-w-3xl space-y-5 p-4 sm:p-8">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-xl font-semibold">{me?.name ?? 'Service account'}</h1>
            <Badge value="application" />
          </div>
          <p className="mt-0.5 text-sm text-slate-500">
            An account an application signs in as. It has no inbox to read and nobody tending it.
          </p>
        </div>
        <Button size="sm" variant="secondary" onClick={signOut}>
          <LogOut className="size-3.5" /> Sign out
        </Button>
      </header>

      <ErrorNote message={error} />

      {/* --- Identity + numbers ------------------------------------------- */}
      <Card>
        {overview.isLoading ? (
          <Spinner />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <Label>People connect to</Label>
              <p className="font-mono text-sm">{me?.username ?? '—'}</p>
              <p className="mt-0.5 text-xs text-slate-400">
                App ID {me?.app_id ?? '—'}. Either one reaches this account.
              </p>
            </div>
            <div className="grid grid-cols-3 gap-3 text-center">
              <Stat label="Connected" value={me?.connections ?? 0} />
              <Stat label="Tokens" value={me?.tokens ?? 0} />
              <Stat label="Sent" value={me?.messages_sent ?? 0} />
            </div>
            <p className="text-xs text-slate-400 sm:col-span-2">
              {/* The number that separates "quiet" from "broken" — the two look
                  identical from outside, and only one needs fixing. */}
              {me?.last_sent_at
                ? `Last message sent ${new Date(me.last_sent_at).toLocaleString()}.`
                : 'Nothing has been sent yet.'}
            </p>
          </div>
        )}
      </Card>

      {/* --- Tokens -------------------------------------------------------- */}
      <Card>
        <h2 className="flex items-center gap-2 text-sm font-semibold">
          <KeyRound className="size-4" /> Tokens
        </h2>
        <p className="mt-0.5 text-xs text-slate-400">
          What signs in as this account. A token is the whole credential — anything holding one can
          send as this account, so give each caller its own and revoke the one you retire.
        </p>

        {freshToken && (
          <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/40">
            <p className="text-xs font-medium text-amber-900 dark:text-amber-200">
              Your new token. It can be read again from the list below, so losing this screen is not fatal.
            </p>
            <div className="mt-2 flex items-center gap-2">
              <code className="min-w-0 flex-1 truncate rounded bg-white px-2 py-1.5 font-mono text-xs dark:bg-slate-900">
                {freshToken}
              </code>
              <Button
                size="sm"
                variant="secondary"
                onClick={() => {
                  navigator.clipboard.writeText(freshToken).then(
                    () => setCopied(true),
                    () => setError('Your browser would not copy it — select the token and copy it by hand.'),
                  )
                }}
              >
                <Copy className="size-3.5" /> {copied ? 'Copied ✓' : 'Copy'}
              </Button>
            </div>
            <button
              type="button"
              className="mt-2 text-xs text-amber-800 underline dark:text-amber-300"
              onClick={() => setFreshToken(null)}
            >
              I have saved it
            </button>
          </div>
        )}

        <form
          className="mt-3 flex flex-wrap items-end gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            setError(null)
            issue.mutate()
          }}
        >
          <div className="min-w-48 flex-1">
            <Label>New token</Label>
            <Input
              value={tokenName}
              placeholder="What will hold it — “grapme alerts”"
              onChange={(e) => setTokenName(e.target.value)}
            />
          </div>
          <Button type="submit" disabled={!tokenName.trim() || issue.isPending}>
            {issue.isPending ? 'Issuing…' : 'Issue token'}
          </Button>
        </form>

        <div className="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
          {tokens.isLoading && <Spinner />}
          {tokens.data?.map((token) => (
            <div key={token.id} className="flex items-center gap-2 py-2 text-sm">
              <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">
                  {token.name}
                  {token.current && <span className="ml-1 text-xs text-slate-400">— signed in with this</span>}
                </span>
                <span className="block text-xs text-slate-400">
                  {token.last_used_at
                    ? `Last used ${new Date(token.last_used_at).toLocaleString()}`
                    : 'Never used'}
                </span>
                {revealed[token.id] && (
                  <code className="mt-1 block truncate rounded bg-slate-50 px-2 py-1 font-mono text-[11px] dark:bg-slate-800">
                    {revealed[token.id]}
                  </code>
                )}
              </span>
              {token.revealable && !revealed[token.id] && (
                <Button
                  size="sm"
                  variant="ghost"
                  title="Show this token"
                  disabled={reveal.isPending}
                  onClick={() => {
                    setError(null)
                    reveal.mutate(token.id)
                  }}
                >
                  <Eye className="size-3.5" />
                </Button>
              )}
              <Button
                size="sm"
                variant="ghost"
                title={token.current ? 'Issue another token first' : 'Revoke'}
                disabled={token.current || revoke.isPending}
                onClick={() => {
                  setError(null)
                  revoke.mutate(token.id)
                }}
              >
                <Trash2 className="size-3.5" />
              </Button>
            </div>
          ))}
          {tokens.data?.length === 0 && (
            <EmptyState title="No tokens" hint="Nothing can sign in as this account until you issue one." />
          )}
        </div>
      </Card>

      {/* --- Connections --------------------------------------------------- */}
      <Card>
        <h2 className="flex items-center gap-2 text-sm font-semibold">
          <Plug className="size-4" /> Connected people
        </h2>
        <p className="mt-0.5 text-xs text-slate-400">
          Who this account may message. Requests to it are accepted as they arrive — there is nobody
          here to answer them — but the person still chose to connect, and either side can undo it.
        </p>

        <div className="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
          {connections.isLoading && <Spinner />}
          {connections.data?.map((c) => (
            <div key={c.uuid} className="flex items-center gap-2 py-2 text-sm">
              <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">{c.name ?? 'Unknown'}</span>
                <span className="block text-xs text-slate-400">
                  {c.app_id}
                  {c.connected_at ? ` · since ${new Date(c.connected_at).toLocaleDateString()}` : ''}
                </span>
              </span>
              <Button
                size="sm"
                variant="ghost"
                title="Disconnect — this account can no longer message them"
                disabled={disconnect.isPending}
                onClick={() => {
                  setError(null)
                  disconnect.mutate(c.uuid)
                }}
              >
                <Trash2 className="size-3.5" />
              </Button>
            </div>
          ))}
          {connections.data?.length === 0 && (
            <EmptyState
              title="Nobody yet"
              hint="This account can only message people connected to it. They connect from their own Netvork account, or the application asks on their behalf."
            />
          )}
        </div>
      </Card>
    </div>
  )
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg bg-slate-50 px-2 py-2 dark:bg-slate-800/60">
      <p className="text-lg font-semibold">{value}</p>
      <p className="text-[11px] text-slate-400">{label}</p>
    </div>
  )
}
