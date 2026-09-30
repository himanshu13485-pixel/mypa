import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Pin, Plus, Share2, Trash2, Users, X } from 'lucide-react'
import { formatDistanceToNow } from 'date-fns'
import { clsx } from 'clsx'
import { badges as badgesApi, notes as notesApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import UserSuggest from '../components/UserSuggest'
import RichEditor from './crm/mails/RichEditor'
import {
  Button,
  Card,
  EmptyState,
  ErrorNote,
  Input,
  Label,
  Modal,
  Pager,
  Select,
  SkeletonCards,
} from '../components/ui'
import type { Note } from '../types'

interface NoteFormState {
  title: string
  type: 'text' | 'checklist'
  body: string
  checklist: { text: string; done?: boolean }[]
  color: string
  is_pinned: boolean
  daily_report: boolean
  password: string
}

const emptyForm: NoteFormState = {
  title: '',
  type: 'text',
  body: '',
  checklist: [],
  color: '',
  is_pinned: false,
  daily_report: false,
  password: '',
}

/**
 * Older notes are plain text; the editor speaks HTML.
 *
 * Without this the line breaks somebody typed would vanish the first time
 * they opened an old note to edit it - the note would look rewritten by the
 * act of opening it, which is the sort of thing that stops people trusting
 * an editor.
 */
function asHtml(body: string): string {
  if (!body) return ''
  if (/<[a-z][\s\S]*>/i.test(body)) return body

  const escape = (line: string) => line
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')

  // A blank line starts a paragraph; a single one is a line break inside it.
  return body
    .split(/\n{2,}/)
    .map((block) => '<p>' + block.split('\n').map(escape).join('<br>') + '</p>')
    .join('')
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

  /*
   * Writing is saved as it is written.
   *
   * A note is where somebody puts the thing they are about to forget, and
   * asking them to remember a button first is the wrong way round. So the
   * note saves itself a moment after the typing stops, and Save note is
   * there for finishing rather than for keeping.
   *
   * A pause rather than a keystroke: saving on every letter would be a
   * request per letter, and a note is written in bursts.
   */
  const AUTOSAVE_PAUSE_MS = 900
  const [saved, setSaved] = useState<'clean' | 'dirty' | 'saving' | 'saved'>('clean')
  const savedSnapshot = useRef<string>('')
  const autosaveTimer = useRef<number | null>(null)

  /*
   * The password is never carried by an autosave.
   *
   * Autosave fires while somebody is still typing, and a password saved
   * halfway through typing it is a note locked with "Lohit@1" - a lock
   * nobody knows the key to, made by the app being helpful. Protection
   * changes on the explicit save and nowhere else.
   */
  const payloadOf = (f: NoteFormState, withPassword = false): Record<string, unknown> => {
    const payload: Record<string, unknown> = {
      title: f.title,
      type: f.type,
      body: f.type === 'text' ? f.body : null,
      checklist: f.type === 'checklist' ? f.checklist.filter((c) => c.text.trim()) : null,
      color: f.color || null,
      is_pinned: f.is_pinned,
      daily_report: f.daily_report,
    }
    if (withPassword && f.password) payload.password = f.password

    return payload
  }

  const saveMutation = useMutation({
    mutationFn: () => (editing
      ? notesApi.update(editing.uuid, payloadOf(form, true), unlockPassword || undefined)
      : notesApi.create(payloadOf(form, true))),
    onSuccess: () => {
      invalidate()
      close()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  /**
   * The same save, without shutting the dialog.
   *
   * A note with no title has nothing to be listed under, so it waits - a
   * row called "Untitled" appearing the moment somebody starts typing is
   * worse than waiting for them to say what it is.
   */
  const autosave = async (f: NoteFormState) => {
    if (!f.title.trim()) return
    const snapshot = JSON.stringify(payloadOf(f))
    if (snapshot === savedSnapshot.current) return

    setSaved('saving')
    try {
      if (editing) {
        await notesApi.update(editing.uuid, payloadOf(f), unlockPassword || undefined)
      } else {
        // The first save is what gives it a uuid; from here it is an edit,
        // so a note is never created twice by its own second keystroke.
        const made = await notesApi.create(payloadOf(f))
        setEditing(made)
      }
      savedSnapshot.current = snapshot
      setSaved('saved')
      invalidate()
    } catch (err) {
      setSaved('dirty')
      setError(errorMessage(err))
    }
  }

  // The pause, restarted by every keystroke and cleared when the dialog goes.
  useEffect(() => {
    if (!showForm) return
    const snapshot = JSON.stringify(payloadOf(form))
    if (snapshot === savedSnapshot.current) return

    setSaved('dirty')
    if (autosaveTimer.current) window.clearTimeout(autosaveTimer.current)
    autosaveTimer.current = window.setTimeout(() => { void autosave(form) }, AUTOSAVE_PAUSE_MS)

    return () => { if (autosaveTimer.current) window.clearTimeout(autosaveTimer.current) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [form, showForm])

  /**
   * Off with the lock.
   *
   * The server has always accepted a null password from the note's owner
   * and taken the protection off; nothing on screen ever sent one, so a
   * note once locked stayed locked for good.
   */
  const unprotect = async () => {
    if (!editing) return
    if (!window.confirm('Take the password off this note? Anybody it is shared with will be able to open it.')) return
    try {
      const fresh = await notesApi.update(editing.uuid, { password: null }, unlockPassword || undefined)
      setEditing(fresh)
      setForm((f) => ({ ...f, password: '' }))
      setUnlockPassword('')
      invalidate()
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => notesApi.remove(uuid),
    onSuccess: invalidate,
  })

  const shareMutation = useMutation({
    mutationFn: () => notesApi.share(shareTarget!.uuid, shareAppId, sharePermission),
    onSuccess: (note) => {
      // Stay open showing the updated list - people usually share with
      // several at once, and now they can see who is already on it.
      setShareTarget(note)
      setShareAppId('')
      invalidate()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const unshareMutation = useMutation({
    mutationFn: (userUuid: string) => notesApi.unshare(shareTarget!.uuid, userUuid),
    onSuccess: (note) => {
      setShareTarget(note)
      invalidate()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  /*
   * The browser's own prompt shows what is typed into it, in a box nobody
   * can mask - so a note's password was read out to the room every time
   * somebody opened it. This one is a password field like any other.
   */
  const [unlocking, setUnlocking] = useState<Note | null>(null)
  const [unlockTry, setUnlockTry] = useState('')
  const [unlockError, setUnlockError] = useState<string | null>(null)
  const [unlockBusy, setUnlockBusy] = useState(false)

  const openNote = async (note: Note) => {
    setError(null)
    setUnlockPassword('')
    if (note.is_locked) {
      setUnlockTry('')
      setUnlockError(null)
      setForgotSent(null)
      setCode('')
      setFreshPassword('')
      setUnlocking(note)

      return
    }
    const full = await notesApi.get(note.uuid)
    startEdit(full)
  }

  const [forgotSent, setForgotSent] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [freshPassword, setFreshPassword] = useState('')

  const forgotPassword = async () => {
    if (!unlocking) return
    setUnlockError(null)
    try {
      const res = await notesApi.requestPasswordReset(unlocking.uuid)
      setForgotSent(res.message)
    } catch (err) {
      setUnlockError(errorMessage(err))
    }
  }

  const redeemCode = async () => {
    if (!unlocking || !code) return
    setUnlockError(null)
    try {
      await notesApi.resetPassword(unlocking.uuid, code, freshPassword || undefined)
      const was = unlocking
      setCode('')
      setFreshPassword('')
      setForgotSent(null)
      setUnlocking(null)
      invalidate()
      // Straight in, with the new password if one was given.
      const full = await notesApi.get(was.uuid, freshPassword || undefined)
      setUnlockPassword(freshPassword || '')
      startEdit(full)
    } catch (err) {
      setUnlockError(errorMessage(err))
    }
  }

  const tryUnlock = async () => {
    if (!unlocking || !unlockTry) return
    setUnlockBusy(true)
    setUnlockError(null)
    try {
      const full = await notesApi.get(unlocking.uuid, unlockTry)
      setUnlockPassword(unlockTry)
      setUnlocking(null)
      startEdit(full)
    } catch {
      setUnlockError('That is not the password for this note.')
    } finally {
      setUnlockBusy(false)
    }
  }

  const startEdit = (note: Note) => {
    setEditing(note)
    setSaved('clean')
    /*
     * What is on the server, so merely opening a note does not save it.
     *
     * Without this the first run of the autosave effect sees a form it has
     * never saved and writes it back unchanged - a pointless version on
     * every note anybody so much as looked at.
     */
    const opened: NoteFormState = {
      title: note.title,
      type: note.type,
      body: note.body ?? '',
      checklist: note.checklist ?? [],
      color: note.color ?? '',
      is_pinned: note.is_pinned,
      daily_report: !!note.daily_report,
      password: '',
    }
    savedSnapshot.current = JSON.stringify(payloadOf(opened))
    setForm({
      title: note.title,
      type: note.type,
      body: note.body ?? '',
      checklist: note.checklist ?? [],
      color: note.color ?? '',
      is_pinned: note.is_pinned,
      daily_report: !!note.daily_report,
      password: '',
    })
    setShowForm(true)
  }

  const close = () => {
    if (autosaveTimer.current) window.clearTimeout(autosaveTimer.current)
    setShowForm(false)
    setEditing(null)
    setForm(emptyForm)
    setUnlockPassword('')
    setSaved('clean')
    savedSnapshot.current = ''
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
        <SkeletonCards count={6} />
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
                  </p>
                  <SharingLine note={note} />
                </div>
                {note.is_own && (
                  <div className="mt-2 flex justify-end gap-1 border-t border-slate-100 pt-2 dark:border-slate-800">
                    {/* A password does not stop sharing - the reader simply
                        needs the password too. */}
                    <button
                      className="rounded p-1 text-slate-400 hover:text-brand-600"
                      title={note.shared_with?.length ? 'Manage sharing' : 'Share'}
                      onClick={() => { setError(null); setShareTarget(note) }}
                    >
                      <Share2 className="size-3.5" />
                    </button>
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

      {/*
        * Asking for a note's password.
        *
        * The browser's own prompt() cannot mask what is typed into it, so
        * opening a protected note read its password out to anybody in the
        * room - and to anybody watching a screen share, which is how most of
        * these get seen.
        */}
      {unlocking && (
        <Modal title={unlocking.title} onClose={() => setUnlocking(null)}>
          <form
            className="space-y-3"
            onSubmit={(e) => { e.preventDefault(); void tryUnlock() }}
          >
            <p className="text-sm text-slate-500">This note is password protected.</p>
            <Input
              type="password"
              autoComplete="off"
              autoFocus
              value={unlockTry}
              onChange={(e) => setUnlockTry(e.target.value)}
              placeholder="Password"
            />
            {unlockError && <p className="text-xs text-red-600 dark:text-red-400">{unlockError}</p>}
            {forgotSent && <p className="text-xs text-emerald-600 dark:text-emerald-400">{forgotSent}</p>}
            <div className="flex flex-wrap items-center justify-end gap-2">
              {/*
                * A note behind a forgotten password used to be a lost note:
                * there was no way back at all, which made the lock a
                * shredder for anybody who wrote the password down badly.
                */}
              {unlocking.is_own && (
                <button
                  type="button"
                  className="mr-auto text-xs text-brand-600 hover:underline"
                  onClick={() => void forgotPassword()}
                >
                  Forgot it? Email me a code
                </button>
              )}
              <Button type="button" variant="secondary" onClick={() => setUnlocking(null)}>Cancel</Button>
              <Button type="submit" disabled={unlockBusy || !unlockTry}>
                {unlockBusy ? 'Opening…' : 'Open the note'}
              </Button>
            </div>
            {forgotSent && (
              <div className="space-y-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                <p className="text-xs text-slate-500">
                  Type the code from the e-mail. Leave the new password blank to take the password off
                  the note altogether.
                </p>
                <Input value={code} onChange={(e) => setCode(e.target.value)} placeholder="Six-digit code" />
                <Input
                  type="password"
                  value={freshPassword}
                  onChange={(e) => setFreshPassword(e.target.value)}
                  placeholder="New password (optional)"
                />
                <Button type="button" size="sm" disabled={!code} onClick={() => void redeemCode()}>
                  Use the code
                </Button>
              </div>
            )}
          </form>
        </Modal>
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
            /*
             * Only the Save button saves.
             *
             * A form with a submit button in it submits on Enter from any of
             * its fields - so a return pressed in the Title, the colour or
             * the password saved the note without anybody meaning to, which
             * reads as the editor saving on its own while you write. Enter
             * inside the note body is a new line and always was; that is a
             * contentEditable, not a form field, and is untouched by this.
             */
            onKeyDown={(e) => {
              if (e.key === 'Enter' && (e.target as HTMLElement).tagName === 'INPUT') e.preventDefault()
            }}
            className="space-y-4"
          >
            <ErrorNote message={error} />
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div className="sm:col-span-2">
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
                {/*
                  * The same editor the mail compose window uses - bold,
                  * italic, underline, bullets, numbering, links - rather
                  * than a second one written for notes alone. A note people
                  * share with colleagues deserves the formatting that makes
                  * a list read as a list.
                  *
                  * A note written before today is plain text with newlines
                  * in it, which a rich editor would run together into one
                  * paragraph, so it is turned into lines on the way in.
                  */}
                <RichEditor
                  value={asHtml(form.body)}
                  onChange={(html) => setForm({ ...form, body: html })}
                  placeholder="Write the note…"
                  minHeight={260}
                />
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

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 sm:items-end">
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
                {/* A password is applied by Save and close and never by the
                    autosave, which would otherwise lock the note with
                    whatever half of it had been typed so far. */}
                {form.password && (
                  <p className="mt-1 text-xs text-slate-400">Applied when you press Save and close.</p>
                )}
                {editing?.is_locked && editing?.is_own && !form.password && (
                  <button
                    type="button"
                    onClick={() => void unprotect()}
                    className="mt-1 text-xs text-red-600 hover:underline dark:text-red-400"
                  >
                    Remove the password
                  </button>
                )}
              </div>
              <div className="space-y-1.5 sm:pb-2">
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={form.is_pinned}
                    onChange={(e) => setForm({ ...form, is_pinned: e.target.checked })}
                  />
                  Pin note
                </label>
                {/*
                  * The same letter a project's ledger sends, for the place
                  * people keep the thing they are about to forget. Only on
                  * the days it changed - a daily mail that arrives on days
                  * with nothing in it is one people stop opening.
                  */}
                <label className="flex items-start gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="mt-0.5"
                    checked={form.daily_report}
                    onChange={(e) => setForm({ ...form, daily_report: e.target.checked })}
                  />
                  <span>
                    Email me a daily report
                    <span className="block text-xs text-slate-400">
                      On the days it changed. A note with a password is named but never quoted.
                    </span>
                  </span>
                </label>
              </div>
            </div>

            <div className="flex flex-wrap items-center justify-end gap-2">
              {/*
                * What the note has done with itself, said quietly.
                *
                * Writing that saves without being asked has to say so, or
                * nobody believes it and everybody keeps pressing the button
                * anyway. "Untitled" waits for a title, because that is the
                * one thing keeping it from being saved.
                */}
              <span className="mr-auto text-xs text-slate-400" aria-live="polite">
                {!form.title.trim()
                  ? 'Give it a title and it saves itself'
                  : saved === 'saving'
                    ? 'Saving…'
                    : saved === 'dirty'
                      ? 'Unsaved changes'
                      : saved === 'saved'
                        ? 'Saved'
                        : ''}
              </span>
              {/* Cancel while it has never been saved, because then there
                  is genuinely something to throw away. Once autosave has
                  made it, closing is all that is left to do. */}
              <Button type="button" variant="secondary" onClick={close}>
                {editing ? 'Close' : 'Cancel'}
              </Button>
              <Button type="submit" disabled={saveMutation.isPending}>
                {saveMutation.isPending ? 'Saving…' : 'Save and close'}
              </Button>
            </div>
          </form>
        </Modal>
      )}

      {/* Share dialog */}
      {shareTarget && (
        <Modal title={`Share "${shareTarget.title}"`} onClose={() => setShareTarget(null)}>
          <div className="space-y-4">
            <ErrorNote message={error} />

            {shareTarget.is_locked && (
              <p className="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                <Lock className="mt-0.5 size-3.5 shrink-0" />
                This note is password protected. Whoever you share it with will
                need the password from you before they can open it.
              </p>
            )}

            {/* Who is on this note right now. */}
            <div>
              <Label>Shared with</Label>
              {shareTarget.shared_with?.length ? (
                <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                  {shareTarget.shared_with.map((person) => (
                    <li key={person.uuid} className="flex items-center justify-between gap-2 px-3 py-2">
                      <span className="min-w-0 truncate text-sm">
                        {person.name}
                        {person.username && (
                          <span className="text-slate-400"> @{person.username}</span>
                        )}
                      </span>
                      <span className="flex shrink-0 items-center gap-2">
                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                          {person.permission === 'edit' ? 'Can edit' : 'Can view'}
                        </span>
                        <button
                          type="button"
                          title={`Stop sharing with ${person.name}`}
                          className="rounded p-1 text-slate-400 hover:text-red-600 disabled:opacity-50"
                          disabled={unshareMutation.isPending}
                          onClick={() => { setError(null); unshareMutation.mutate(person.uuid) }}
                        >
                          <X className="size-3.5" />
                        </button>
                      </span>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-xs text-slate-400">Not shared with anyone yet.</p>
              )}
            </div>

            <form
              onSubmit={(e) => {
                e.preventDefault()
                setError(null)
                shareMutation.mutate()
              }}
              className="space-y-4 border-t border-slate-100 pt-4 dark:border-slate-800"
            >
              <div>
                <Label>Add someone (username or email)</Label>
                <UserSuggest placeholder="username or email" value={shareAppId} onChange={setShareAppId} required autoFocus />
              </div>
              <div>
                <Label>Permission</Label>
                <Select value={sharePermission} onChange={(e) => setSharePermission(e.target.value as 'view' | 'edit')}>
                  <option value="view">Can view</option>
                  <option value="edit">Can edit</option>
                </Select>
                <p className="mt-1 text-[11px] text-slate-400">
                  Sharing again with the same person just changes their permission.
                </p>
              </div>
              <div className="flex justify-end gap-2">
                <Button type="button" variant="secondary" onClick={() => setShareTarget(null)}>
                  Done
                </Button>
                <Button type="submit" disabled={shareMutation.isPending}>
                  {shareMutation.isPending ? 'Sharing…' : 'Share'}
                </Button>
              </div>
            </form>
          </div>
        </Modal>
      )}
    </div>
  )
}

/**
 * The one line on a card that answers "where has this note gone?" - who sent
 * it to me, or who I sent it to. Silent on notes that are nobody else's
 * business.
 */
function SharingLine({ note }: { note: Note }) {
  if (!note.is_own) {
    return (
      <p className="mt-1 flex items-center gap-1 text-[11px] text-brand-600 dark:text-brand-400">
        <Users className="size-3" />
        Shared with you{note.owner ? ` by ${note.owner.name}` : ''}
      </p>
    )
  }

  const people = note.shared_with ?? []
  if (people.length === 0) return null

  const shown = people.slice(0, 3).map((p) => p.name).join(', ')

  return (
    <p className="mt-1 flex items-start gap-1 text-[11px] text-brand-600 dark:text-brand-400">
      <Users className="mt-0.5 size-3 shrink-0" />
      <span className="min-w-0">
        Shared with {shown}
        {people.length > 3 ? ` +${people.length - 3} more` : ''}
      </span>
    </p>
  )
}
