import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Calendar, Copy, LogIn, Video } from 'lucide-react'
import { clsx } from 'clsx'
import { format, formatDistanceToNow } from 'date-fns'
import { meetings as meetingsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { useToast } from '../components/Toast'
import type { MeetingItem } from '../types'
import {
  Badge, Button, Card, EmptyState, ErrorNote, Input, Label, LoadError, Modal, Select, Spinner,
} from '../components/ui'

export function meetingLink(code: string): string {
  return `${window.location.origin}/meetings/room/${code}`
}

export default function MeetingsPage() {
  const { toastError } = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [joinCode, setJoinCode] = useState('')
  const [showSchedule, setShowSchedule] = useState(false)

  const { data: meetings, isLoading, isError, error: loadError, refetch } = useQuery({
    queryKey: ['meetings'],
    queryFn: meetingsApi.list,
    refetchInterval: 30_000,
  })

  const instantMutation = useMutation({
    mutationFn: () => meetingsApi.create({ type: 'video' }),
    onSuccess: (m) => {
      queryClient.invalidateQueries({ queryKey: ['meetings'] })
      navigate(`/meetings/room/${m.code}`)
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const join = () => {
    // Accept a bare code OR a full pasted invite link — extract the
    // xxx-xxxx-xxx part from whatever was entered.
    const raw = joinCode.trim().toLowerCase()
    const fromLink = raw.match(/[a-z]{3}-[a-z]{4}-[a-z]{3}/)
    const code = fromLink ? fromLink[0] : raw.replace(/[^a-z-]/g, '')
    if (!code) return
    navigate(`/meetings/room/${code}`)
  }

  const [copiedCode, setCopiedCode] = useState<string | null>(null)
  const copyLink = (m: MeetingItem) => {
    navigator.clipboard.writeText(meetingLink(m.code)).then(
      () => {
        setCopiedCode(m.code)
        setTimeout(() => setCopiedCode(null), 2000)
      },
      () => prompt('Copy this link:', meetingLink(m.code)),
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2">
        <Video className="size-5 text-brand-600" />
        <h1 className="text-lg font-semibold">Meetings</h1>
      </div>

      {/* Actions row */}
      <div className="grid gap-3 sm:grid-cols-3">
        <Card className="flex flex-col items-start gap-2">
          <p className="text-sm font-semibold">Start now</p>
          <p className="text-xs text-slate-400">Instant video meeting — share the link after it opens.</p>
          <Button size="sm" onClick={() => instantMutation.mutate()} disabled={instantMutation.isPending}>
            <Video className="size-3.5" /> {instantMutation.isPending ? 'Creating…' : 'New meeting'}
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
                  {m.title ?? 'Meeting'}
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
              </div>
              <div className="flex gap-1.5">
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
  const [form, setForm] = useState({ title: '', type: 'video', scheduled_at: '', requires_approval: true, passcode: '', guest_access: false })
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
        // Only meaningful with a passcode — the backend enforces the same.
        guest_access: form.guest_access && form.passcode.trim().length >= 4,
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
            <span className="font-semibold">{created.title ?? 'Meeting'}</span> is ready. Share this link —
            anyone signed in to Netvork can join:
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
          {created.has_passcode && (
            <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
              Passcode <span className="font-mono font-semibold">{created.passcode}</span> — send this
              separately from the link, or it defeats the point.
            </p>
          )}
          {created.guest_access && (
            <div className="space-y-1 rounded-lg bg-slate-100 px-3 py-2 dark:bg-slate-800">
              <p className="text-xs font-medium">Link for people without an account</p>
              <div className="flex gap-2">
                <input
                  readOnly
                  value={`${window.location.origin}/join/${created.code}`}
                  onFocus={(e) => e.currentTarget.select()}
                  className="min-w-0 flex-1 rounded border border-slate-300 bg-white px-2 py-1 font-mono text-[11px] dark:border-slate-600 dark:bg-slate-900"
                />
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => navigator.clipboard
                    .writeText(`${window.location.origin}/join/${created.code}`)
                    .catch(() => undefined)}
                >
                  <Copy className="size-3.5" />
                </Button>
              </div>
              <p className="text-[11px] text-slate-400">They will need the passcode too, and get 30 minutes.</p>
            </div>
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
          <div>
            <Label>Passcode (optional)</Label>
            <Input
              value={form.passcode}
              onChange={(e) => setForm({ ...form, passcode: e.target.value.replace(/[^a-zA-Z0-9]/g, '') })}
              placeholder="4–12 letters or digits"
              maxLength={12}
              autoComplete="off"
            />
            <p className="mt-1 text-[11px] text-slate-400">
              Anyone joining has to type this as well as the link. Share it separately.
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

          {/* Guest access is only worth anything alongside a passcode, so the
              control is disabled until there is one rather than silently
              doing nothing. */}
          <label className={clsx('flex items-start gap-2 text-sm', form.passcode.trim().length < 4 && 'opacity-50')}>
            <input
              type="checkbox"
              className="mt-0.5"
              disabled={form.passcode.trim().length < 4}
              checked={form.guest_access}
              onChange={(e) => setForm({ ...form, guest_access: e.target.checked })}
            />
            <span>
              Let people join without a Netvork account
              <span className="block text-[11px] text-slate-400">
                {form.passcode.trim().length < 4
                  ? 'Set a passcode first — without one the link alone would let anyone in.'
                  : 'They get 30 minutes, and still need the passcode.'}
              </span>
            </span>
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
