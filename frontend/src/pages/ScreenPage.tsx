import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Copy, Eye, MonitorUp } from 'lucide-react'
import { format, formatDistanceToNow } from 'date-fns'
import { meetings as meetingsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { Badge, Button, Card, EmptyState, Input, Spinner } from '../components/ui'
import { screenShareSupported } from '../lib/devices'

export function screenLink(code: string): string {
  return `${window.location.origin}/screen/session/${code}`
}

/**
 * Screen module: UltraViewer-style screen sharing over the meeting engine.
 * Share your screen with a code/link; anyone signed in can watch live.
 */
export default function ScreenPage() {
  const navigate = useNavigate()
  /** Ask the browser, not the window width. */
  const canShareScreen = screenShareSupported()
  const queryClient = useQueryClient()
  const [viewCode, setViewCode] = useState('')
  const [copiedCode, setCopiedCode] = useState<string | null>(null)

  const { data: sessions, isLoading } = useQuery({
    queryKey: ['screen-sessions'],
    queryFn: meetingsApi.listScreens,
    refetchInterval: 30_000,
  })

  const shareMutation = useMutation({
    mutationFn: () => meetingsApi.create({ is_screen: true, type: 'video', title: 'Screen share' }),
    onSuccess: (m) => {
      queryClient.invalidateQueries({ queryKey: ['screen-sessions'] })
      navigate(`/screen/session/${m.code}`)
    },
    onError: (err) => alert(errorMessage(err)),
  })

  const view = () => {
    const raw = viewCode.trim().toLowerCase()
    const fromLink = raw.match(/[a-z]{3}-[a-z]{4}-[a-z]{3}/)
    const code = fromLink ? fromLink[0] : raw.replace(/[^a-z-]/g, '')
    if (code) navigate(`/screen/session/${code}`)
  }

  const copyLink = (code: string) => {
    navigator.clipboard.writeText(screenLink(code)).then(
      () => {
        setCopiedCode(code)
        setTimeout(() => setCopiedCode(null), 2000)
      },
      () => prompt('Copy this link:', screenLink(code)),
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2">
        <MonitorUp className="size-5 text-brand-600" />
        <h1 className="text-xl font-semibold tracking-tight">Screen</h1>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <Card className="flex flex-col items-start gap-2">
          <p className="text-sm font-semibold">Share my screen</p>
          <p className="text-xs text-slate-400">
            Get a code and link instantly — anyone signed in to Netvork who opens it watches your
            screen live. Great for support and demos.
          </p>
          {/* Pressing this on a phone used to create the session and then fail
              on the next screen with a raw JavaScript error, leaving a dead
              session behind. No phone browser can capture its own screen, so
              say that here rather than after the fact. Watching one still
              works, which is why only this half is withheld. */}
          {canShareScreen ? (
            <Button size="sm" onClick={() => shareMutation.mutate()} disabled={shareMutation.isPending}>
              <MonitorUp className="size-3.5" /> {shareMutation.isPending ? 'Starting…' : 'Start sharing'}
            </Button>
          ) : (
            <p className="rounded-lg bg-slate-100 px-2.5 py-1.5 text-[11px] leading-snug text-slate-500 dark:bg-slate-800 dark:text-slate-400">
              Sharing needs a computer — no phone browser can capture its own screen. You can still
              watch someone else's from here.
            </p>
          )}
        </Card>
        <Card className="flex flex-col items-start gap-2">
          <p className="text-sm font-semibold">View someone's screen</p>
          <div className="flex w-full gap-1">
            <Input
              placeholder="code or link"
              value={viewCode}
              onChange={(e) => setViewCode(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && view()}
            />
            <Button size="sm" onClick={view} disabled={!viewCode.trim()}>
              <Eye className="size-3.5" />
            </Button>
          </div>
          <p className="text-xs text-slate-400">Enter the code or paste the link they sent you.</p>
        </Card>
      </div>

      {isLoading ? (
        <Spinner />
      ) : !sessions?.length ? (
        <Card>
          <EmptyState title="No screen sessions yet" hint="Start sharing and send the link, or enter a code to view." />
        </Card>
      ) : (
        <div className="space-y-1.5">
          {sessions.map((s) => (
            <Card key={s.uuid} className="flex flex-wrap items-center gap-3 p-3">
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 text-sm font-medium">
                  {s.is_host ? 'My screen share' : `${s.host.name}'s screen`}
                  <Badge value={s.status} />
                  {s.status === 'active' && !!s.joined_count && (
                    <span className="text-xs font-normal text-emerald-600">{s.joined_count} connected</span>
                  )}
                </p>
                <p className="text-xs text-slate-400">
                  <span className="font-mono">{s.code}</span>
                  {' · '}{formatDistanceToNow(new Date(s.created_at), { addSuffix: true })}
                  {s.status === 'ended' && s.duration_seconds != null &&
                    ` · lasted ${Math.max(1, Math.round(s.duration_seconds / 60))} min`}
                  {s.status === 'ended' && s.started_at && ` · ${format(new Date(s.started_at), 'd MMM, HH:mm')}`}
                </p>
              </div>
              <div className="flex gap-1.5">
                <Button size="sm" variant="secondary" onClick={() => copyLink(s.code)}>
                  <Copy className="size-3.5" /> {copiedCode === s.code ? 'Copied ✓' : 'Link'}
                </Button>
                {s.status !== 'ended' && (
                  <Button size="sm" onClick={() => navigate(`/screen/session/${s.code}`)}>
                    {s.is_host ? 'Resume' : 'View'}
                  </Button>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}

      <Card>
        <p className="text-xs text-slate-400">
          <span className="font-semibold text-slate-500">Note:</span> viewers can watch your screen
          live but cannot control your mouse or keyboard — browsers do not allow remote control of a
          computer. Pair it with an audio call or meeting to talk while you share.
        </p>
      </Card>
    </div>
  )
}
