import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import {
  Check, CheckCheck, ChevronLeft, Flag, Mic, Paperclip, Pencil, Phone, Plus, Reply, Search, Send,
  Smile, Square, Trash2, Video, X,
} from 'lucide-react'
import { conversationMembers, reportsApi } from '../api/endpoints'
import { PickUserModal } from '../components/UserSuggest'
import { REPORT_REASONS } from '../types'
import { format, isToday } from 'date-fns'
import { clsx } from 'clsx'
import { chat } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { getEcho } from '../lib/echo'
import { useAuthStore } from '../stores/auth'
import { useCalls } from '../components/CallManager'
import { useToast } from '../components/Toast'
import { Button, EmptyState, Input, Modal, Spinner } from '../components/ui'
import type { ChatMessage, ConversationItem } from '../types'
import { Avatar } from '../lib/avatars'

const QUICK_EMOJI = ['👍', '❤️', '😂', '😮', '😢', '🙏']

/**
 * Render message text with any http(s) URLs as clickable links (meeting
 * invites, shared pages, …). Text is still rendered as plain React strings,
 * so this cannot inject markup.
 */
function linkify(text: string, own: boolean) {
  const parts = text.split(/(https?:\/\/[^\s<>"]+)/g)
  if (parts.length === 1) return text

  return parts.map((part, i) =>
    /^https?:\/\//.test(part) ? (
      <a
        key={i}
        href={part}
        target="_blank"
        rel="noopener noreferrer"
        className={clsx(
          'underline underline-offset-2 break-all',
          own ? 'text-white hover:opacity-80' : 'text-brand-600 hover:text-brand-700 dark:text-brand-400',
        )}
      >
        {part}
      </a>
    ) : (
      part
    ),
  )
}

function VoiceRecorder({ onSend }: { onSend: (blob: Blob, seconds: number) => void }) {
  const { toastError } = useToast()
  const [recording, setRecording] = useState(false)
  const [seconds, setSeconds] = useState(0)
  const recorderRef = useRef<MediaRecorder | null>(null)
  const chunksRef = useRef<Blob[]>([])
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null)

  const start = async () => {
    // Asked before the microphone, so a browser that cannot record says so
    // instead of blaming a permission the person already granted.
    if (typeof MediaRecorder === 'undefined') {
      toastError('This browser cannot record audio. Try Chrome, Edge or Safari, or type your message instead.')
      return
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
      const recorder = new MediaRecorder(stream)
      recorderRef.current = recorder
      chunksRef.current = []
      recorder.ondataavailable = (e) => chunksRef.current.push(e.data)
      recorder.onstop = () => {
        stream.getTracks().forEach((t) => t.stop())
      }
      recorder.start()
      setRecording(true)
      setSeconds(0)
      timerRef.current = setInterval(() => setSeconds((s) => s + 1), 1000)
    } catch (err) {
      // Blocked and busy are different problems with different fixes, and
      // "access is required" only described one of them.
      const name = (err as { name?: string } | null)?.name
      toastError(
        name === 'NotAllowedError'
          ? 'Microphone access is blocked. Allow it for this site in your browser, then try again.'
          : name === 'NotFoundError'
            ? 'No microphone was found.'
            : 'The microphone is busy — close any other app or tab using it.',
      )
    }
  }

  const stop = (send: boolean) => {
    const recorder = recorderRef.current
    if (!recorder) return
    if (timerRef.current) clearInterval(timerRef.current)
    recorder.onstop = () => {
      recorder.stream.getTracks().forEach((t) => t.stop())
      if (send && chunksRef.current.length) {
        onSend(new Blob(chunksRef.current, { type: recorder.mimeType || 'audio/webm' }), seconds)
      }
    }
    recorder.stop()
    setRecording(false)
  }

  if (recording) {
    return (
      <div className="flex items-center gap-2">
        <span className="flex items-center gap-1.5 text-xs text-red-500">
          <span className="size-2 animate-pulse rounded-full bg-red-500" />
          {Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, '0')}
        </span>
        <Button type="button" size="sm" variant="secondary" onClick={() => stop(false)} title="Cancel">
          <X className="size-3.5" />
        </Button>
        <Button type="button" size="sm" onClick={() => stop(true)} title="Send voice message">
          <Square className="size-3.5" /> Send
        </Button>
      </div>
    )
  }

  return (
    <button
      type="button"
      className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-brand-600 dark:hover:bg-slate-800"
      onClick={start}
      title="Record voice message"
    >
      <Mic className="size-4" />
    </button>
  )
}

export default function MessagesPage() {
  const queryClient = useQueryClient()
  const { startCall } = useCalls()
  const { toastError } = useToast()
  const [showMembers, setShowMembers] = useState(false)
  const [params, setParams] = useSearchParams()
  const [selected, setSelected] = useState<ConversationItem | null>(null)
  const [draft, setDraft] = useState('')
  const [replyTo, setReplyTo] = useState<ChatMessage | null>(null)
  const [editing, setEditing] = useState<ChatMessage | null>(null)
  const [reactFor, setReactFor] = useState<string | null>(null)
  const [typing, setTyping] = useState<{ uuid: string; name: string }[]>([])
  const typingSentRef = useRef(0)
  const bottomRef = useRef<HTMLDivElement>(null)
  const listRef = useRef<HTMLDivElement>(null)
  const lastConvRef = useRef<string | null>(null)
  /** True once the opened conversation has been pinned to its newest message. */
  const pinnedRef = useRef(false)
  const fileRef = useRef<HTMLInputElement>(null)

  const { data: conversations, isLoading } = useQuery({
    queryKey: ['conversations'],
    queryFn: chat.conversations,
    refetchInterval: 20_000,
  })

  /*
   * Searching within a conversation.
   *
   * The API has always taken `?q=` and filtered message bodies; nothing ever
   * sent it, so finding an old message meant scrolling for it. While a search
   * is running the poll is off — results should not shuffle underneath you.
   */
  const [search, setSearch] = useState('')
  const [searching, setSearching] = useState(false)
  const query = searching ? search.trim() : ''

  const { data: messages } = useQuery({
    queryKey: ['messages', selected?.uuid, query],
    queryFn: () => chat.messages(selected!.uuid, query ? { q: query } : undefined),
    enabled: !!selected,
    refetchInterval: query ? false : 15_000,
  })

  useEffect(() => {
    setSearching(false)
    setSearch('')
  }, [selected?.uuid])

  const invalidateMessages = () => {
    queryClient.invalidateQueries({ queryKey: ['messages', selected?.uuid] })
    queryClient.invalidateQueries({ queryKey: ['conversations'] })
  }

  const sendMutation = useMutation({
    mutationFn: (payload: FormData | Record<string, unknown>) => chat.send(selected!.uuid, payload),
    onSuccess: () => {
      setDraft('')
      setReplyTo(null)
      invalidateMessages()
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  /*
   * Deep link: ?conversation=<uuid>, from the call log.
   *
   * It knows which conversation a call belonged to but not the App ID of
   * whoever was on it, so it cannot use ?start= below. Nothing is fetched:
   * the list is already on its way and the wanted thread is one of its
   * entries, so this waits for it rather than asking twice.
   */
  const wantConversation = params.get('conversation')
  useEffect(() => {
    if (!wantConversation || !conversations?.data) return
    const found = conversations.data.find((c) => c.uuid === wantConversation)
    if (!found) return
    setSelected(found)
    setParams(new URLSearchParams(), { replace: true })
  }, [wantConversation, conversations, setParams])

  // Deep link: ?start=<app_id> (from connections page) or ?group=<uuid>
  useEffect(() => {
    const startWith = params.get('start')
    const groupUuid = params.get('group')
    if (startWith) {
      chat.start(startWith).then((c) => {
        setSelected(c)
        queryClient.invalidateQueries({ queryKey: ['conversations'] })
      }).catch((err) => toastError(errorMessage(err)))
    } else if (groupUuid) {
      chat.groupConversation(groupUuid).then((c) => {
        setSelected(c)
        queryClient.invalidateQueries({ queryKey: ['conversations'] })
      }).catch(() => undefined)
    }
    if (startWith || groupUuid) {
      setParams(new URLSearchParams(), { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // WebSocket: refresh on events for the open conversation
  useEffect(() => {
    if (!selected) return
    const echo = getEcho()
    if (!echo) return

    const channel = echo.private(`conversation.${selected.uuid}`)
    channel.listen('.message.sent', () => {
      invalidateMessages()
      chat.markRead(selected.uuid).catch(() => undefined)
    })
    channel.listen('.message.updated', invalidateMessages)

    // "X is typing…" — each signal keeps the name alive for a few seconds and
    // then lets it lapse, so a sender who closes the tab mid-word does not
    // leave the indicator stuck on forever.
    const timers = new Map<string, ReturnType<typeof setTimeout>>()
    channel.listen('.user.typing', (e: { user_uuid: string; name: string }) => {
      setTyping((t) => (t.some((x) => x.uuid === e.user_uuid) ? t : [...t, { uuid: e.user_uuid, name: e.name }]))
      clearTimeout(timers.get(e.user_uuid))
      timers.set(e.user_uuid, setTimeout(() => {
        setTyping((t) => t.filter((x) => x.uuid !== e.user_uuid))
        timers.delete(e.user_uuid)
      }, 4000))
    })

    return () => {
      timers.forEach(clearTimeout)
      timers.clear()
      setTyping([])
      echo.leave(`conversation.${selected.uuid}`)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected?.uuid])

  // Mark read + scroll on open/new messages
  useEffect(() => {
    if (selected) {
      chat.markRead(selected.uuid).then(() =>
        queryClient.invalidateQueries({ queryKey: ['conversations'] }),
      )
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected?.uuid])

  // Scroll only the message list (never the page), and only when the reader
  // is already near the bottom — don't yank them down while reading history.
  useEffect(() => {
    const el = listRef.current
    if (!el) return
    if (lastConvRef.current !== selected?.uuid) {
      lastConvRef.current = selected?.uuid ?? null
      pinnedRef.current = false
    }

    // The initial pin must wait until the conversation's messages have
    // actually rendered — they usually arrive a beat after the conversation
    // opens, and scrolling an empty list pins nothing.
    const needInitialPin = !pinnedRef.current && (messages?.length ?? 0) > 0
    const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 160
    if (!needInitialPin && !nearBottom) return
    if (needInitialPin) pinnedRef.current = true

    const toBottom = (smooth: boolean) => el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' })
    toBottom(!needInitialPin)
    // Attachments, call chips and fonts finish laying out after first paint
    // and grow the list — follow up until the height settles, or opening a
    // chat lands somewhere in the middle instead of on the latest message.
    const t1 = setTimeout(() => toBottom(false), 150)
    const t2 = setTimeout(() => toBottom(false), 450)
    return () => {
      clearTimeout(t1)
      clearTimeout(t2)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [messages?.length, selected?.uuid])

  const send = () => {
    if (editing) {
      if (draft.trim()) {
        chat.edit(selected!.uuid, editing.uuid, draft.trim()).then(() => {
          setEditing(null)
          setDraft('')
          invalidateMessages()
        })
      }
      return
    }
    if (!draft.trim()) return
    sendMutation.mutate({ body: draft.trim(), reply_to: replyTo?.uuid ?? null })
  }

  const sendFiles = (fileList: File[], type: string, duration?: number) => {
    const form = new FormData()
    fileList.forEach((f) => form.append('attachments[]', f))
    form.append('type', type)
    if (replyTo) form.append('reply_to', replyTo.uuid)
    if (duration !== undefined) form.append('duration_seconds', String(duration))
    sendMutation.mutate(form)
  }

  const [showNewChat, setShowNewChat] = useState(false)
  const startNewChat = () => setShowNewChat(true)
  const beginChatWith = (identifier: string) => {
    chat.start(identifier).then((c) => {
      setSelected(c)
      queryClient.invalidateQueries({ queryKey: ['conversations'] })
    }).catch((err) => toastError(errorMessage(err)))
  }

  const timeLabel = (iso: string) => {
    const date = new Date(iso)
    return isToday(date) ? format(date, 'HH:mm') : format(date, 'd MMM, HH:mm')
  }

  return (
    // h-full, not a 100vh calculation: the shell already gives <main> a
    // definite height, and a vh sum is wrong on any phone whose URL bar
    // shows and hides.
    <div className="flex h-full min-h-0 gap-4">
      {/* Conversation list */}
      <div className={clsx('flex w-full min-h-0 shrink-0 flex-col md:w-72', selected && 'hidden md:flex')}>
        <div className="mb-3 flex shrink-0 items-center justify-between">
          <h1 className="text-xl font-semibold tracking-tight">Messages</h1>
          <Button size="sm" onClick={startNewChat}>
            <Plus className="size-3.5" /> New
          </Button>
        </div>
        {isLoading ? (
          <Spinner />
        ) : !conversations?.data.length ? (
          <EmptyState title="No conversations" hint="Start a chat with a connection's App ID." />
        ) : (
          <div className="scroll-pane min-h-0 flex-1 space-y-1 overflow-y-auto">
            {conversations.data.map((c) => (
              <button
                key={c.uuid}
                onClick={() => setSelected(c)}
                className={clsx(
                  'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors',
                  selected?.uuid === c.uuid
                    ? 'bg-brand-50 dark:bg-brand-950'
                    : 'hover:bg-slate-100 dark:hover:bg-slate-800',
                )}
              >
                <Avatar
                  name={c.name}
                  photoPath={c.type === 'direct' ? c.other_user?.photo_path : null}
                  avatar={c.type === 'direct' ? c.other_user?.avatar : null}
                  size={38}
                />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{c.name}</p>
                  <p className="truncate text-xs text-slate-400">
                    {c.type === 'group' ? `${c.members_count} members` : c.other_user?.app_id}
                  </p>
                </div>
                {c.unread_count > 0 && (
                  <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-600 text-[10px] font-semibold text-white">
                    {c.unread_count > 9 ? '9+' : c.unread_count}
                  </span>
                )}
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Chat window */}
      <div className={clsx('min-w-0 flex-1 flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900', selected ? 'flex' : 'hidden md:flex')}>
        {!selected ? (
          <div className="flex flex-1 items-center justify-center">
            <EmptyState title="Select a conversation" hint="Or start a new one with an App ID." />
          </div>
        ) : (
          <>
            {/* Header */}
            <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-800">
              <div className="flex min-w-0 items-center gap-1.5">
                <button
                  className="tap -ml-2 flex items-center justify-center rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 md:hidden"
                  aria-label="Back to conversations"
                  onClick={() => setSelected(null)}
                >
                  <ChevronLeft className="size-5" />
                </button>
                <div className="min-w-0">
                  <p className="text-sm font-semibold">{selected.name}</p>
                  {selected.type === 'group' ? (
                    <button
                      className="text-xs text-slate-400 hover:text-brand-600 hover:underline"
                      onClick={() => setShowMembers(true)}
                      title="View members"
                    >
                      {selected.members_count} members
                    </button>
                  ) : (
                    <p className="text-xs text-slate-400">
                      {selected.other_user?.username ? `@${selected.other_user.username}` : selected.other_user?.app_id}
                    </p>
                  )}
                </div>
              </div>
              {showNewChat && (
        <PickUserModal
          title="Start a conversation"
          actionLabel="Message"
          onClose={() => setShowNewChat(false)}
          onSubmit={beginChatWith}
        />
      )}
      {showMembers && selected && (
                <MembersModal conversationUuid={selected.uuid} onClose={() => setShowMembers(false)} />
              )}
              <div className="flex gap-1">
                <Button
                  size="sm"
                  variant={searching ? 'primary' : 'ghost'}
                  title="Search this conversation"
                  onClick={() => setSearching((v) => !v)}
                >
                  <Search className="size-4" />
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  title={selected.type === 'group' ? 'Group audio call' : 'Audio call'}
                  onClick={() => {
                    if (
                      selected.type === 'group' &&
                      (selected.members_count ?? 0) > 8 &&
                      !confirm('Large group: mesh calls send your stream to every member, which is heavy on data and battery beyond ~8 people. Start anyway?')
                    ) {
                      return
                    }
                    startCall(selected.uuid, 'audio', selected.name)
                  }}
                >
                  <Phone className="size-4" />
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  title={selected.type === 'group' ? 'Group video call' : 'Video call'}
                  onClick={() => {
                    if (
                      selected.type === 'group' &&
                      (selected.members_count ?? 0) > 8 &&
                      !confirm('Large group: mesh video sends your video to every member, which is heavy on data and battery beyond ~8 people. Start anyway?')
                    ) {
                      return
                    }
                    startCall(selected.uuid, 'video', selected.name)
                  }}
                >
                  <Video className="size-4" />
                </Button>
              </div>
            </div>

            {searching && (
              <div className="shrink-0 border-b border-slate-100 px-4 py-2 dark:border-slate-800">
                <div className="flex items-center gap-2">
                  <Search className="size-4 shrink-0 text-slate-400" />
                  <Input
                    autoFocus
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search this conversation…"
                    className="min-w-0 flex-1 border-0 shadow-none ring-0 focus:ring-0"
                  />
                  <button
                    aria-label="Close search"
                    className="tap flex size-9 items-center justify-center rounded-lg text-slate-400 hover:text-slate-600"
                    onClick={() => { setSearching(false); setSearch('') }}
                  >
                    <X className="size-4" />
                  </button>
                </div>
                {query && (
                  <p className="pl-6 text-xs text-slate-400">
                    {messages?.length
                      ? `${messages.length} message${messages.length === 1 ? '' : 's'} matching “${query}”`
                      : `Nothing matching “${query}”`}
                  </p>
                )}
              </div>
            )}

            {/* Messages */}
            <div ref={listRef} className="flex-1 space-y-2 overflow-y-auto p-4">
              {messages?.map((m) => (
                <div key={m.uuid} className={clsx('group flex', m.is_own ? 'justify-end' : 'justify-start')}>
                  <div className={clsx('relative max-w-[75%]')}>
                    <div
                      className={clsx(
                        'rounded-2xl px-3 py-2 text-sm',
                        m.is_own
                          ? 'rounded-br-sm bg-brand-600 text-white'
                          : 'rounded-bl-sm bg-slate-100 dark:bg-slate-800',
                      )}
                    >
                      {/* A message outlives the account that sent it, so the
                          name can be missing. Saying so is better than a line
                          of text from nobody at all. */}
                      {!m.is_own && selected.type === 'group' && (
                        <p className="mb-0.5 text-[11px] font-semibold opacity-70">
                          {m.sender?.name ?? 'Deleted account'}
                        </p>
                      )}
                      {m.reply_to && (
                        <p className={clsx('mb-1 rounded-lg border-l-2 px-2 py-1 text-xs opacity-80', m.is_own ? 'border-white/50 bg-white/10' : 'border-brand-400 bg-white dark:bg-slate-900')}>
                          <span className="font-medium">{m.reply_to.sender_name ?? 'Deleted account'}: </span>
                          {m.reply_to.body ?? '…'}
                        </p>
                      )}
                      {m.is_deleted ? (
                        <p className="italic opacity-60">Message deleted</p>
                      ) : (
                        <>
                          {m.body && <p className="whitespace-pre-wrap break-words">{linkify(m.body, m.is_own)}</p>}
                          {m.attachments.map((a) => (
                            <div key={a.id} className="mt-1">
                              {(m.type === 'voice' || m.type === 'audio') ? (
                                <AudioAttachment conversationUuid={selected.uuid} attachmentId={a.id} duration={a.duration_seconds} own={m.is_own} />
                              ) : (
                                <a
                                  className={clsx('flex items-center gap-1.5 text-xs underline', m.is_own ? 'text-white' : 'text-brand-600')}
                                  href="#"
                                  onClick={async (e) => {
                                    e.preventDefault()
                                    const token = useAuthStore.getState().token
                                    const res = await fetch(chat.attachmentUrl(selected.uuid, a.id), { headers: { Authorization: `Bearer ${token}` } })
                                    const blob = await res.blob()
                                    const url = URL.createObjectURL(blob)
                                    const link = document.createElement('a')
                                    link.href = url
                                    link.download = a.name
                                    link.click()
                                    URL.revokeObjectURL(url)
                                  }}
                                >
                                  <Paperclip className="size-3" /> {a.name}
                                </a>
                              )}
                            </div>
                          ))}
                        </>
                      )}
                      <p className={clsx('mt-0.5 flex items-center justify-end gap-1 text-[10px]', m.is_own ? 'text-white/70' : 'text-slate-400')}>
                        {m.edited_at && 'edited · '}
                        {timeLabel(m.created_at)}
                        {m.is_own && !m.is_deleted && (
                          m.read_by_others
                            ? <CheckCheck className="size-3" aria-label="Read" />
                            : <Check className="size-3 opacity-70" aria-label="Sent" />
                        )}
                      </p>
                    </div>

                    {/* Reactions */}
                    {m.reactions.length > 0 && (
                      <div className={clsx('mt-0.5 flex gap-1', m.is_own ? 'justify-end' : 'justify-start')}>
                        {m.reactions.map((r) => (
                          <button
                            key={r.emoji}
                            className={clsx(
                              'rounded-full border px-1.5 text-xs',
                              r.mine
                                ? 'border-brand-400 bg-brand-50 dark:bg-brand-950'
                                : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800',
                            )}
                            onClick={() => chat.react(selected.uuid, m.uuid, r.emoji).then(invalidateMessages)}
                          >
                            {r.emoji} {r.count > 1 ? r.count : ''}
                          </button>
                        ))}
                      </div>
                    )}

                    {/* Hover actions */}
                    {!m.is_deleted && (
                      <div className={clsx('absolute -top-3 hidden gap-0.5 rounded-lg border border-slate-200 bg-white p-0.5 shadow-sm group-hover:flex dark:border-slate-700 dark:bg-slate-800', m.is_own ? 'right-0' : 'left-0')}>
                        <button className="rounded p-1 text-slate-400 hover:text-brand-600" title="React" onClick={() => setReactFor(reactFor === m.uuid ? null : m.uuid)}>
                          <Smile className="size-3.5" />
                        </button>
                        <button className="rounded p-1 text-slate-400 hover:text-brand-600" title="Reply" onClick={() => { setReplyTo(m); setEditing(null) }}>
                          <Reply className="size-3.5" />
                        </button>
                        {m.is_own && m.type === 'text' && (
                          <button className="rounded p-1 text-slate-400 hover:text-brand-600" title="Edit" onClick={() => { setEditing(m); setDraft(m.body ?? ''); setReplyTo(null) }}>
                            <Pencil className="size-3.5" />
                          </button>
                        )}
                        {!m.is_own && (
                          <button
                            className="rounded p-1 text-slate-400 hover:text-red-600"
                            title="Report this message"
                            onClick={() => {
                              const reason = prompt(`Report this message — reason (${REPORT_REASONS.join(', ')}):`, 'spam')
                                ?.trim().toLowerCase()
                              if (!reason) return
                              reportsApi.fileMessage(m.uuid, reason)
                                .then((res) => alert((res as { message?: string }).message ?? 'Reported.'))
                                .catch((err) => toastError(errorMessage(err)))
                            }}
                          >
                            <Flag className="size-3.5" />
                          </button>
                        )}
                        <button
                          className="rounded p-1 text-slate-400 hover:text-red-600"
                          title="Delete"
                          onClick={() => {
                            const scope = m.is_own && confirm('Delete for everyone? (Cancel = delete only for you)') ? 'everyone' : 'me'
                            chat.remove(selected.uuid, m.uuid, scope).then(invalidateMessages)
                          }}
                        >
                          <Trash2 className="size-3.5" />
                        </button>
                      </div>
                    )}
                    {reactFor === m.uuid && (
                      <div className={clsx('absolute z-10 flex gap-1 rounded-full border border-slate-200 bg-white px-2 py-1 shadow-lg dark:border-slate-700 dark:bg-slate-800', m.is_own ? 'right-0' : 'left-0')}>
                        {QUICK_EMOJI.map((emoji) => (
                          <button
                            key={emoji}
                            className="text-base hover:scale-125"
                            onClick={() => {
                              chat.react(selected.uuid, m.uuid, emoji).then(invalidateMessages)
                              setReactFor(null)
                            }}
                          >
                            {emoji}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              ))}
              <div ref={bottomRef} />
            </div>

            {/* Composer */}
            <div className="border-t border-slate-200 p-3 dark:border-slate-800">
              {typing.length > 0 && (
                <p className="mb-1.5 flex items-center gap-1.5 text-xs text-slate-400">
                  <span className="flex gap-0.5">
                    {[0, 150, 300].map((delay) => (
                      <span
                        key={delay}
                        className="size-1.5 animate-bounce rounded-full bg-slate-400"
                        style={{ animationDelay: `${delay}ms` }}
                      />
                    ))}
                  </span>
                  {typing.length === 1
                    ? `${typing[0].name} is typing…`
                    : `${typing.map((t) => t.name.split(' ')[0]).join(', ')} are typing…`}
                </p>
              )}
              {(replyTo || editing) && (
                <div className="mb-2 flex items-center justify-between rounded-lg bg-slate-100 px-3 py-1.5 text-xs dark:bg-slate-800">
                  <span className="truncate">
                    {editing ? 'Editing message' : `Replying to ${replyTo?.sender?.name ?? 'message'}: ${replyTo?.body ?? ''}`}
                  </span>
                  <button onClick={() => { setReplyTo(null); setEditing(null); setDraft('') }}>
                    <X className="size-3.5" />
                  </button>
                </div>
              )}
              <div className="flex items-center gap-1.5">
                <button
                  type="button"
                  className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-brand-600 dark:hover:bg-slate-800"
                  title="Attach file"
                  onClick={() => fileRef.current?.click()}
                >
                  <Paperclip className="size-4" />
                </button>
                <input
                  ref={fileRef}
                  type="file"
                  multiple
                  className="hidden"
                  onChange={(e) => {
                    const list = Array.from(e.target.files ?? [])
                    if (list.length) {
                      const type = list.every((f) => f.type.startsWith('image/')) ? 'image' : 'file'
                      sendFiles(list, type)
                    }
                    e.target.value = ''
                  }}
                />
                <VoiceRecorder onSend={(blob, seconds) => {
                  sendFiles([new File([blob], `voice-${Date.now()}.webm`, { type: blob.type })], 'voice', seconds)
                }} />
                <Input
                  placeholder={editing ? 'Edit your message…' : 'Type a message…'}
                  value={draft}
                  onChange={(e) => {
                    setDraft(e.target.value)
                    // One signal every couple of seconds, not one per keystroke.
                    const now = Date.now()
                    if (selected && e.target.value && now - typingSentRef.current > 2000) {
                      typingSentRef.current = now
                      chat.typing(selected.uuid).catch(() => undefined)
                    }
                  }}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                      e.preventDefault()
                      send()
                    }
                  }}
                />
                <Button onClick={send} disabled={sendMutation.isPending || (!draft.trim() && !editing)}>
                  {editing ? <Check className="size-4" /> : <Send className="size-4" />}
                </Button>
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function AudioAttachment({ conversationUuid, attachmentId, duration, own }: {
  conversationUuid: string
  attachmentId: number
  duration?: number | null
  own: boolean
}) {
  const [src, setSrc] = useState<string | null>(null)

  const load = async () => {
    const token = useAuthStore.getState().token
    const res = await fetch(chat.attachmentUrl(conversationUuid, attachmentId), {
      headers: { Authorization: `Bearer ${token}` },
    })
    const blob = await res.blob()
    setSrc(URL.createObjectURL(blob))
  }

  if (!src) {
    return (
      <button
        className={clsx('flex items-center gap-1.5 text-xs underline', own ? 'text-white' : 'text-brand-600')}
        onClick={load}
      >
        <Mic className="size-3" /> Voice message{duration ? ` (${duration}s)` : ''} — tap to load
      </button>
    )
  }

  return <audio controls autoPlay src={src} className="mt-1 h-9 max-w-full" />
}

function MembersModal({ conversationUuid, onClose }: { conversationUuid: string; onClose: () => void }) {
  const { data: members, isLoading } = useQuery({
    queryKey: ['conversation-members', conversationUuid],
    queryFn: () => conversationMembers(conversationUuid),
  })

  return (
    <Modal title="Members" onClose={onClose}>
      {isLoading ? (
        <Spinner />
      ) : (
        <div className="space-y-1.5">
          {members?.map((m) => (
            <div key={m.uuid} className="flex items-center gap-2 rounded-lg border border-slate-100 px-3 py-2 text-sm dark:border-slate-800">
              <Avatar name={m.name} photoPath={m.photo_path} avatar={m.avatar} size={30} />
              <div className="min-w-0 flex-1">
                <p className="truncate font-medium">
                  {m.name}
                  {m.is_me && <span className="ml-1 text-xs font-normal text-slate-400">(you)</span>}
                </p>
                {m.username && <p className="text-xs text-slate-400">@{m.username}</p>}
              </div>
            </div>
          ))}
        </div>
      )}
    </Modal>
  )
}
