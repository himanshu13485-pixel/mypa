import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useConnectBase } from '../lib/connectBase'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Flag, MessageSquare, Phone, Search, Undo2, UserPlus, Video, X } from 'lucide-react'
import { badges as badgesApi, chat, connections as connectionsApi, profile, reportsApi } from '../api/endpoints'
import { useCalls } from '../components/CallManager'
import { REPORT_REASONS } from '../types'
import { errorMessage } from '../api/client'
import { useToast } from '../components/Toast'
import UserSuggest from '../components/UserSuggest'
import { useAuthStore } from '../stores/auth'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorNote,
  Label,
  Modal,
  Select,
  SkeletonList,
  Textarea,
} from '../components/ui'
import { Avatar } from '../lib/avatars'

export default function ConnectionsPage() {
  const navigate = useNavigate()
  const connectBase = useConnectBase()
  const { toast, toastError } = useToast()
  const queryClient = useQueryClient()
  const { startCall } = useCalls()
  const user = useAuthStore((s) => s.user)
  const [query, setQuery] = useState('')
  const [result, setResult] = useState<Awaited<ReturnType<typeof connectionsApi.search>> | null>(null)
  const [searchError, setSearchError] = useState<string | null>(null)
  /** Narrows the connections you already have, not a search for new ones. */
  const [filter, setFilter] = useState('')
  /** App ID of whoever we are opening a conversation for, to disable its buttons. */
  const [calling, setCalling] = useState<string | null>(null)
  const [reporting, setReporting] = useState<{ identifier: string; name: string; reason: string; details: string } | null>(null)

  /*
   * The filter is a question for the server.
   *
   * The list arrives twenty at a time, so filtering it here could only ever
   * search the twenty on screen — which is why looking for a colleague by
   * name came back empty the moment somebody had more than a page of
   * connections. Debounced, because it is a request per keystroke otherwise.
   */
  const [searchTerm, setSearchTerm] = useState('')
  useEffect(() => {
    const id = setTimeout(() => setSearchTerm(filter.trim()), 300)
    return () => clearTimeout(id)
  }, [filter])

  const { data: list, isLoading } = useQuery({
    queryKey: ['connections', searchTerm],
    queryFn: () => connectionsApi.list(undefined, searchTerm || undefined),
    /*
     * Presence goes stale on its own — somebody closing their laptop sends
     * nothing — so the dots need re-asking rather than invalidating. A minute
     * against the server's two-minute window means a dot is wrong for well
     * under a minute, and only while this page is open: refetchInterval stops
     * when the tab is in the background.
     */
    refetchInterval: 60_000,
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

  /*
   * Reporting somebody used to be two window.prompts in a row: pick a reason
   * by typing one of six words exactly, then add details. Getting the word
   * wrong threw the whole thing away, and in browsers that ignore prompt()
   * the first one returned null and the report simply never happened.
   */
  const reportUser = (identifier: string, name: string) => {
    setReporting({ identifier, name, reason: 'spam', details: '' })
  }

  const sendReport = () => {
    if (!reporting) return
    const { identifier, reason, details } = reporting
    setReporting(null)
    reportsApi.fileUser(identifier, reason, details.trim() || undefined)
      .then((res) => toast((res as { message?: string }).message ?? 'Reported. Thank you.'))
      .catch((err) => toastError(errorMessage(err)))
  }

  /*
   * Who is ticked, by uuid.
   *
   * Not by App ID: that is what gets sent, but it is null for an account
   * whose App ID has not been issued yet, and a set keyed on null holds one
   * entry for everybody. The uuid is the row's own identity.
   */
  const [chosen, setChosen] = useState<Set<string>>(new Set())

  const toggleChosen = (uuid: string) => setChosen((prev) => {
    const next = new Set(prev)
    if (!next.delete(uuid)) next.add(uuid)
    return next
  })

  /** The handle the server can resolve — App ID when there is one, else the username. */
  const handleOf = (p: { app_id: string | null; username?: string | null }) => p.app_id ?? p.username ?? ''

  const search = async () => {
    setSearchError(null)
    setResult(null)
    setChosen(new Set())
    try {
      setResult(await connectionsApi.search(query))
    } catch (err) {
      setSearchError(errorMessage(err))
    }
  }

  /*
   * Everyone you ticked, in one go.
   *
   * Finding six colleagues and then sending six requests one at a time is
   * the same search repeated six times. Each request is still its own call —
   * the server takes one person at a time — but one refusal does not sink
   * the others, and whoever failed is named rather than silently skipped.
   */
  const sendMutation = useMutation({
    mutationFn: async (appIds: string[]) => {
      const results = await Promise.allSettled(appIds.map((id) => connectionsApi.send(id)))
      return {
        sent: results.filter((r) => r.status === 'fulfilled').length,
        failed: appIds.filter((_, i) => results[i].status === 'rejected'),
      }
    },
    onSuccess: ({ sent, failed }) => {
      invalidate()
      // Whoever failed stays ticked, so the retry is one click.
      setChosen(new Set(
        (result ?? []).filter((p) => failed.includes(handleOf(p))).map((p) => p.uuid),
      ))
      if (failed.length) {
        setSearchError(`Sent ${sent}. Could not send to ${failed.length} of them.`)
        return
      }
      setSearchError(null)
      setResult(null)
      setQuery('')
    },
    onError: (err) => setSearchError(errorMessage(err)),
  })

  /** Taking back a request you sent. The row is the same one either side. */
  const retractMutation = useMutation({
    mutationFn: (uuid: string) => connectionsApi.remove(uuid),
    onSuccess: invalidate,
    onError: (err) => toastError(errorMessage(err)),
  })

  const respondMutation = useMutation({
    mutationFn: ({ uuid, action }: { uuid: string; action: 'accept' | 'decline' }) =>
      connectionsApi.respond(uuid, action),
    onSuccess: invalidate,
  })

  const pending = list?.data.filter((c) => c.status === 'pending') ?? []
  const accepted = list?.data.filter((c) => c.status === 'accepted') ?? []

  // Filtering the people you already know, which is a different question from
  // the App ID search above — that one goes looking for strangers.
  // The server searched names, usernames and App IDs across the whole list,
  // so what came back is already the answer.
  const shown = accepted

  /**
   * Ring somebody straight from the list.
   *
   * A call needs a conversation, and the list only knows App IDs — so open (or
   * reuse) the direct conversation first, exactly as the Message button does,
   * and start the call in the one that comes back.
   */
  const callConnection = async (appId: string, name: string, type: 'audio' | 'video') => {
    setCalling(appId)
    try {
      const conversation = await chat.start(appId)
      await startCall(conversation.uuid, type, name)
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setCalling(null)
    }
  }

  return (
    <div className="space-y-6">
      <h1 className="text-xl font-semibold tracking-tight">Connections</h1>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card className="min-w-0">
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

        <Card className="min-w-0">
          <h2 className="mb-2 text-sm font-semibold">Find a user</h2>
          <div className="flex gap-2">
            <div className="flex-1">
              <UserSuggest
                placeholder="part of a username, or an App ID / email"
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
            {/* However many people match — a fragment of a username finds
                everybody who has it, and the reader picks their colleague. */}
            {result?.map((person) => (
              <div key={person.uuid} className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                <div className="flex min-w-0 items-center gap-3">
                  {/* Ticking is for the people you can actually ask; someone
                      you already know has nothing to send. */}
                  {!person.is_connected && (
                    <input
                      type="checkbox"
                      checked={chosen.has(person.uuid)}
                      onChange={() => toggleChosen(person.uuid)}
                      aria-label={`Select ${person.name}`}
                      className="size-4 shrink-0 accent-brand-600"
                    />
                  )}
                  <Avatar name={person.name} photoPath={person.photo_path} avatar={person.avatar} size={38} />
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium">{person.name}</p>
                    <p className="truncate text-xs text-slate-400">@{person.username ?? person.app_id}</p>
                  </div>
                </div>
                {person.is_connected ? (
                  <Badge value="accepted" />
                ) : (
                  <Button size="sm" onClick={() => sendMutation.mutate([handleOf(person)])} disabled={sendMutation.isPending}>
                    <UserPlus className="size-3.5" /> Connect
                  </Button>
                )}
              </div>
            ))}

            {/* One request per person is still a click each; this is the row
                for when you meant all of them. */}
            {chosen.size > 0 && (
              <div className="mt-2 flex items-center justify-between gap-3 rounded-lg bg-brand-50 px-3 py-2 dark:bg-brand-500/10">
                <span className="text-xs font-medium">{chosen.size} selected</span>
                <div className="flex gap-2">
                  <Button size="sm" variant="secondary" onClick={() => setChosen(new Set())}>
                    Clear
                  </Button>
                  <Button
                    size="sm"
                    disabled={sendMutation.isPending}
                    onClick={() => sendMutation.mutate(
                      (result ?? []).filter((p) => chosen.has(p.uuid)).map(handleOf).filter(Boolean),
                    )}
                  >
                    <UserPlus className="size-3.5" />
                    {sendMutation.isPending ? 'Sending…' : `Connect with ${chosen.size}`}
                  </Button>
                </div>
              </div>
            )}
          </div>
        </Card>
      </div>

      {isLoading ? (
        <SkeletonList rows={6} />
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Card className="min-w-0">
            <h2 className="mb-2 text-sm font-semibold">Pending requests</h2>
            {pending.length === 0 ? (
              <EmptyState title="No pending requests" />
            ) : (
              <div className="space-y-2">
                {pending.map((c) => (
                  <div key={c.uuid} className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <div className="flex min-w-0 items-center gap-3">
                      <Avatar name={c.user?.name} photoPath={c.user?.photo_path} avatar={c.user?.avatar} size={38} />
                      <div className="min-w-0">
                      <p className="truncate text-sm font-medium">{c.user?.name}</p>
                      <p className="truncate text-xs text-slate-400">
                        {c.user?.username ? `@${c.user.username} · ` : ''}
                        {c.user?.app_id} · {c.direction === 'sent' ? 'you sent this request' : 'sent you a request'}
                      </p>
                      {c.message && <p className="mt-0.5 text-xs italic text-slate-500">“{c.message}”</p>}
                      </div>
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
                      /*
                       * A sent request could be waited on, but not taken back
                       * — so a request to the wrong person sat in a stranger's
                       * list until they answered it.
                       */
                      <div className="flex items-center gap-2">
                        <Badge value="pending" />
                        <Button
                          size="sm"
                          variant="secondary"
                          title={`Withdraw the request to ${c.user?.name ?? 'this person'}`}
                          disabled={retractMutation.isPending}
                          onClick={() => {
                            if (confirm(`Withdraw your connection request to ${c.user?.name ?? 'this person'}?`)) {
                              retractMutation.mutate(c.uuid)
                            }
                          }}
                        >
                          <Undo2 className="size-3.5" /> Withdraw
                        </Button>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </Card>

          <Card className="min-w-0">
            <h2 className="mb-2 text-sm font-semibold">My connections</h2>
            {accepted.length > 0 && (
              <div className="relative mb-3">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input
                  value={filter}
                  onChange={(e) => setFilter(e.target.value)}
                  placeholder="Search your connections by name or App ID"
                  className="w-full rounded-lg border border-slate-200 bg-white py-2 pl-9 pr-8 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-slate-900"
                />
                {filter && (
                  <button
                    className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 hover:text-slate-600"
                    title="Clear"
                    onClick={() => setFilter('')}
                  >
                    <X className="size-3.5" />
                  </button>
                )}
              </div>
            )}
            {accepted.length === 0 ? (
              <EmptyState title="No connections yet" hint="Search an App ID to connect with someone." />
            ) : shown.length === 0 ? (
              <EmptyState title="Nobody by that name" hint="Clear the search to see everyone you are connected to." />
            ) : (
              <div className="space-y-2">
                {shown.map((c) => (
                  <div key={c.uuid} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    {/* flex-basis 11rem, not plain flex-1. The row wraps, but
                        wrapping only happens once something refuses to shrink —
                        and flex-1 shrinks to nothing first, so on a phone the
                        buttons stayed on one line and the name was the thing
                        that gave, squeezed to two letters beside the avatar.
                        A basis the name can insist on flips the outcome: the
                        name keeps its line and the buttons wrap below it. */}
                    <div className="flex min-w-0 flex-[1_1_11rem] items-center gap-3">
                      {/* The dot rides on the avatar rather than sitting beside
                          the name, so it stays put however the row wraps. */}
                      <div className="relative shrink-0">
                        <Avatar name={c.user?.name} photoPath={c.user?.photo_path} avatar={c.user?.avatar} size={38} />
                        {c.user?.is_online && (
                          <span
                            title="Online now"
                            className="absolute -bottom-0.5 -right-0.5 size-3 rounded-full border-2 border-white bg-emerald-500 dark:border-slate-900"
                          />
                        )}
                      </div>
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium">{c.user?.name}</p>
                        {/*
                          * Who they are, then where they are.
                          *
                          * The username is how people refer to each other —
                          * it is what you type to find them and what they put
                          * on a card — and this row showed only the App ID,
                          * so the one identifier everybody knows was the one
                          * missing. Online moves to the end rather than
                          * replacing it: being online is a passing state, and
                          * it was displacing the name of the person.
                          */}
                        <p className="truncate text-xs text-slate-400">
                          {c.user?.username && <span>@{c.user.username}</span>}
                          {c.user?.username && c.user?.app_id && ' · '}
                          {c.user?.app_id}
                          {c.user?.is_online && (
                            <span className="ml-1 font-medium text-emerald-600 dark:text-emerald-400">· Online</span>
                          )}
                        </p>
                      </div>
                    </div>
                    {c.user?.app_id && (
                      <div className="flex items-center gap-1.5">
                        <Button
                          size="sm"
                          variant="secondary"
                          title={`Audio call ${c.user.name}`}
                          disabled={calling === c.user.app_id}
                          onClick={() => callConnection(c.user!.app_id!, c.user!.name, 'audio')}
                        >
                          <Phone className="size-3.5" />
                        </Button>
                        <Button
                          size="sm"
                          variant="secondary"
                          title={`Video call ${c.user.name}`}
                          disabled={calling === c.user.app_id}
                          onClick={() => callConnection(c.user!.app_id!, c.user!.name, 'video')}
                        >
                          <Video className="size-3.5" />
                        </Button>
                        {/* An icon like the two beside it. As the only word in
                            the row it set the button's height and pushed the
                            call icons out of line, and it was also the only one
                            with no title — so on a phone, where nothing hovers,
                            it was the least labelled of the three despite being
                            the only one that said anything. */}
                        <Button
                          size="sm"
                          variant="secondary"
                          title={`Message ${c.user.name}`}
                          onClick={() => navigate(`${connectBase}/messages?start=${c.user!.app_id}`)}
                        >
                          <MessageSquare className="size-3.5" />
                        </Button>
                      </div>
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

      {reporting && (
        <Modal title={`Report ${reporting.name}`} onClose={() => setReporting(null)}>
          <div className="space-y-4">
            <div>
              <Label>Reason</Label>
              <Select
                value={reporting.reason}
                onChange={(e) => setReporting({ ...reporting, reason: e.target.value })}
              >
                {REPORT_REASONS.map((r) => (
                  <option key={r} value={r}>{r.charAt(0).toUpperCase() + r.slice(1)}</option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Anything else? (optional)</Label>
              <Textarea
                rows={3}
                value={reporting.details}
                onChange={(e) => setReporting({ ...reporting, details: e.target.value })}
                placeholder="Anything that helps us understand."
              />
            </div>
            <div className="flex justify-end gap-2">
              <Button variant="ghost" onClick={() => setReporting(null)}>Cancel</Button>
              <Button variant="danger" onClick={sendReport}>Send report</Button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  )
}
