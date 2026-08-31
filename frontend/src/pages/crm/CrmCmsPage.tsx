import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Megaphone, Pin, Plus, Pencil, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmCmsPost } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner, Textarea } from '../../components/ui'

const KIND_LABELS: Record<string, string> = {
  announcement: 'Announcement', policy: 'Policy', holiday: 'Holiday', news: 'News',
}

const KIND_STYLES: Record<string, string> = {
  announcement: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
  policy: 'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300',
  holiday: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
  news: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
}

export default function CrmCmsPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [page, setPage] = useState(1)
  const [kind, setKind] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<CrmCmsPost | null>(null)
  const [form, setForm] = useState({ title: '', body: '', kind: 'announcement', is_pinned: false, status: 'published', publish_on: '', expires_on: '' })
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'cms', page, kind],
    queryFn: () => crm.cms.list(page, kind || undefined),
  })

  const manages = data?.manages ?? false
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'cms'] })

  const openCreate = () => {
    setEditing(null)
    setForm({ title: '', body: '', kind: 'announcement', is_pinned: false, status: 'published', publish_on: '', expires_on: '' })
    setError(null)
    setShowForm(true)
  }

  const openEdit = (p: CrmCmsPost) => {
    setEditing(p)
    setForm({
      title: p.title, body: p.body, kind: p.kind, is_pinned: p.is_pinned,
      status: p.status, publish_on: p.publish_on ?? '', expires_on: p.expires_on ?? '',
    })
    setError(null)
    setShowForm(true)
  }

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = {
        title: form.title, body: form.body, kind: form.kind, is_pinned: form.is_pinned,
        status: form.status, publish_on: form.publish_on || null, expires_on: form.expires_on || null,
      }
      return editing ? crm.cms.update(editing.uuid, payload) : crm.cms.create(payload)
    },
    onSuccess: () => { refresh(); setShowForm(false); toast('Post saved.', 'success') },
    onError: (err) => setError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => crm.cms.remove(uuid),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Notice board</h1>
          <p className="text-sm text-slate-500">Announcements, policies and holidays for the whole team.</p>
        </div>
        <div className="flex gap-2">
          <Select value={kind} onChange={(e) => { setKind(e.target.value); setPage(1) }}>
            <option value="">All kinds</option>
            {Object.entries(KIND_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </Select>
          {manages && <Button onClick={openCreate}><Plus className="size-4" /> New post</Button>}
        </div>
      </div>

      <div className="space-y-3">
        {isLoading ? (
          <Card><div className="flex justify-center py-16"><Spinner /></div></Card>
        ) : !data || data.data.length === 0 ? (
          <Card><EmptyState title="Nothing posted yet" hint="Company announcements appear here." /></Card>
        ) : (
          data.data.map((p) => (
            <Card key={p.uuid} className={clsx(p.is_pinned && 'ring-2 ring-emerald-200 dark:ring-emerald-500/30')}>
              <div className="flex flex-wrap items-center gap-2">
                {p.is_pinned ? <Pin className="size-4 shrink-0 text-emerald-500" /> : <Megaphone className="size-4 shrink-0 text-slate-400" />}
                <h2 className="min-w-0 flex-1 truncate text-base font-semibold text-slate-900 dark:text-white">{p.title}</h2>
                <span className={clsx('rounded-full px-2 py-0.5 text-[11px] font-medium', KIND_STYLES[p.kind])}>{KIND_LABELS[p.kind]}</span>
                {p.status === 'draft' && (
                  <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">Draft</span>
                )}
                {manages && (
                  <div className="flex gap-1">
                    <button onClick={() => openEdit(p)} aria-label="Edit" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                      <Pencil className="size-4" />
                    </button>
                    <button onClick={() => { if (confirm(`Delete "${p.title}"?`)) deleteMutation.mutate(p.uuid) }} aria-label="Delete" className="rounded p-1.5 text-slate-400 hover:text-red-500">
                      <Trash2 className="size-4" />
                    </button>
                  </div>
                )}
              </div>
              <p className="mt-2 whitespace-pre-wrap text-sm text-slate-600 dark:text-slate-300">{p.body}</p>
              <p className="mt-2 text-xs text-slate-400">
                {p.created_by ?? '—'} · {p.created_at?.slice(0, 16)}
                {p.expires_on && <> · until {p.expires_on}</>}
              </p>
            </Card>
          ))
        )}
      </div>
      <Pager resp={data} onPage={setPage} />

      {showForm && (
        <Modal title={editing ? 'Edit post' : 'New post'} onClose={() => setShowForm(false)} wide>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div>
              <Label>Title</Label>
              <Input value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} className="w-full" />
            </div>
            <div>
              <Label>Body</Label>
              <Textarea rows={5} value={form.body} onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))} className="w-full" />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Kind</Label>
                <Select value={form.kind} onChange={(e) => setForm((f) => ({ ...f, kind: e.target.value }))} className="w-full">
                  {Object.entries(KIND_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </Select>
              </div>
              <div>
                <Label>Status</Label>
                <Select value={form.status} onChange={(e) => setForm((f) => ({ ...f, status: e.target.value }))} className="w-full">
                  <option value="published">Published</option>
                  <option value="draft">Draft</option>
                </Select>
              </div>
              <div>
                <Label>Publish on</Label>
                <Input type="date" value={form.publish_on} onChange={(e) => setForm((f) => ({ ...f, publish_on: e.target.value }))} className="w-full" />
              </div>
              <div>
                <Label>Expires on</Label>
                <Input type="date" value={form.expires_on} onChange={(e) => setForm((f) => ({ ...f, expires_on: e.target.value }))} className="w-full" />
              </div>
            </div>
            <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
              <input type="checkbox" checked={form.is_pinned} onChange={(e) => setForm((f) => ({ ...f, is_pinned: e.target.checked }))} className="size-4 accent-emerald-600" />
              Pin to the top
            </label>
            <Button className="w-full" disabled={!form.title || !form.body || saveMutation.isPending} onClick={() => saveMutation.mutate()}>
              {saveMutation.isPending ? 'Saving…' : 'Save post'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}
