import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import {
  Check, CheckCheck, Flag, Mic, Paperclip, Pencil, Phone, Plus, Reply, Send, Smile,
  Square, Trash2, Video, X,
} from 'lucide-react'
import { reportsApi } from '../api/endpoints'
import { REPORT_REASONS } from '../types'
import { format, isToday } from 'date-fns'
import { clsx } from 'clsx'
import { chat } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { getEcho } from '../lib/echo'
import { useAuthStore } from '../stores/auth'
import { useCalls } from '../components/CallManager'
import { Button, EmptyState, Input, Spinner } from '../components/ui'
import type { ChatMessage, ConversationItem } from '../types'

const QUICK_EMOJI = ['👍', '❤️', '😂', '😮', '😢', '🙏']

function VoiceRecorder({ onSend }: { onSend: (blob: Blob, seconds: number) => void }) {
  const [recording, setRecording] = useState(false)
  const [seconds, setSeconds] = useState(0)
  const recorderRef = useRef<MediaRecorder | null>(null)
  const chunksRef = useRef<Blob[]>([])
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null)

  const start = async () => {
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
    } catch {
      alert('Microphone access is required for voice messages.')
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
  const [params, setParams] = useSearchParams()
  const [selected, setSelected] = useState<ConversationItem | null>(null)
  const [draft, setDraft] = useState('')
  const [replyTo, setReplyTo] = useState<ChatMessage | null>(null)
  const [editing, setEditing] = useState<ChatMessage | null>(null)
  const [reactFor, setReactFor] = useState<string | null>(null)
  const bottomRef = useRef<HTMLDivElement>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  const { data: conversations, isLoading } = useQuery({
    queryKey: ['conversations'],
    queryFn: chat.conversations,
    refetchInterval: 20_000,
  })

  const { data: messages } = useQuery({
    queryKey: ['messages', selected?.uuid],
    queryFn: () => chat.messages(selected!.uuid),
    enabled: !!selected,
    refetchInterval: 15_000,
  })

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
    onError: (err) => alert(errorMessage(err)),
  })

  // Deep link: ?start=<app_id> (from connections page) or ?group=<uuid>
  useEffect(() => {
    const startWith = params.get('start')
    const groupUuid = params.get('group')
    if (startWith) {
      chat.start(startWith).then((c) => {
        setSelected(c)
        queryClient.invalidateQueries({ queryKey: ['conversations'] })
      }).catch((err) => alert(errorMessage(err)))
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

    return () => {
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

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [messages?.length])

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

  const startNewChat = () => {
    const appId = prompt('Enter the username or email to message:')
    if (!appId?.trim()) return
    chat.start(appId.trim()).then((c) => {
      setSelected(c)
      queryClient.invalidateQueries({ queryKey: ['conversations'] })
    }).catch((err) => alert(errorMessage(err)))
  }

  const timeLabel = (iso: string) => {
    const date = new Date(iso)
    return isToday(date) ? format(date, 'HH:mm') : format(date, 'd MMM, HH:mm')
  }

  return (
    <div className="flex h-[calc(100vh-8rem)] gap-4">
      {/* Conversation list */}
      <div className={clsx('w-full shrink-0 md:w-72', selected && 'hidden md:block')}>
        <div className="mb-3 flex items-center justify-between">
          <h1 className="text-lg font-semibold">Messages</h1>
          <Button size="sm" onClick={startNewChat}>
            <Plus className="size-3.5" /> New
          </Button>
        </div>
        {isLoading ? (
          <Spinner />
        ) : !conversations?.data.length ? (
          <EmptyState title="No conversations" hint="Start a chat with a connection's App ID." />
        ) : (
          <div className="space-y-1 overflow-y-auto">
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
                <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-slate-200 text-sm font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-200">
                  {c.name.charAt(0).toUpperCase()}
                </div>
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
              <div className="flex items-center gap-2">
                <button className="md:hidden" onClick={() => setSelected(null)}>
                  <X className="size-4" />
                </button>
                <div>
                  <p className="text-sm font-semibold">{selected.name}</p>
                  <p className="text-xs text-slate-400">
                    {selected.type === 'group' ? `${selected.members_count} members` : selected.other_user?.app_id}
                  </p>
                </div>
              </div>
              <div className="flex gap-1">
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

            {/* Messages */}
            <div className="flex-1 space-y-2 overflow-y-auto p-4">
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
                      {!m.is_own && selected.type === 'group' && m.sender && (
                        <p className="mb-0.5 text-[11px] font-semibold opacity-70">{m.sender.name}</p>
                      )}
                      {m.reply_to && (
                        <p className={clsx('mb-1 rounded-lg border-l-2 px-2 py-1 text-xs opacity-80', m.is_own ? 'border-white/50 bg-white/10' : 'border-brand-400 bg-white dark:bg-slate-900')}>
                          <span className="font-medium">{m.reply_to.sender_name}: </span>
                          {m.reply_to.body ?? '…'}
                        </p>
                      )}
                      {m.is_deleted ? (
                        <p className="italic opacity-60">Message deleted</p>
                      ) : (
                        <>
                          {m.body && <p className="whitespace-pre-wrap break-words">{m.body}</p>}
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
                        {m.is_own && (m.is_deleted ? null : <CheckCheck className="size-3" />)}
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
                                .catch((err) => alert(errorMessage(err)))
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
                  onChange={(e) => setDraft(e.target.value)}
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
