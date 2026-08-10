import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Calendar, Copy, KeyRound, LogIn, Trash2, Video } from 'lucide-react'
import { format, formatDistanceToNow } from 'date-fns'
import { meetings as meetingsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { useToast } from '../components/Toast'
import { usePrompt } from '../components/Prompt'
import type { MeetingItem } from '../types'
import {
  Badge, Button, Card, EmptyState, ErrorNote, Input, Label, LoadError, Modal, Select, Spinner,
} from '../components/ui'

/**
 * The one link. Signed-in members open it and walk in; anyone else is asked
 * for their name and the meeting password on the way through, and only if the
 * host set one.
 */
export function meetingLink(code: string): string {
  return `${window.location.origin}/meetings/room/${code}`
}

/**
 * The URL for a meeting that has not been created yet.
 *
 * "New meeting" opens the lobby at this address and the room is made only when
 * somebody joins, so backing out leaves nothing behind. Deliberately not a
 * valid meeting code — those are three dash-separated groups of letters — so
 * it can never collide with a real one.
 *
 * Lives here rather than in the room page because the dependency between the
 * two already runs room -> list (see meetingLink above); the other direction
 * would be a cycle, and would pull the whole room bundle into this page.
 */
export const NEW_MEETING = 'new'

export default function MeetingsPage() {
  const { toast, toastError } = useToast()
  const { ask, confirm } = usePrompt()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [joinCode, setJoinCode] = useState('')
  const [showSchedule, setShowSchedule] = useState(false)

  const { data: meetings, isLoading, isError, error: loadError, refetch } = useQuery({
    queryKey: ['meetings'],
    queryFn: meetingsApi.list,
    refetchInterval: 30_000,
  })

  /**
   * Straight to the lobby, without creating anything.
   *
   * This used to create the meeting and then show you your camera, so anyone
   * who thought better of it left behind a room they had never held — and the
   * list slowly filled with them. The room is made when somebody actually
   * joins; back out of the lobby and there is nothing to tidy up.
   */
  const startInstant = () => navigate(`/meetings/room/${NEW_MEETING}`)

  const join = () => {
    // Accept a bare code OR a full pasted invite link — extract the
    // xxx-xxxx-xxx part from whatever was entered.
    const raw = joinCode.trim().toLowerCase()
    const fromLink = raw.match(/[a-z]{3}-[a-z]{4}-[a-z]{3}/)
    const code = fromLink ? fromLink[0] : raw.replace(/[^a-z-]/g, '')
    if (!code) return
    navigate(`/meetings/room/${code}`)
  }

  const [removing, setRemoving] = useState<string | null>(null)
  const remove = async (m: MeetingItem) => {
    const sure = await confirm({
      title: `Delete ${m.title || 'this meeting'}?`,
      message: m.status === 'ended'
        ? 'The record of who attended goes too, along with anything shared in its chat.'
        : 'The invite link stops working for everyone you sent it to.',
      actionLabel: 'Delete',
      danger: true,
    })
    if (!sure) return

    setRemoving(m.code)
    try {
      const res = await meetingsApi.remove(m.code)
      toast(res.message, 'success')
      queryClient.invalidateQueries({ queryKey: ['meetings'] })
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setRemoving(null)
    }
  }

  /*
   * The password is the guest switch, and it used to live inside the room
   * only — so a host who scheduled a meeting and closed the dialog had to
   * start the meeting to find out whether outsiders could get in, or to let
   * them. It belongs here, next to the link it governs.
   */
  const changePasscode = async (m: MeetingItem) => {
    const next = await ask({
      title: m.has_passcode ? 'Change the meeting password' : 'Add a meeting password',
      message:
        'Anyone without a Netvork account is asked for this on the way in, along with their name, '
        + 'and stays 30 minutes. Signed-in members never need it. 4–12 letters or digits.',
      value: m.passcode ?? '',
      placeholder: 'e.g. open1234',
      actionLabel: 'Save',
    })
    if (next === null) return

    const clean = next.replace(/[^a-zA-Z0-9]/g, '')
    if (clean.length < 4) {
      toastError('A password needs at least 4 letters or digits.')
      return
    }

    try {
      const res = await meetingsApi.setPasscode(m.code, clean)
      toast(res.message, 'success')
      queryClient.invalidateQueries({ queryKey: ['meetings'] })
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  const clearPasscode = async (m: MeetingItem) => {
    const sure = await confirm({
      title: 'Remove the password?',
      message: 'Anyone without a Netvork account will no longer be able to join with the link. '
        + 'People already in the meeting stay.',
      actionLabel: 'Remove it',
      danger: true,
    })
    if (!sure) return

    try {
      const res = await meetingsApi.setPasscode(m.code, null)
      toast(res.message, 'success')
      queryClient.invalidateQueries({ queryKey: ['meetings'] })
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  const [copiedCode, setCopiedCode] = useState<string | null>(null)
  const copyLink = (m: MeetingItem) => {
    /*
     * The link is only half of what a guest needs, and it is the half that
     * gets sent. The password stays out of the clipboard on purpose — the two
     * together in one message let anyone who sees it in — so say it instead.
     */
    const copied = () => {
      setCopiedCode(m.code)
      setTimeout(() => setCopiedCode(null), 2000)
      if (m.has_passcode && m.passcode) {
        toast(`Link copied. Guests also need the password ${m.passcode} — send it separately.`, 'success')
      } else if (!m.has_passcode && (m.can_moderate ?? m.is_host)) {
        toast('Link copied. It works for signed-in Netvork members only — add a password to let guests in.', 'success')
      }
    }

    navigator.clipboard.writeText(meetingLink(m.code)).then(
      copied,
      () => prompt('Copy this link:', meetingLink(m.code)),
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2">
        <Video className="size-5 text-brand-600" />
        <h1 className="text-xl font-semibold tracking-tight">Meetings</h1>
      </div>

      {/* Actions row */}
      <div className="grid gap-3 sm:grid-cols-3">
        <Card className="flex flex-col items-start gap-2">
          <p className="text-sm font-semibold">Start now</p>
          <p className="text-xs text-slate-400">Instant video meeting — share the link after it opens.</p>
          <Button size="sm" onClick={startInstant}>
            <Video className="size-3.5" /> New meeting
          </Button>
        </Card>
        <Card className="flex flex-col items-start gap-2">
          <p className="text-sm font-semibold">Schedule</p>
          <p className="text-xs text-slate-400">Create a titled meeting for later and share its link now.</p>
          <Button size="sm" variant="secondary" onClick={() => setShowSchedule(true)}>
            <Calendar className="size-3.5" /> Schedule meeting
          </Button>
        </Card>
        <Card className="flex flex-col items-start gap-2">
          <p className="text-sm font-semibold">Join with a code</p>
          <div className="flex w-full gap-1">
            <Input
              placeholder="code or invite link"
              value={joinCode}
              onChange={(e) => setJoinCode(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && join()}
            />
            <Button size="sm" onClick={join} disabled={!joinCode.trim()}>
              <LogIn className="size-3.5" />
            </Button>
          </div>
        </Card>
      </div>

      {/* My meetings */}
      {isLoading ? (
        <Spinner />
      ) : isError ? (
        <Card>
          <LoadError what="your meetings" message={errorMessage(loadError)} onRetry={() => refetch()} />
        </Card>
      ) : !meetings?.length ? (
        <Card>
          <EmptyState title="No meetings yet" hint="Start an instant meeting or schedule one and share its link." />
        </Card>
      ) : (
        <div className="space-y-1.5">
          {meetings.map((m) => (
            <Card key={m.uuid} className="flex flex-wrap items-center gap-3 p-3">
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 text-sm font-medium">
                  <span className="truncate">{m.title ?? 'Meeting'}</span>
                  <Badge value={m.status} />
                  {m.status === 'active' && !!m.joined_count && (
                    <span className="text-xs font-normal text-emerald-600">{m.joined_count} inside</span>
                  )}
                </p>
                <p className="text-xs text-slate-400">
                  <span className="font-mono">{m.code}</span>
                  {' · '}host {m.is_host ? 'you' : m.host.name}
                  {m.scheduled_at && ` · ${format(new Date(m.scheduled_at), 'd MMM, HH:mm')}`}
                  {!m.scheduled_at && ` · created ${formatDistanceToNow(new Date(m.created_at), { addSuffix: true })}`}
                </p>
                {m.status === 'ended' && (
                  <p className="text-[11px] text-slate-400">
                    {m.started_at && `Held ${format(new Date(m.started_at), 'd MMM, HH:mm')}`}
                    {m.duration_seconds != null && ` · lasted ${Math.max(1, Math.round(m.duration_seconds / 60))} min`}
                    {!!m.participants?.length && ` · ${m.participants.length} attended: ${m.participants.join(', ')}`}
                  </p>
                )}
                {/* Who this link actually works for, and the one control that
                    changes the answer. Moderators only: nobody else may set it,
                    and nobody else is shown what it is. */}
                {(m.can_moderate ?? m.is_host) && m.status !== 'ended' && (
                  <p className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]">
                    <span className="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                      <KeyRound className="size-3" />
                      {m.has_passcode ? (
                        <>Guests: <span className="select-all font-mono">{m.passcode}</span></>
                      ) : (
                        'Members only'
                      )}
                    </span>
                    <button className="text-brand-600 hover:underline" onClick={() => void changePasscode(m)}>
                      {m.has_passcode ? 'change' : 'add a password'}
                    </button>
                    {m.has_passcode && (
                      <button className="text-slate-400 hover:text-red-600" onClick={() => void clearPasscode(m)}>
                        remove
                      </button>
                    )}
                  </p>
                )}
              </div>
              {/* Their own row on a phone. Beside the title the three of them
                  squeezed it to three wrapped words down the left. */}
              <div className="flex w-full justify-end gap-1.5 sm:w-auto">
                {/* Nothing could be removed before, so a meeting made and then
                    not used sat here for ever. */}
                {m.is_host && m.status !== 'active' && (
                  <Button
                    size="sm"
                    variant="ghost"
                    title="Delete this meeting"
                    disabled={removing === m.code}
                    onClick={() => void remove(m)}
                  >
                    <Trash2 className="size-3.5" />
                  </Button>
                )}
                <Button size="sm" variant="secondary" title="Copy invite link" onClick={() => copyLink(m)}>
                  <Copy className="size-3.5" /> {copiedCode === m.code ? 'Copied ✓' : 'Link'}
                </Button>
                {m.status !== 'ended' && (
                  <Button size="sm" onClick={() => navigate(`/meetings/room/${m.code}`)}>
                    {m.status === 'active' ? 'Join' : 'Start'}
                  </Button>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}

      {showSchedule && (
        <ScheduleModal
          onClose={() => setShowSchedule(false)}
          onCreated={() => queryClient.invalidateQueries({ queryKey: ['meetings'] })}
        />
      )}
    </div>
  )
}

function ScheduleModal({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
  const [form, setForm] = useState({ title: '', type: 'video', scheduled_at: '', requires_approval: true, passcode: '' })
  const [error, setError] = useState<string | null>(null)
  const [created, setCreated] = useState<MeetingItem | null>(null)

  const create = useMutation({
    mutationFn: () =>
      meetingsApi.create({
        title: form.title || null,
        type: form.type,
        scheduled_at: form.scheduled_at || null,
        requires_approval: form.requires_approval,
        passcode: form.passcode.trim() || null,
      }),
    onSuccess: (m) => {
      setCreated(m)
      onCreated()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={created ? 'Meeting ready' : 'Schedule a meeting'} onClose={onClose}>
      {created ? (
        <div className="space-y-3 text-sm">
          <p>
            <span className="font-semibold">{created.title ?? 'Meeting'}</span> is ready. This is the link,
            for everyone:
          </p>
          <div className="flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 font-mono text-xs dark:bg-slate-800">
            <span className="min-w-0 flex-1 truncate">{meetingLink(created.code)}</span>
            <Button
              size="sm"
              variant="secondary"
              onClick={() => navigator.clipboard.writeText(meetingLink(created.code)).catch(() => undefined)}
            >
              <Copy className="size-3.5" />
            </Button>
          </div>
          {created.has_passcode ? (
            <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
              Password <span className="font-mono font-semibold">{created.passcode}</span> — people without a
              Netvork account are asked for this on the way in. Send it separately from the link, or the two
              together let anyone in. Members just click and join.
            </p>
          ) : (
            <p className="text-xs text-slate-400">
              No password, so the link works for signed-in Netvork members only. You can add one later from
              inside the meeting.
            </p>
          )}
          <div className="flex justify-end">
            <Button onClick={onClose}>Done</Button>
          </div>
        </div>
      ) : (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            setError(null)
            create.mutate()
          }}
        >
          <ErrorNote message={error} />
          <div>
            <Label>Title</Label>
            <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="Weekly site review" autoFocus />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label>Type</Label>
              <Select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                <option value="video">Video</option>
                <option value="audio">Audio only</option>
              </Select>
            </div>
            <div>
              <Label>When (optional)</Label>
              <Input type="datetime-local" value={form.scheduled_at} onChange={(e) => setForm({ ...form, scheduled_at: e.target.value })} />
            </div>
          </div>
          {/* The password is the whole guest switch. There is no second
              checkbox: set one and outsiders can join with it, leave it empty
              and they cannot. */}
          <div>
            <Label>Password (optional)</Label>
            <Input
              value={form.passcode}
              onChange={(e) => setForm({ ...form, passcode: e.target.value.replace(/[^a-zA-Z0-9]/g, '') })}
              placeholder="4–12 letters or digits"
              maxLength={12}
              autoComplete="off"
            />
            <p className="mt-1 text-[11px] text-slate-400">
              {form.passcode.trim().length >= 4
                ? 'People without a Netvork account can join with this and stay 30 minutes. Members just use the link. Send the password separately.'
                : 'Leave it empty and the link is for signed-in Netvork members only. Set one to let outsiders in with it.'}
            </p>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.requires_approval}
              onChange={(e) => setForm({ ...form, requires_approval: e.target.checked })}
            />
            Require my approval before anyone joins (waiting room)
          </label>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={create.isPending}>{create.isPending ? 'Creating…' : 'Create & get link'}</Button>
          </div>
        </form>
      )}
    </Modal>
  )
}
