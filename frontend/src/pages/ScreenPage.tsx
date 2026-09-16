import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Copy, Eye, KeyRound, MessageSquare, MonitorUp } from 'lucide-react'
import { format, formatDistanceToNow } from 'date-fns'
import { meetings as meetingsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { Badge, Button, Card, EmptyState, Input, SkeletonList } from '../components/ui'
import { screenShareSupported } from '../lib/devices'
import { MeetingTranscript } from '../components/MeetingTranscript'
import type { MeetingItem } from '../types'
import { livePath } from '../lib/crmPath'

export function screenLink(code: string): string {
  return `${window.location.origin}/screen/session/${code}`
}

/**
 * A password worth reading down the phone: no l/1, no O/0.
 *
 * Six characters is not a secret to be guarded for years - it guards one
 * screen, for as long as that screen is up, against somebody who would have
 * to know the link as well.
 */
function suggestWord(): string {
  const alphabet = 'abcdefghjkmnpqrstuvwxyz23456789'

  return Array.from({ length: 6 }, () => alphabet[Math.floor(Math.random() * alphabet.length)]).join('')
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
  /* Whose conversation is being read - during the share or long after. */
  const [chatFor, setChatFor] = useState<MeetingItem | null>(null)
  /*
   * The password that opens a share to people with no Netvork account.
   *
   * There is no separate "public link" to generate: the link is always the
   * same one, and this is what decides whether a stranger holding it gets in
   * or is turned towards a sign-in page. Empty means members only.
   */
  const [guestWord, setGuestWord] = useState('')
  /** Which running session is having its password changed, if any. */
  const [guestFor, setGuestFor] = useState<string | null>(null)
  const [guestEdit, setGuestEdit] = useState('')

  const { data: sessions, isLoading } = useQuery({
    queryKey: ['screen-sessions'],
    queryFn: meetingsApi.listScreens,
    refetchInterval: 30_000,
  })

  const shareMutation = useMutation({
    mutationFn: () => meetingsApi.create({
      is_screen: true,
      type: 'video',
      title: 'Screen share',
      // Only once it is long enough to be accepted; a half-typed word that
      // was thought better of must not fail the whole share.
      ...(guestWord.length >= 4 ? { passcode: guestWord } : {}),
    }),
    onSuccess: (m) => {
      queryClient.invalidateQueries({ queryKey: ['screen-sessions'] })
      navigate(livePath('screen', m.code))
    },
    onError: (err) => alert(errorMessage(err)),
  })

  /*
   * Opening - or closing - an existing share to guests.
   *
   * A share is usually started before anybody says "I cannot get in", so the
   * decision has to be changeable while the screen is up rather than only in
   * the moment it began.
   */
  const guestMutation = useMutation({
    mutationFn: ({ code, passcode }: { code: string; passcode: string | null }) =>
      meetingsApi.setPasscode(code, passcode),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['screen-sessions'] })
      setGuestFor(null)
      setGuestEdit('')
    },
    onError: (err) => alert(errorMessage(err)),
  })

  const view = () => {
    const raw = viewCode.trim().toLowerCase()
    const fromLink = raw.match(/[a-z]{3}-[a-z]{4}-[a-z]{3}/)
    const code = fromLink ? fromLink[0] : raw.replace(/[^a-z-]/g, '')
    if (code) navigate(livePath('screen', code))
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
            <>
              {/* Not a setting buried in a menu: whether the link works for
                  people outside the company is decided here, where the link
                  is made. */}
              <div className="w-full space-y-1">
                <span className="flex items-center gap-1 text-[11px] font-medium text-slate-500 dark:text-slate-400">
                  <KeyRound className="size-3" /> Guest password (optional)
                </span>
                <div className="flex w-full gap-1">
                  <Input
                    placeholder="leave empty for members only"
                    value={guestWord}
                    maxLength={12}
                    onChange={(e) => setGuestWord(e.target.value.replace(/[^a-zA-Z0-9]/g, ''))}
                  />
                  <Button size="sm" variant="secondary" onClick={() => setGuestWord(suggestWord())}>
                    Suggest
                  </Button>
                </div>
                <p className="text-[11px] leading-snug text-slate-400">
                  Set one and anyone with the link can watch without a Netvork account — they type
                  their name and this password. 4–12 letters or numbers.
                </p>
              </div>
              <Button
                size="sm"
                onClick={() => shareMutation.mutate()}
                disabled={shareMutation.isPending || (guestWord.length > 0 && guestWord.length < 4)}
              >
                <MonitorUp className="size-3.5" /> {shareMutation.isPending ? 'Starting…' : 'Start sharing'}
              </Button>
            </>
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
        <SkeletonList rows={4} avatar={false} />
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
                {/* Who the link lets in, said on the row that holds the link.
                    The host's own sessions only, and only while they are
                    running - there is nothing to let anybody into afterwards. */}
                {s.is_host && s.status !== 'ended' && (
                  guestFor === s.code ? (
                    <div className="mt-1 flex flex-wrap items-center gap-1">
                      <Input
                        className="w-40"
                        autoFocus
                        placeholder="4–12 letters/numbers"
                        value={guestEdit}
                        maxLength={12}
                        onChange={(e) => setGuestEdit(e.target.value.replace(/[^a-zA-Z0-9]/g, ''))}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter' && guestEdit.length >= 4) {
                            guestMutation.mutate({ code: s.code, passcode: guestEdit })
                          }
                          if (e.key === 'Escape') setGuestFor(null)
                        }}
                      />
                      <Button
                        size="sm"
                        disabled={guestEdit.length < 4 || guestMutation.isPending}
                        onClick={() => guestMutation.mutate({ code: s.code, passcode: guestEdit })}
                      >
                        Save
                      </Button>
                      <Button size="sm" variant="secondary" onClick={() => setGuestFor(null)}>
                        Cancel
                      </Button>
                    </div>
                  ) : (
                    <p className="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                      {s.allows_guests ? (
                        <>
                          <span className="text-emerald-600">
                            Guests can watch · password <span className="font-mono">{s.passcode}</span>
                          </span>
                          <button
                            className="text-slate-400 underline underline-offset-2 hover:text-slate-600"
                            onClick={() => { setGuestFor(s.code); setGuestEdit(s.passcode ?? suggestWord()) }}
                          >
                            change
                          </button>
                          <button
                            className="text-slate-400 underline underline-offset-2 hover:text-slate-600"
                            onClick={() => guestMutation.mutate({ code: s.code, passcode: null })}
                          >
                            members only
                          </button>
                        </>
                      ) : (
                        <>
                          <span className="text-slate-400">Signed-in members only</span>
                          <button
                            className="text-brand-600 underline underline-offset-2"
                            onClick={() => { setGuestFor(s.code); setGuestEdit(suggestWord()) }}
                          >
                            let guests watch
                          </button>
                        </>
                      )}
                    </p>
                  )
                )}
              </div>
              <div className="flex gap-1.5">
                {/* What was typed while the screen was up. It used to go
                    when the session did. */}
                <Button size="sm" variant="secondary" title="Read the chat" onClick={() => setChatFor(s)}>
                  <MessageSquare className="size-3.5" /> Chat
                </Button>
                <Button size="sm" variant="secondary" onClick={() => copyLink(s.code)}>
                  <Copy className="size-3.5" /> {copiedCode === s.code ? 'Copied ✓' : 'Link'}
                </Button>
                {s.status !== 'ended' && (
                  <Button size="sm" onClick={() => navigate(livePath('screen', s.code))}>
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

      {chatFor && (
        <MeetingTranscript
          code={chatFor.code}
          title={chatFor.title}
          onClose={() => setChatFor(null)}
        />
      )}
    </div>
  )
}
