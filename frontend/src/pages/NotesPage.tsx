import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Pin, Plus, Share2, Trash2 } from 'lucide-react'
import { formatDistanceToNow } from 'date-fns'
import { clsx } from 'clsx'
import { badges as badgesApi, notes as notesApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import UserSuggest from '../components/UserSuggest'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner, Textarea } from '../components/ui'
import type { Note } from '../types'

interface NoteFormState {
  title: string
  type: 'text' | 'checklist'
  body: string
  checklist: { text: string; done?: boolean }[]
  color: string
  is_pinned: boolean
  password: string
}

const emptyForm: NoteFormState = {
  title: '',
  type: 'text',
  body: '',
  checklist: [],
  color: '',
  is_pinned: false,
  password: '',
}

export default function NotesPage() {
  const queryClient = useQueryClient()

  // Attending this section clears its share notifications.
  useEffect(() => {
    badgesApi.readKinds(['note_shared']).then(() => {
      queryClient.invalidateQueries({ queryKey: ['notifications-count'] })
    }).catch(() => undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  // Back to page 1 whenever the filter/search changes.
  useEffect(() => setPage(1), [query])
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<Note | null>(null)
  const [unlockPassword, setUnlockPassword] = useState('')
  const [form, setForm] = useState<NoteFormState>(emptyForm)
  const [error, setError] = useState<string | null>(null)
  const [shareTarget, setShareTarget] = useState<Note | null>(null)
  const [shareAppId, setShareAppId] = useState('')
  const [sharePermission, setSharePermission] = useState<'view' | 'edit'>('view')

  const { data, isLoading } = useQuery({
    queryKey: ['notes', query, page],
    queryFn: () => notesApi.list({ page, ...(query ? { q: query } : {}) }),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['notes'] })

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = {
        title: form.title,
        type: form.type,
        body: form.type === 'text' ? form.body : null,
        checklist: form.type === 'checklist' ? form.checklist.filter((c) => c.text.trim()) : null,
        color: form.color || null,
        is_pinned: form.is_pinned,
      }
      if (form.password) payload.password = form.password
      return editing
        ? notesApi.update(editing.uuid, payload, unlockPassword || undefined)
        : notesApi.create(payload)
    },
    onSuccess: () => {
      invalidate()
      close()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => notesApi.remove(uuid),
    onSuccess: invalidate,
  })

  const shareMutation = useMutation({
    mutationFn: () => notesApi.share(shareTarget!.uuid, shareAppId, sharePermission),
    onSuccess: () => {
      setShareTarget(null)
      setShareAppId('')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const openNote = async (note: Note) => {
    setError(null)
    setUnlockPassword('')
    if (note.is_locked) {
      const password = prompt('This note is password protected. Enter the password:')
      if (password === null) return
      try {
        const full = await notesApi.get(note.uuid, password)
        setUnlockPassword(password)
        startEdit(full)
      } catch {
        alert('Wrong password.')
      }
      return
    }
    const full = await notesApi.get(note.uuid)
    startEdit(full)
  }

  const startEdit = (note: Note) => {
    setEditing(note)
    setForm({
      title: note.title,
      type: note.type,
      body: note.body ?? '',
      checklist: note.checklist ?? [],
      color: note.color ?? '',
      is_pinned: note.is_pinned,
      password: '',
    })
    setShowForm(true)
  }

  const close = () => {
    setShowForm(false)
    setEditing(null)
    setForm(emptyForm)
    setUnlockPassword('')
  }

  const notesList = data?.data ?? []

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold tracking-tight">Notes</h1>
        <div className="flex gap-2">
          <Input
            placeholder="Search notes…"
            className="w-52"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && setQuery(search)}
          />
          <Button onClick={() => { setEditing(null); setForm(emptyForm); setError(null); setShowForm(true) }}>
            <Plus className="size-4" /> New note
          </Button>
        </div>
      </div>

      {isLoading ? (
        <Spinner />
      ) : notesList.length === 0 ? (
        <Card>
          <EmptyState title="No notes yet" hint="Capture ideas, checklists, and private information." />
        </Card>
      ) : (
        <>
        <div className="columns-1 gap-3 sm:columns-2 lg:columns-3 xl:columns-4">
          {notesList.map((note) => (
            <div key={note.uuid} className="mb-3 break-inside-avoid">
              <Card
                className={clsx('cursor-pointer transition-shadow hover:shadow-md', note.is_pinned && 'ring-1 ring-brand-300')}
              >
                <div onClick={() => openNote(note)}>
                  <div className="flex items-start justify-between gap-2">
                    <h3 className="flex items-center gap-1.5 text-sm font-semibold">
                      {note.color && <span className="size-2.5 rounded-full" style={{ backgroundColor: note.color }} />}
                      {note.title}
                      {note.is_locked && <Lock className="size-3.5 text-slate-400" />}
                      {note.is_pinned && <Pin className="size-3.5 text-brand-500" />}
                    </h3>
                  </div>
                  {note.is_locked ? (
                    <p className="mt-1 text-xs italic text-slate-400">Password protected</p>
                  ) : note.preview ? (
                    <p className="mt-1 line-clamp-4 text-xs text-slate-500">{note.preview}</p>
                  ) : null}
                  <p className="mt-2 text-[11px] text-slate-400">
                    {formatDistanceToNow(new Date(note.updated_at), { addSuffix: true })}
                    {note.group ? ` · ${note.group.name}` : ''}
                    {!note.is_own ? ' · shared with you' : ''}
                  </p>
                </div>
                {note.is_own && (
                  <div className="mt-2 flex justify-end gap-1 border-t border-slate-100 pt-2 dark:border-slate-800">
                    {!note.is_locked && (
                      <button
                        className="rounded p-1 text-slate-400 hover:text-brand-600"
                        title="Share"
                        onClick={() => { setError(null); setShareTarget(note) }}
                      >
                        <Share2 className="size-3.5" />
                      </button>
                    )}
                    <button
                      className="rounded p-1 text-slate-400 hover:text-red-600"
                      title="Delete"
                      onClick={() => {
                        if (confirm(`Delete note "${note.title}"?`)) deleteMutation.mutate(note.uuid)
                      }}
                    >
                      <Trash2 className="size-3.5" />
                    </button>
                  </div>
                )}
              </Card>
            </div>
          ))}
        </div>
        <Pager resp={data} onPage={setPage} />
        </>
      )}

      {/* Editor */}
      {showForm && (
        <Modal title={editing ? 'Edit note' : 'New note'} onClose={close} wide>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              setError(null)
              saveMutation.mutate()
            }}
            className="space-y-4"
          >
            <ErrorNote message={error} />
            <div className="grid grid-cols-3 gap-3">
              <div className="col-span-2">
                <Label>Title</Label>
                <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required autoFocus />
              </div>
              <div>
                <Label>Type</Label>
                <Select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as 'text' | 'checklist' })}>
                  <option value="text">Text</option>
                  <option value="checklist">Checklist</option>
                </Select>
              </div>
            </div>

            {form.type === 'text' ? (
              <div>
                <Label>Content</Label>
                <Textarea rows={8} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} />
              </div>
            ) : (
              <div>
                <Label>Checklist</Label>
                <div className="space-y-2">
                  {form.checklist.map((item, i) => (
                    <div key={i} className="flex items-center gap-2">
                      <input
                        type="checkbox"
                        checked={item.done ?? false}
                        onChange={(e) => {
                          const next = [...form.checklist]
                          next[i] = { ...item, done: e.target.checked }
                          setForm({ ...form, checklist: next })
                        }}
                      />
                      <Input
                        value={item.text}
                        onChange={(e) => {
                          const next = [...form.checklist]
                          next[i] = { ...item, text: e.target.value }
                          setForm({ ...form, checklist: next })
                        }}
                      />
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setForm({ ...form, checklist: form.checklist.filter((_, j) => j !== i) })}
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </div>
                  ))}
                  <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    onClick={() => setForm({ ...form, checklist: [...form.checklist, { text: '' }] })}
                  >
                    <Plus className="size-3.5" /> Add item
                  </Button>
                </div>
              </div>
            )}

            <div className="grid grid-cols-3 items-end gap-3">
              <div>
                <Label>Color</Label>
                <input
                  type="color"
                  value={form.color || '#406cf0'}
                  onChange={(e) => setForm({ ...form, color: e.target.value })}
                  className="h-9 w-16 cursor-pointer rounded border border-slate-300 dark:border-slate-700"
                />
              </div>
              <div>
                <Label>{editing?.is_locked ? 'Change password (blank keeps current)' : 'Password (optional)'}</Label>
                <Input
                  type="password"
                  value={form.password}
                  onChange={(e) => setForm({ ...form, password: e.target.value })}
                  placeholder="Protect this note"
                />
              </div>
              <label className="flex items-center gap-2 pb-2 text-sm">
                <input
                  type="checkbox"
                  checked={form.is_pinned}
                  onChange={(e) => setForm({ ...form, is_pinned: e.target.checked })}
                />
                Pin note
              </label>
            </div>

            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={close}>
                Cancel
              </Button>
              <Button type="submit" disabled={saveMutation.isPending}>
                {saveMutation.isPending ? 'Saving…' : 'Save note'}
              </Button>
            </div>
          </form>
        </Modal>
      )}

      {/* Share dialog */}
      {shareTarget && (
        <Modal title={`Share "${shareTarget.title}"`} onClose={() => setShareTarget(null)}>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              shareMutation.mutate()
            }}
            className="space-y-4"
          >
            <ErrorNote message={error} />
            <div>
              <Label>Share with (username or email)</Label>
              <UserSuggest placeholder="username or email" value={shareAppId} onChange={setShareAppId} required autoFocus />
            </div>
            <div>
              <Label>Permission</Label>
              <Select value={sharePermission} onChange={(e) => setSharePermission(e.target.value as 'view' | 'edit')}>
                <option value="view">Can view</option>
                <option value="edit">Can edit</option>
              </Select>
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => setShareTarget(null)}>
                Cancel
              </Button>
              <Button type="submit" disabled={shareMutation.isPending}>
                Share
              </Button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  )
}
