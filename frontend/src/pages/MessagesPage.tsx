import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import {
  Archive, ArrowDown, Bell, BellOff, Check, CheckCheck, CheckSquare, ChevronLeft, Clock, Copy, Eraser, Flag, Forward, Megaphone, Mic, MoreVertical, Palette, Paperclip, Pencil, Phone, Pin, Plus,
  Reply, Search, Send, Star,
  Smile, Square, Trash2, Video, X,
} from 'lucide-react'
import { badges as badgesApi, conversationMembers, removeConversationMember, reportsApi } from '../api/endpoints'
import type { ConversationMember } from '../api/endpoints'
import MessageAttachment from '../components/MessageAttachment'
import PersonModal from '../components/PersonModal'
import BroadcastModal from '../components/BroadcastModal'
import { EmojiPicker } from '../components/EmojiPicker'
import { insertAtCursor } from '../lib/insertAtCursor'
import { PickUserModal } from '../components/UserSuggest'
import { REPORT_REASONS } from '../types'
import { format, isToday } from 'date-fns'
import { clsx } from 'clsx'
import { chat } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { DELETE_WINDOW_HOURS, withinEditWindow } from '../lib/editWindow'
import { countUnseen, seenIdsOf } from '../lib/unseenMessages'
import { getEcho } from '../lib/echo'
import { useAuthStore } from '../stores/auth'
import { useCalls } from '../components/CallManager'
import { useToast } from '../components/Toast'
import { usePrompt } from '../components/Prompt'
import { Badge, Button, EmptyState, Input, Modal, SkeletonList, SkeletonMessages } from '../components/ui'
import type { ChatMessage, ConversationItem } from '../types'
import { Avatar } from '../lib/avatars'
import { PresenceDot, PresenceInline } from '../components/PresenceDot'
import { lastSeenLabel, resolvePresence, usePresenceMap } from '../lib/presence'
import { useMediaQuery } from '../lib/useMediaQuery'
import { CHAT_THEMES, chatTheme } from '../lib/chatThemes'
import { useLongPress } from '../lib/useLongPress'
import {
  canUnsendAll, copyTextOf, MAX_FORWARD_AT_ONCE, selectedIn, toggleSelected,
} from '../lib/messageSelection'

const QUICK_EMOJI = ['👍', '❤️', '😂', '😮', '😢', '🙏']

/** One line in a chat's ⋮ menu. */
function ChatMenuItem({ icon, label, onClick, danger }: {
  icon: React.ReactNode
  label: string
  onClick: () => void
  /** For the one that empties something. Red before the tap, not after. */
  danger?: boolean
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={clsx(
        'tap flex w-full items-center gap-2.5 px-3 py-2 text-left text-xs hover:bg-slate-100 dark:hover:bg-slate-700',
        danger
          ? 'text-red-600 dark:text-red-400'
          : 'text-slate-600 dark:text-slate-200',
      )}
    >
      <span className={clsx('shrink-0', danger ? 'text-red-500' : 'text-slate-400')}>{icon}</span>
      {label}
    </button>
  )
}

/**
 * Pick the colours one chat is read in.
 *
 * Applied the moment a swatch is tapped rather than behind a Save, because
 * the thread is right there behind the dialog and the only way to judge a
 * colour is to see it. "Use for all my chats" is here because the usual
 * reason to change one is that you want them all this way - and it is a
 * checkbox rather than a second button so that it is plainly the same
 * decision, made wider.
 */
function ThemeModal({ conversation, busy, onPick, onClose }: {
  conversation: ConversationItem
  busy: boolean
  onPick: (theme: string | null, applyToAll: boolean) => void
  onClose: () => void
}) {
  const [applyToAll, setApplyToAll] = useState(false)
  const current = conversation.theme ?? 'default'

  return (
    <Modal title={`Colours for “${conversation.name}”`} onClose={onClose}>
      <div className="space-y-4">
        <p className="text-xs text-slate-400">
          Yours only. The person on the other side keeps whatever colours they chose.
        </p>

        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {CHAT_THEMES.map((t) => (
            <button
              key={t.key}
              type="button"
              disabled={busy}
              onClick={() => onPick(t.key === 'default' ? null : t.key, applyToAll)}
              className={clsx(
                'tap flex flex-col items-center gap-2 rounded-xl border p-3 transition-colors disabled:opacity-60',
                current === t.key
                  ? 'border-brand-500 ring-1 ring-brand-500'
                  : 'border-slate-200 hover:border-slate-300 dark:border-slate-700 dark:hover:border-slate-600',
              )}
            >
              {/* Two bubbles, the way they will actually sit. */}
              <span className="flex w-full flex-col gap-1">
                <span className={clsx('h-3 w-2/3 self-start rounded-full', t.swatch[1])} />
                <span className={clsx('h-3 w-2/3 self-end rounded-full', t.swatch[0])} />
              </span>
              <span className="text-[11px] font-medium">{t.label}</span>
            </button>
          ))}
        </div>

        <label className="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
          <input
            type="checkbox"
            checked={applyToAll}
            onChange={(e) => setApplyToAll(e.target.checked)}
          />
          Use for all my chats
        </label>

        <div className="flex justify-end">
          <Button variant="secondary" onClick={onClose}>Done</Button>
        </div>
      </div>
    </Modal>
  )
}

/**
 * How long a conversation keeps what is said in it. Off is the default and
 * has to stay the default: nobody's history disappears unless somebody in
 * the room asks for it.
 */
const RETENTION_LABELS: Record<number, string> = { 24: '24 hours', 168: '7 days', 720: '30 days' }
const RETENTION_CHOICES: { hours: number | null; label: string; hint: string }[] = [
  { hours: null, label: 'Keep everything', hint: 'Nothing is deleted — the default.' },
  { hours: 24, label: 'Delete after 24 hours', hint: 'Yesterday is gone by this time tomorrow.' },
  { hours: 168, label: 'Delete after 7 days', hint: 'A week of history, no more.' },
  { hours: 720, label: 'Delete after 30 days', hint: 'A month of history, no more.' },
]

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

/**
 * The ceiling on one attachment, in megabytes.
 *
 * Must match mypa.files.max_upload_kb on the server. Stated here so the
 * picker can refuse a file before the upload rather than after it; the
 * server remains the one that actually decides.
 */
const MAX_UPLOAD_MB = 25

export default function MessagesPage() {
  const queryClient = useQueryClient()

  // Opening chat clears the message notifications it produced — one
  // per message means the bell fills up fast otherwise.
  useEffect(() => {
    badgesApi.readKinds(['message']).then(() => {
      queryClient.invalidateQueries({ queryKey: ['notifications-count'] })
    }).catch(() => undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  const { startCall } = useCalls()
  const { toast, toastError } = useToast()
  const [showMembers, setShowMembers] = useState(false)
  const [params, setParams] = useSearchParams()
  const [pickedChat, setSelected] = useState<ConversationItem | null>(null)
  const [draft, setDraft] = useState('')
  const [replyTo, setReplyTo] = useState<ChatMessage | null>(null)
  const [editing, setEditing] = useState<ChatMessage | null>(null)
  const [reactFor, setReactFor] = useState<string | null>(null)
  /*
   * Which message has its action row open, on a touchscreen.
   *
   * The row used to be revealed by `group-hover` alone, which is a rule that
   * can only ever fire for a mouse. On a phone — and most of all inside the
   * Android shell, whose WebView does not fake a hover state the way mobile
   * Chrome sometimes does — reply, forward, edit, pin, star and delete were
   * all present in the DOM, correct, tested, and completely unreachable.
   *
   * One id rather than a boolean per row, like reactFor above it: opening the
   * actions on a second message closes the first, which is the behaviour
   * hover gave for free.
   */
  const [actionsFor, setActionsFor] = useState<string | null>(null)
  /*
   * Hover capability, not screen size.
   *
   * The first cut of this asked useIsPhone(), which would have left every
   * tablet exactly as broken as the phone was — an iPad is wider than any
   * phone breakpoint and has no more hover than one. `(hover: none)` asks the
   * question the bug is actually about, and answers it correctly for the
   * awkward middle too: a touchscreen laptop with a trackpad keeps the hover
   * behaviour, because it genuinely can hover.
   */
  const noHover = useMediaQuery('(hover: none)')
  /*
   * Press and hold a bubble to open its actions — the gesture every
   * messaging app has trained people to reach for. Called once and bound per
   * row, because a hook cannot be called inside the map below.
   */
  const bindLongPress = useLongPress()

  /*
   * Which messages are ticked, and therefore whether the thread is in
   * selection mode at all. One set rather than a flag plus a set: "nothing
   * ticked" and "not selecting" are the same state, and keeping them as two
   * was how the toolbar was going to end up on screen with nothing in it.
   */
  const [selection, setSelection] = useState<Set<string>>(new Set())
  const selecting = selection.size > 0

  const clearSelection = () => setSelection(new Set())

  /*
   * Leaving the conversation drops the selection.
   *
   * Ticks carried into another thread would be ticks on messages that are no
   * longer on screen — and the next bulk delete would take them with it.
   */
  // pickedChat, not the derived `selected` below it: same uuid, and this
  // runs before that line does.
  useEffect(() => { setSelection(new Set()) }, [pickedChat?.uuid])
  const [typing, setTyping] = useState<{ uuid: string; name: string }[]>([])
  const typingSentRef = useRef(0)
  const bottomRef = useRef<HTMLDivElement>(null)
  /** Messages that arrived while the reader was looking further up. */
  const [unseen, setUnseen] = useState(0)
  /** Whose profile is open, if any. */
  const [viewingPerson, setViewingPerson] = useState<string | null>(null)
  /** The message being passed along, and where to. */
  /*
   * What is being forwarded — a list, because forwarding one thing and
   * forwarding twenty differ only in length. A single message from the
   * action row arrives here as a list of one.
   */
  const [forwarding, setForwarding] = useState<ChatMessage[] | null>(null)
  /** The confirm for deleting a whole selection at once. */
  const [deletingMany, setDeletingMany] = useState(false)
  const [deleting, setDeleting] = useState<ChatMessage | null>(null)
  const [pickedChats, setPickedChats] = useState<Set<string>>(new Set())
  /*
   * The messages known to have been seen, by uuid.
   *
   * Not a count: the endpoint hands back a fixed window of recent messages,
   * so two arriving pushes two off the top and the length is exactly what it
   * was. A counter watching the length would report nothing had happened.
   */
  const seenIdsRef = useRef<Set<string>>(new Set())
  const listRef = useRef<HTMLDivElement>(null)
  const lastConvRef = useRef<string | null>(null)
  /** True once the opened conversation has been pinned to its newest message. */
  const pinnedRef = useRef(false)
  const fileRef = useRef<HTMLInputElement>(null)
  const draftInputRef = useRef<HTMLInputElement>(null)

  /**
   * Put an emoji where the cursor is, not always at the end.
   *
   * "Typing 😊 and finishing the sentence" is the ordinary case a chat emoji
   * button exists for, and appending to the end instead would move every
   * emoji you pick mid-sentence to the wrong place the moment you kept
   * typing.
   */
  const insertEmoji = (emoji: string) => {
    const el = draftInputRef.current
    const start = el?.selectionStart ?? draft.length
    const end = el?.selectionEnd ?? draft.length
    const { text, cursor } = insertAtCursor(draft, emoji, start, end)
    setDraft(text)

    // The DOM has not re-rendered with the new value yet on this tick.
    requestAnimationFrame(() => {
      el?.focus()
      el?.setSelectionRange(cursor, cursor)
    })
  }


  /*
   * Presence, as the sockets have it.
   *
   * The list already refetches every twenty seconds, but that was never what
   * a dot should wait for: somebody signing in has to show up now, not on the
   * next poll. This is the live half; the response is the fallback for
   * whoever was already there when the page loaded.
   */
  const livePresence = usePresenceMap()

  /*
   * The archive is a second list, not a filter over this one.
   *
   * A chat you archived should leave the list - that is the whole of what
   * archiving means - so the server keeps them apart and this asks for one
   * side or the other.
   */
  const [showArchived, setShowArchived] = useState(false)

  const { data: conversations, isLoading } = useQuery({
    queryKey: ['conversations', showArchived],
    queryFn: () => chat.conversations(showArchived),
    refetchInterval: 20_000,
  })

  /*
   * The open chat, read back off the list every render.
   *
   * `pickedChat` is the row somebody tapped, and it is a snapshot: pin it,
   * mute it or change its colour and that snapshot still says what it said
   * when it was taken. Looking it up again means the header and the thread
   * follow the list instead of arguing with it, and the fallback keeps a
   * chat open when it drops out of the current list - which is exactly what
   * archiving it from inside does.
   */
  const selected = pickedChat
    ? conversations?.data.find((c) => c.uuid === pickedChat.uuid) ?? pickedChat
    : null

  /** Which row's ⋮ menu is open, and which chat is choosing its colours. */
  const [rowMenu, setRowMenu] = useState<string | null>(null)
  const [headerMenu, setHeaderMenu] = useState(false)
  const [themeFor, setThemeFor] = useState<ConversationItem | null>(null)

  /*
   * The four things you can do to a chat without opening it.
   *
   * All of them are settings on your own membership - pinning, muting and
   * archiving change nothing for the person on the other side - so they all
   * refresh the list and say what happened, and nothing else.
   */
  const refreshChats = () => queryClient.invalidateQueries({ queryKey: ['conversations'] })

  // pinChatMutation, not pinMutation: further down, a message can be pinned
  // inside a chat, which is a different pin entirely.
  const pinChatMutation = useMutation({
    mutationFn: (c: ConversationItem) => chat.togglePin(c.uuid),
    onSuccess: (res) => { toast(res.message); refreshChats() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const muteMutation = useMutation({
    mutationFn: (c: ConversationItem) => chat.toggleMute(c.uuid),
    onSuccess: (res) => { toast(res.message); refreshChats() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const archiveMutation = useMutation({
    mutationFn: (c: ConversationItem) => chat.toggleArchive(c.uuid),
    onSuccess: (res) => { toast(res.message); refreshChats() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const readMutation = useMutation({
    mutationFn: (c: ConversationItem) => chat.markRead(c.uuid),
    onSuccess: () => { refreshChats(); queryClient.invalidateQueries({ queryKey: ['notifications-count'] }) },
    onError: (err) => toastError(errorMessage(err)),
  })

  /** The chat waiting on "yes, empty it" - nothing is cleared until then. */
  const [clearingChat, setClearingChat] = useState<ConversationItem | null>(null)

  const clearMutation = useMutation({
    mutationFn: (c: ConversationItem) => chat.clear(c.uuid),
    onSuccess: (res, c) => {
      toast(res.message)
      setClearingChat(null)
      queryClient.invalidateQueries({ queryKey: ['messages', c.uuid] })
      queryClient.invalidateQueries({ queryKey: ['pinned', c.uuid] })
      refreshChats()
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const themeMutation = useMutation({
    mutationFn: ({ c, theme, all }: { c: ConversationItem; theme: string | null; all: boolean }) =>
      chat.setTheme(c.uuid, theme, all),
    onSuccess: (res) => { toast(res.message); refreshChats() },
    onError: (err) => toastError(errorMessage(err)),
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
  const [retentionOpen, setRetentionOpen] = useState(false)
  const query = searching ? search.trim() : ''

  const { data: messages, isLoading: loadingThread } = useQuery({
    queryKey: ['messages', selected?.uuid, query],
    queryFn: () => chat.messages(selected!.uuid, query ? { q: query } : undefined),
    enabled: !!selected,
    refetchInterval: query ? false : 15_000,
  })

  /* The ticked messages, in the order the thread holds them — a Set remembers
     the order things were tapped, which is not the order they were said. */
  const picked = selectedIn(messages ?? [], selection)

  /*
   * The newest message's identity.
   *
   * What the scroll effects watch, instead of the list's length: the endpoint
   * returns a fixed window of recent messages, so when one arrives another
   * falls off the top and the length is unchanged. An effect keyed on length
   * would simply never run.
   */
  const newestUuid = messages?.length ? messages[messages.length - 1].uuid : null

  /*
   * What is pinned here. Its own query because it is not a slice of the
   * loaded window — a pin from last month stays pinned long after its
   * message has fallen off the end of the thread.
   */
  const { data: pinnedMessages } = useQuery({
    queryKey: ['pinned', selected?.uuid],
    queryFn: () => chat.pinned(selected!.uuid),
    enabled: !!selected,
  })

  /**
   * Warm a thread before it is asked for.
   *
   * The unsearched thread only — that is what opening a conversation shows,
   * and the effect below clears any search on the way in, so the key this
   * fills is the key that will be read.
   */
  const prefetchThread = (uuid: string) => {
    if (uuid === selected?.uuid) return

    void queryClient.prefetchQuery({
      queryKey: ['messages', uuid, ''],
      queryFn: () => chat.messages(uuid),
    })
  }

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

    /*
     * Something arrived while they were reading further up.
     *
     * Yanking the list down would lose their place, and saying nothing means
     * a message can land, be marked read and be scrolled past without ever
     * being seen. So the arrival is counted and offered instead: the reader
     * decides when to go and look.
     */
    if (!needInitialPin && !nearBottom) {
      // Recomputed rather than incremented, so a refetch that changed
      // nothing adds nothing.
      setUnseen(countUnseen(messages, seenIdsRef.current))

      return
    }

    seenIdsRef.current = seenIdsOf(messages)
    setUnseen(0)
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
  }, [newestUuid, messages?.length, selected?.uuid])

  /*
   * Scrolling back down by hand clears the count.
   *
   * The pill is an offer, not a task list: somebody who has arrived at the
   * bottom has seen what arrived, however they got there.
   */
  useEffect(() => {
    const el = listRef.current
    if (!el) return

    const onScroll = () => {
      if (el.scrollHeight - el.scrollTop - el.clientHeight < 160) {
        seenIdsRef.current = seenIdsOf(messages)
        setUnseen(0)
      }
    }

    el.addEventListener('scroll', onScroll, { passive: true })

    return () => el.removeEventListener('scroll', onScroll)
  }, [newestUuid, messages?.length])

  /** Take the reader to the newest message, and stop offering. */
  const jumpToLatest = () => {
    const el = listRef.current
    if (!el) return

    el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' })
    seenIdsRef.current = seenIdsOf(messages)
    setUnseen(0)
  }

  /*
   * Both toggle, and both are optimistic in the same narrow way: the list is
   * refetched rather than patched, because a pin can push somebody else's
   * pin off the end and only the server knows which.
   */
  const starMutation = useMutation({
    mutationFn: (m: ChatMessage) => chat.star(selected!.uuid, m.uuid),
    onSuccess: () => invalidateMessages(),
    onError: (err) => toastError(errorMessage(err)),
  })

  const pinMutation = useMutation({
    mutationFn: (m: ChatMessage) => chat.pin(selected!.uuid, m.uuid),
    onSuccess: () => {
      invalidateMessages()
      queryClient.invalidateQueries({ queryKey: ['pinned', selected?.uuid] })
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const forwardMutation = useMutation({
    /*
     * One request whatever the count. The bulk route keeps them in the order
     * the thread holds them, which a loop of single forwards could not.
     */
    mutationFn: () => chat.forwardMany(
      selected!.uuid,
      (forwarding ?? []).map((m) => m.uuid),
      [...pickedChats],
    ),
    onSuccess: (res) => {
      setForwarding(null)
      setPickedChats(new Set())
      clearSelection()
      queryClient.invalidateQueries({ queryKey: ['conversations'] })
      // Named, not swallowed: an announcement group refuses quietly on the
      // server, and a silent no here would read as a send that worked.
      toast(
        res.data.refused.length
          ? `${res.message} ${res.data.refused.length} could not be posted to.`
          : res.message,
        res.data.refused.length ? 'error' : 'success',
      )
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  /**
   * Star everything ticked.
   *
   * The single star is a toggle, which is right for one message and wrong for
   * twenty: a mixed selection would come out inverted rather than starred.
   * So only the unstarred ones are touched, and a selection that is already
   * starred throughout says so instead of quietly unstarring the lot.
   */
  const bulkStar = () => {
    const toStar = picked.filter((m) => !m.is_starred)
    if (toStar.length === 0) {
      toast('Those are already starred.', 'success')
      clearSelection()

      return
    }

    Promise.allSettled(toStar.map((m) => chat.star(selected!.uuid, m.uuid)))
      .then((results) => {
        const failed = results.filter((r) => r.status === 'rejected').length
        invalidateMessages()
        clearSelection()
        if (failed) toastError(`${failed} could not be starred.`)
        else toast(`Starred ${toStar.length}.`, 'success')
      })
  }

  /**
   * Delete everything ticked, for me or for everyone.
   *
   * One request per message rather than a bulk route: each one has to pass
   * the same authorship and six-hour checks it would alone, and that is
   * exactly what the single endpoint already does. What is reported is what
   * actually happened — a partial failure here is somebody's message staying
   * up, which they need to be told about rather than left to notice.
   */
  const bulkDelete = (scope: 'me' | 'everyone') => {
    const targets = [...picked]
    setDeletingMany(false)

    Promise.allSettled(targets.map((m) => chat.remove(selected!.uuid, m.uuid, scope)))
      .then((results) => {
        const failed = results.filter((r) => r.status === 'rejected').length
        invalidateMessages()
        clearSelection()
        if (failed) toastError(`${targets.length - failed} deleted; ${failed} could not be.`)
        else toast(`Deleted ${targets.length}.`, 'success')
      })
  }

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
    /*
     * Checked here as well as on the server.
     *
     * The server is the one that decides, but it can only say no after the
     * whole file has been sent — which on a phone connection means watching a
     * 40 MB upload crawl to the end and then fail. Saying so before it starts
     * is the difference between a rule and a punishment.
     */
    const tooBig = fileList.filter((f) => f.size > MAX_UPLOAD_MB * 1024 * 1024)

    if (tooBig.length) {
      toastError(
        tooBig.length === 1
          ? `${tooBig[0].name} is larger than ${MAX_UPLOAD_MB} MB.`
          : `${tooBig.length} files are larger than ${MAX_UPLOAD_MB} MB.`,
      )

      return
    }

    const form = new FormData()
    fileList.forEach((f) => form.append('attachments[]', f))
    form.append('type', type)
    if (replyTo) form.append('reply_to', replyTo.uuid)
    if (duration !== undefined) form.append('duration_seconds', String(duration))
    sendMutation.mutate(form)
  }

  const [showNewChat, setShowNewChat] = useState(false)
  const [showBroadcast, setShowBroadcast] = useState(false)
  const startNewChat = () => setShowNewChat(true)
  const beginChatWith = (identifier: string) => {
    chat.start(identifier).then((c) => {
      setSelected(c)
      queryClient.invalidateQueries({ queryKey: ['conversations'] })
    }).catch((err) => toastError(errorMessage(err)))
  }

  /*
   * The header's two facts about the other person, worked out once.
   *
   * Both re-read on every render, and the conversation list refetches every
   * twenty seconds, so "5 min ago" ages into "6 min ago" on its own without a
   * timer of its own.
   */
  const headerPresence = resolvePresence(livePresence, selected?.other_user?.uuid, selected?.other_user?.presence)
  const headerLastSeen = lastSeenLabel(selected?.other_user?.last_seen_at)

  /** The colours this chat is wearing for me. Falls back to the app's own. */
  const theme = chatTheme(selected?.theme)

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
          <div className="flex items-center gap-1.5">
            {/*
              * Icon-only, and next to New rather than inside it.
              *
              * A broadcast is the same gesture as starting a chat — you are
              * choosing who to write to — so it belongs here; but it is the
              * rarer of the two by a long way, and giving it equal billing
              * would make the common thing harder to hit on a narrow list.
              */}
            <Button
              size="sm"
              variant="secondary"
              title="Send one message to several people, privately"
              onClick={() => setShowBroadcast(true)}
            >
              <Megaphone className="size-3.5" />
            </Button>
            <Button size="sm" onClick={startNewChat}>
              <Plus className="size-3.5" /> New
            </Button>
          </div>
        </div>
        {/*
          * The way into the archive, and the way back out.
          *
          * Only shown once there is something in it: an empty archive is a
          * row that explains a feature nobody has used.
          */}
        {(showArchived || (conversations?.archived_count ?? 0) > 0) && (
          <button
            type="button"
            onClick={() => setShowArchived((v) => !v)}
            className="mb-2 flex shrink-0 items-center gap-2 rounded-lg px-3 py-1.5 text-xs text-slate-500 transition-colors hover:bg-slate-100 dark:hover:bg-slate-800"
          >
            {showArchived ? <ChevronLeft className="size-3.5" /> : <Archive className="size-3.5" />}
            {showArchived
              ? 'Back to chats'
              : `Archived (${conversations?.archived_count ?? 0})`}
          </button>
        )}
        {isLoading ? (
          <SkeletonList rows={8} />
        ) : !conversations?.data.length ? (
          <EmptyState
            title={showArchived ? 'Nothing archived' : 'No conversations'}
            hint={showArchived
              ? 'Archived chats are kept here, out of the main list.'
              : "Start a chat with a connection's App ID."}
          />
        ) : (
          <div className="scroll-pane min-h-0 flex-1 space-y-1 overflow-y-auto">
            {conversations.data.map((c) => (
              <div key={c.uuid} className="group relative">
              <button
                onClick={() => setSelected(c)}
                onPointerEnter={() => prefetchThread(c.uuid)}
                onPointerDown={() => prefetchThread(c.uuid)}
                onFocus={() => prefetchThread(c.uuid)}
                className={clsx(
                  // pr-9 keeps the row's own contents clear of the ⋮ that
                  // sits over its right edge.
                  'flex w-full items-center gap-3 rounded-lg py-2.5 pl-3 pr-9 text-left transition-colors',
                  selected?.uuid === c.uuid
                    ? 'bg-brand-50 dark:bg-brand-950'
                    : 'hover:bg-slate-100 dark:hover:bg-slate-800',
                )}
              >
                {/* The dot rides on the avatar so it stays put whatever the
                    row does, and only a direct chat has one: a group is not
                    anywhere in particular. */}
                <div className="relative shrink-0">
                  <Avatar
                    name={c.name}
                    photoPath={c.type === 'direct' ? c.other_user?.photo_path : null}
                    avatar={c.type === 'direct' ? c.other_user?.avatar : null}
                    size={38}
                  />
                  {c.type === 'direct' && (
                    <PresenceDot
                      state={resolvePresence(livePresence, c.other_user?.uuid, c.other_user?.presence)}
                    />
                  )}
                </div>
                <div className="min-w-0 flex-1">
                  <p className="flex items-center gap-1 truncate text-sm font-medium">
                    <span className="truncate">{c.name}</span>
                    {/* Two facts the row has to carry on its own: this one
                        is held at the top, and this one will not ring. */}
                    {c.is_pinned && <Pin className="size-3 shrink-0 text-brand-500" />}
                    {c.is_muted && <BellOff className="size-3 shrink-0 text-slate-400" />}
                  </p>
                  <p className="truncate text-xs text-slate-400">
                    {/* The App ID, always — the dot on the avatar says where
                        they are, and this line used to lose the one identifier
                        on the row to a word repeating it. */}
                    {c.type === 'group' ? `${c.members_count} members` : c.other_user?.app_id}
                  </p>
                </div>
                {c.unread_count > 0 && (
                  <span className={clsx(
                    'flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold text-white',
                    // A muted chat still counts, quietly. A brand-blue badge
                    // on a chat you silenced is the notification you turned
                    // off wearing a different hat.
                    c.is_muted ? 'bg-slate-400' : 'bg-brand-600',
                  )}>
                    {c.unread_count > 9 ? '9+' : c.unread_count}
                  </span>
                )}
              </button>

              {/*
                * The row's own menu.
                *
                * Outside the row button rather than inside it, because a
                * button inside a button is not a thing HTML has: the browser
                * drops one of them, and which one is not up to us. Always
                * present on a touchscreen - `noHover` - and on hover or
                * keyboard focus otherwise.
                */}
              <button
                type="button"
                aria-label={`Options for ${c.name}`}
                onClick={(e) => { e.stopPropagation(); setRowMenu(rowMenu === c.uuid ? null : c.uuid) }}
                className={clsx(
                  'tap absolute right-0.5 top-1/2 flex size-7 -translate-y-1/2 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-200 hover:text-slate-600 focus:opacity-100 dark:hover:bg-slate-700',
                  noHover || rowMenu === c.uuid ? 'opacity-100' : 'opacity-0 group-hover:opacity-100',
                )}
              >
                <MoreVertical className="size-4" />
              </button>

              {rowMenu === c.uuid && (
                <>
                  {/* Anywhere else closes it. */}
                  <div className="fixed inset-0 z-20" onClick={() => setRowMenu(null)} />
                  <div className="absolute right-1 top-11 z-30 w-48 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-lift dark:border-slate-700 dark:bg-slate-800">
                    <ChatMenuItem
                      icon={<Pin className="size-3.5" />}
                      label={c.is_pinned ? 'Unpin chat' : 'Pin to top'}
                      onClick={() => { setRowMenu(null); pinChatMutation.mutate(c) }}
                    />
                    <ChatMenuItem
                      icon={c.is_muted ? <Bell className="size-3.5" /> : <BellOff className="size-3.5" />}
                      label={c.is_muted ? 'Unmute' : 'Mute notifications'}
                      onClick={() => { setRowMenu(null); muteMutation.mutate(c) }}
                    />
                    {c.unread_count > 0 && (
                      <ChatMenuItem
                        icon={<CheckCheck className="size-3.5" />}
                        label="Mark as read"
                        onClick={() => { setRowMenu(null); readMutation.mutate(c) }}
                      />
                    )}
                    <ChatMenuItem
                      icon={<Palette className="size-3.5" />}
                      label="Chat colour…"
                      onClick={() => { setRowMenu(null); setThemeFor(c) }}
                    />
                    <ChatMenuItem
                      icon={<Archive className="size-3.5" />}
                      label={c.is_archived ? 'Unarchive' : 'Archive chat'}
                      onClick={() => { setRowMenu(null); archiveMutation.mutate(c) }}
                    />
                    <ChatMenuItem
                      danger
                      icon={<Eraser className="size-3.5" />}
                      label="Clear chat…"
                      onClick={() => { setRowMenu(null); setClearingChat(c) }}
                    />
                  </div>
                </>
              )}
              </div>
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
            {/*
              * The selection toolbar, in place of the header.
              *
              * In place of, not above: two bars would push the thread down
              * the moment anything was ticked, and a list that jumps while
              * you are picking things out of it is a list you lose your place
              * in. The header has nothing to say while a selection is open
              * anyway — the question on screen is "these ones, and then
              * what", not "who am I talking to".
              */}
            {selecting && (
              <div className="flex items-center justify-between gap-2 border-b border-slate-200 bg-brand-50 px-2 py-2 dark:border-slate-800 dark:bg-brand-500/10">
                <div className="flex min-w-0 items-center gap-1.5">
                  <button
                    className="tap rounded-lg p-2 text-slate-500 hover:bg-white/60 dark:hover:bg-slate-800"
                    aria-label="Cancel selection"
                    onClick={clearSelection}
                  >
                    <X className="size-4" />
                  </button>
                  <span className="truncate text-sm font-medium">{selection.size} selected</span>
                </div>

                <div className="flex items-center gap-0.5">
                  <button
                    className="tap rounded-lg p-2 text-slate-500 hover:bg-white/60 disabled:opacity-40 dark:hover:bg-slate-800"
                    title={picked.some((m) => m.body) ? 'Copy text' : 'Nothing here has text to copy'}
                    aria-label="Copy selected"
                    disabled={!picked.some((m) => m.body)}
                    onClick={() => {
                      navigator.clipboard.writeText(copyTextOf(messages ?? [], selection))
                        .then(() => { toast('Copied.', 'success'); clearSelection() })
                        .catch(() => toastError('This browser would not let the app copy.'))
                    }}
                  >
                    <Copy className="size-4" />
                  </button>
                  <button
                    className="tap rounded-lg p-2 text-slate-500 hover:bg-white/60 dark:hover:bg-slate-800"
                    title="Star"
                    aria-label="Star selected"
                    onClick={() => bulkStar()}
                  >
                    <Star className="size-4" />
                  </button>
                  <button
                    className="tap rounded-lg p-2 text-slate-500 hover:bg-white/60 disabled:opacity-40 dark:hover:bg-slate-800"
                    title={selection.size > MAX_FORWARD_AT_ONCE
                      ? `${MAX_FORWARD_AT_ONCE} messages at a time is the limit`
                      : 'Forward'}
                    aria-label="Forward selected"
                    disabled={selection.size > MAX_FORWARD_AT_ONCE}
                    onClick={() => { setForwarding(picked); setPickedChats(new Set()) }}
                  >
                    <Forward className="size-4" />
                  </button>
                  <button
                    className="tap rounded-lg p-2 text-slate-500 hover:bg-white/60 hover:text-red-600 dark:hover:bg-slate-800"
                    title="Delete"
                    aria-label="Delete selected"
                    onClick={() => setDeletingMany(true)}
                  >
                    <Trash2 className="size-4" />
                  </button>
                </div>
              </div>
            )}

            {/* Header */}
            <div className={clsx('flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-800', selecting && 'hidden')}>
              <div className="flex min-w-0 items-center gap-1.5">
                <button
                  className="tap -ml-2 flex items-center justify-center rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 md:hidden"
                  aria-label="Back to conversations"
                  onClick={() => setSelected(null)}
                >
                  <ChevronLeft className="size-5" />
                </button>
                <div className="min-w-0">
                  {/* The person you are talking to, tappable. A one-to-one
                      chat header names somebody; it should also be the way
                      to find out who they are. */}
                  {selected.type !== 'group' && selected.other_user?.uuid ? (
                    <button
                      type="button"
                      onClick={() => setViewingPerson(selected.other_user!.uuid)}
                      className="block max-w-full truncate text-left text-sm font-semibold hover:underline"
                    >
                      {selected.name}
                    </button>
                  ) : (
                    <p className="text-sm font-semibold">{selected.name}</p>
                  )}
                  {selected.type === 'group' ? (
                    <button
                      className="text-xs text-slate-400 hover:text-brand-600 hover:underline"
                      onClick={() => setShowMembers(true)}
                      title="View members"
                    >
                      {selected.members_count} members
                    </button>
                  ) : (
                    <p className="flex items-center gap-1.5 text-xs text-slate-400">
                      <span className="truncate">
                        {selected.other_user?.username ? `@${selected.other_user.username}` : selected.other_user?.app_id}
                      </span>
                      {/* The one place presence has no avatar to sit on, so
                          the dot goes on the line itself — beside the handle
                          rather than replacing it. */}
                      <PresenceInline state={headerPresence} />
                      {/*
                        * Last seen, but not while they are here.
                        *
                        * "Online · last seen just now" is two ways of saying
                        * the same thing, and the second is the one that stops
                        * being true first. It only earns its place once the
                        * answer to "are they there" is no, which is exactly
                        * when the reader starts wondering how long ago.
                        *
                        * Absent when they have hidden it — the Settings
                        * switch for that finally governs something — and
                        * absent when they have never opened the app.
                        */}
                      {headerPresence !== 'online' && headerLastSeen && (
                        <span className="truncate">· {headerLastSeen}</span>
                      )}
                    </p>
                  )}
                </div>
              </div>
              {viewingPerson && (
        <PersonModal uuid={viewingPerson} onClose={() => setViewingPerson(null)} />
      )}

      {/*
        * The same two deletions, for a whole selection.
        *
        * "Delete for everyone" is offered only when every message ticked is
        * still inside its window — a mixed selection would delete half for
        * everybody and half for nobody, and the half left standing would be
        * the one somebody most wanted gone. When it cannot be offered the
        * dialog says why rather than quietly showing one button.
        */}
      {deletingMany && (
        <Modal title={`Delete ${selection.size} messages`} onClose={() => setDeletingMany(false)}>
          <div className="space-y-3">
            {!canUnsendAll(messages ?? [], selection) && (
              <p className="text-xs text-amber-600">
                Some of these are older than {DELETE_WINDOW_HOURS} hours or are not yours, so they
                cannot be taken back for everyone. You can still remove all of them from your own
                screen.
              </p>
            )}

            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="secondary" onClick={() => setDeletingMany(false)}>Cancel</Button>
              <Button variant="secondary" onClick={() => bulkDelete('me')}>Delete for me</Button>
              {canUnsendAll(messages ?? [], selection) && (
                <Button variant="danger" onClick={() => bulkDelete('everyone')}>
                  Delete for everyone
                </Button>
              )}
            </div>

            <p className="text-right text-[11px] text-slate-400">
              Everyone still sees that a message was deleted.
            </p>
          </div>
        </Modal>
      )}

      {/*
        * Two deletions wearing one word, asked as two buttons.
        *
        * This used to be a confirm() reading "Delete for everyone? (Cancel =
        * delete only for you)" — where Cancel deleted something. A dialog in
        * which the way out of the dialog performs an action is not a
        * confirmation at all, and the one thing every person on earth knows
        * about Cancel is that it cancels.
        */}
      {deleting && (
        <Modal title="Delete message" onClose={() => setDeleting(null)}>
          <div className="space-y-3">
            <p className="rounded-lg bg-slate-100 p-2 text-xs text-slate-500 dark:bg-slate-800">
              {deleting.body
                ? deleting.body.slice(0, 140)
                : `${deleting.attachments?.length ?? 0} attachment(s)`}
            </p>

            {deleting.is_own && !deleting.can_delete_for_everyone && (
              <p className="text-xs text-amber-600">
                This one is older than {DELETE_WINDOW_HOURS} hours, so it can no longer be taken back
                for everyone. You can still remove it from your own screen.
              </p>
            )}

            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="secondary" onClick={() => setDeleting(null)}>Cancel</Button>
              <Button
                variant="secondary"
                onClick={() => {
                  chat.remove(selected!.uuid, deleting.uuid, 'me')
                    .then(invalidateMessages)
                    .catch((err) => toastError(errorMessage(err)))
                  setDeleting(null)
                }}
              >
                Delete for me
              </Button>
              {deleting.can_delete_for_everyone && (
                <Button
                  variant="danger"
                  onClick={() => {
                    chat.remove(selected!.uuid, deleting.uuid, 'everyone')
                      .then(invalidateMessages)
                      .catch((err) => toastError(errorMessage(err)))
                    setDeleting(null)
                  }}
                >
                  Delete for everyone
                </Button>
              )}
            </div>

            {/* Said plainly, because people expect "delete" to mean gone. */}
            <p className="text-right text-[11px] text-slate-400">
              Everyone still sees that a message was deleted.
            </p>
          </div>
        </Modal>
      )}

      {/*
        * Where to pass it along to.
        *
        * Several at once, because forwarding one thing to three people is one
        * decision rather than three — and the thread it came from is left out
        * of the list, since sending a message back into its own conversation
        * is never what anybody meant.
        */}
      {forwarding && (
        <Modal title="Forward to" onClose={() => setForwarding(null)}>
          <div className="space-y-3">
            <p className="rounded-lg bg-slate-100 p-2 text-xs text-slate-500 dark:bg-slate-800">
              {forwarding.length > 1
                ? `${forwarding.length} messages`
                : forwarding[0]?.body
                  ? forwarding[0].body.slice(0, 140)
                  : `${forwarding[0]?.attachments?.length ?? 0} attachment(s)`}
            </p>

            <div className="max-h-64 space-y-1 overflow-y-auto">
              {(conversations?.data ?? [])
                .filter((c) => c.uuid !== selected?.uuid)
                .map((c) => (
                  <label key={c.uuid} className="flex items-center gap-2 rounded-lg px-1 py-1.5 text-sm">
                    <input
                      type="checkbox"
                      className="size-4 accent-brand-600"
                      checked={pickedChats.has(c.uuid)}
                      onChange={() => {
                        const next = new Set(pickedChats)
                        if (!next.delete(c.uuid)) next.add(c.uuid)
                        setPickedChats(next)
                      }}
                    />
                    <span className="truncate">{c.name}</span>
                  </label>
                ))}
            </div>

            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setForwarding(null)}>Cancel</Button>
              <Button
                disabled={pickedChats.size === 0 || forwardMutation.isPending}
                onClick={() => forwardMutation.mutate()}
              >
                {forwardMutation.isPending
                  ? 'Sending…'
                  : `Forward${pickedChats.size ? ` to ${pickedChats.size}` : ''}`}
              </Button>
            </div>
          </div>
        </Modal>
      )}
      {showBroadcast && (
        <BroadcastModal
          onClose={() => setShowBroadcast(false)}
          onSent={() => queryClient.invalidateQueries({ queryKey: ['conversations'] })}
        />
      )}
      {showNewChat && (
        <PickUserModal
          title="Start a conversation"
          actionLabel="Message"
          onClose={() => setShowNewChat(false)}
          onSubmit={beginChatWith}
        />
      )}
      {/*
        * Clearing is not deleting, and the dialog has to say so.
        *
        * The word people carry into this from every other app is the one
        * that means "gone" - so the sentence that matters most here is the
        * one about the other side keeping everything, and it is above the
        * button rather than under it.
        */}
      {clearingChat && (
        <Modal title={`Clear “${clearingChat.name}”`} onClose={() => setClearingChat(null)}>
          <div className="space-y-3">
            <p className="text-sm text-slate-600 dark:text-slate-300">
              Every message in this chat will be removed from your screen.
            </p>
            <p className="rounded-lg bg-slate-100 p-2 text-xs text-slate-500 dark:bg-slate-800">
              {clearingChat.type === 'group' ? 'Everyone else in the group' : 'The other person'} keeps
              their copy - this only clears yours, and it cannot be undone. The chat stays in your
              list, and anything said from now on arrives as normal.
            </p>
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setClearingChat(null)}>Cancel</Button>
              <Button
                variant="danger"
                disabled={clearMutation.isPending}
                onClick={() => clearMutation.mutate(clearingChat)}
              >
                {clearMutation.isPending ? 'Clearing…' : 'Clear for me'}
              </Button>
            </div>
          </div>
        </Modal>
      )}
      {themeFor && (
        <ThemeModal
          conversation={themeFor}
          busy={themeMutation.isPending}
          onPick={(key, all) => themeMutation.mutate({ c: themeFor, theme: key, all })}
          onClose={() => setThemeFor(null)}
        />
      )}
      {showMembers && selected && (
                <MembersModal
                  conversationUuid={selected.uuid}
                  onClose={() => setShowMembers(false)}
                  onChanged={() => queryClient.invalidateQueries({ queryKey: ['conversations'] })}
                />
              )}
              {retentionOpen && selected && (
                <RetentionModal
                  conversationUuid={selected.uuid}
                  current={selected.auto_delete_hours ?? null}
                  onClose={() => setRetentionOpen(false)}
                  onSaved={() => {
                    setRetentionOpen(false)
                    queryClient.invalidateQueries({ queryKey: ['conversations'] })
                    queryClient.invalidateQueries({ queryKey: ['messages', selected.uuid] })
                  }}
                />
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
                {/* Disappearing messages. Lit when a span is set, so the
                    room can see at a glance that it is on. */}
                <Button
                  size="sm"
                  variant={selected.auto_delete_hours ? 'primary' : 'ghost'}
                  title={selected.auto_delete_hours
                    ? `Messages delete themselves after ${RETENTION_LABELS[selected.auto_delete_hours] ?? 'a while'}`
                    : 'Auto-delete messages'}
                  onClick={() => setRetentionOpen(true)}
                >
                  <Clock className="size-4" />
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

                {/*
                  * Everything about the chat itself, in one place.
                  *
                  * The header had four buttons and no room for four more, and
                  * pinning, muting, colouring and archiving are all things you
                  * do to a conversation once and then forget - which is what a
                  * menu is for.
                  */}
                <div className="relative">
                  <Button
                    size="sm"
                    variant={headerMenu ? 'primary' : 'ghost'}
                    title="Chat options"
                    aria-label="Chat options"
                    onClick={() => setHeaderMenu((v) => !v)}
                  >
                    <MoreVertical className="size-4" />
                  </Button>
                  {headerMenu && (
                    <>
                      <div className="fixed inset-0 z-20" onClick={() => setHeaderMenu(false)} />
                      <div className="absolute right-0 top-10 z-30 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-lift dark:border-slate-700 dark:bg-slate-800">
                        <ChatMenuItem
                          icon={<Pin className="size-3.5" />}
                          label={selected.is_pinned ? 'Unpin chat' : 'Pin to top'}
                          onClick={() => { setHeaderMenu(false); pinChatMutation.mutate(selected) }}
                        />
                        <ChatMenuItem
                          icon={selected.is_muted ? <Bell className="size-3.5" /> : <BellOff className="size-3.5" />}
                          label={selected.is_muted ? 'Unmute' : 'Mute notifications'}
                          onClick={() => { setHeaderMenu(false); muteMutation.mutate(selected) }}
                        />
                        <ChatMenuItem
                          icon={<Palette className="size-3.5" />}
                          label="Chat colour…"
                          onClick={() => { setHeaderMenu(false); setThemeFor(selected) }}
                        />
                        {selected.type === 'group' && (
                          <ChatMenuItem
                            icon={<CheckSquare className="size-3.5" />}
                            label="View members"
                            onClick={() => { setHeaderMenu(false); setShowMembers(true) }}
                          />
                        )}
                        <ChatMenuItem
                          icon={<Archive className="size-3.5" />}
                          label={selected.is_archived ? 'Unarchive' : 'Archive chat'}
                          onClick={() => { setHeaderMenu(false); archiveMutation.mutate(selected) }}
                        />
                        <ChatMenuItem
                          danger
                          icon={<Eraser className="size-3.5" />}
                          label="Clear chat…"
                          onClick={() => { setHeaderMenu(false); setClearingChat(selected) }}
                        />
                      </div>
                    </>
                  )}
                </div>
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
            {/*
              * What this conversation is holding up.
              *
              * Above the thread rather than inside it: a pin whose whole job
              * is to stay findable should not scroll away with the messages
              * it was pinned above.
              */}
            {!!pinnedMessages?.length && (
              <div className="border-b border-slate-200 bg-slate-50 px-4 py-2 dark:border-slate-800 dark:bg-slate-800/40">
                {pinnedMessages.slice(0, 3).map((m) => (
                  <div key={m.uuid} className="flex items-center gap-2 py-0.5 text-xs">
                    <Pin className="size-3 shrink-0 text-brand-600" />
                    <span className="min-w-0 flex-1 truncate">{m.body || 'Attachment'}</span>
                    <button
                      className="shrink-0 text-slate-400 hover:text-brand-600"
                      title="Unpin"
                      onClick={() => pinMutation.mutate(m)}
                    >
                      <X className="size-3" />
                    </button>
                  </div>
                ))}
                {pinnedMessages.length > 3 && (
                  <p className="pt-0.5 text-[11px] text-slate-400">
                    and {pinnedMessages.length - 3} more pinned
                  </p>
                )}
              </div>
            )}

            {/*
              * What arrived while you were reading further up.
              *
              * Positioned over the list rather than inside it so it stays put
              * while the list scrolls underneath — a marker that moves with
              * the content is a marker you have to chase.
              */}
            {unseen > 0 && (
              <div className="pointer-events-none relative z-10 flex justify-center">
                <button
                  type="button"
                  onClick={jumpToLatest}
                  className="pointer-events-auto absolute top-2 flex items-center gap-1.5 rounded-full bg-brand-600 px-3 py-1.5 text-xs font-medium text-white shadow-lift hover:bg-brand-700"
                >
                  <ArrowDown className="size-3.5" />
                  {unseen} new {unseen === 1 ? 'message' : 'messages'}
                </button>
              </div>
            )}

            <div ref={listRef} className={clsx('flex-1 space-y-2 overflow-y-auto p-4', theme.pane)}>
              {/* A thread being opened for the first time. Once it has been
                  read once the cache answers instantly and this never shows;
                  before, every switch blanked the panel either way. */}
              {loadingThread && !messages && <SkeletonMessages />}
              {messages?.map((m) => (
                <div
                  key={m.uuid}
                  className={clsx(
                    'group flex',
                    m.is_own ? 'justify-end' : 'justify-start',
                    // The whole row lights up, not just the bubble: at a
                    // glance the question is "how many have I got", and a
                    // tint that stops at the bubble's edge is hard to count.
                    selection.has(m.uuid) && '-mx-4 bg-brand-500/10 px-4 py-0.5',
                  )}
                >
                  <div className={clsx('relative max-w-[75%]')}>
                    <div
                      className={clsx(
                        'rounded-2xl px-3 py-2 text-sm',
                        m.is_own
                          ? clsx('rounded-br-sm', theme.own)
                          : clsx('rounded-bl-sm', theme.theirs),
                        // Only where the gesture is the way in. A mouse has
                        // hover, and suppressing its text selection to catch
                        // a press it will never make would be a plain loss.
                        noHover && 'select-none',
                        // While picking, a tap anywhere on the bubble is the
                        // tick — so the whole thing has to look pressable,
                        // and nothing inside may answer the tap first. A link
                        // would navigate away and an attachment would start
                        // downloading, both while also ticking the message.
                        selecting && 'cursor-pointer [&_a]:pointer-events-none [&_button]:pointer-events-none',
                      )}
                      onClick={selecting
                        ? (e) => { e.preventDefault(); setSelection(toggleSelected(selection, m.uuid)) }
                        : undefined}
                      {...(noHover && !m.is_deleted && !selecting
                        ? bindLongPress(() => { setActionsFor(m.uuid); setReactFor(null) })
                        : {})}
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
                                /* A photo looks like the photo; anything else
                                   gets a chip with its name and size, rather
                                   than a wall of underlined filenames. */
                                <MessageAttachment
                                  conversationUuid={selected.uuid}
                                  attachment={a}
                                  own={m.is_own}
                                />
                              )}
                            </div>
                          ))}
                        </>
                      )}
                      <p className={clsx('mt-0.5 flex items-center justify-end gap-1 text-[10px]', m.is_own ? 'text-white/70' : 'text-slate-400')}>
                        {/* "The meeting is cancelled" carries one weight from
                            the person who decided it and another from somebody
                            passing it on. */}
                        {m.is_forwarded && 'forwarded · '}
                        {/* Yours alone: the copy they received says nothing
                            about there having been others. */}
                        {!!m.broadcast_to && `broadcast to ${m.broadcast_to} · `}
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

                    {/*
                      * Message actions.
                      *
                      * On a mouse these appear on hover, as they always have.
                      * On a touchscreen there is no hover to appear on, so the
                      * same row gets a visible handle to open it — every one
                      * of reply, forward, edit, pin, star and delete was
                      * otherwise unreachable on a phone, which is exactly how
                      * a feature ships, passes its tests, and still cannot be
                      * used by the people it was built for.
                      *
                      * A visible handle rather than a long-press: long-press
                      * fights text selection and scrolling for the same
                      * gesture, and a gesture nobody is told about is not
                      * much better than no gesture at all.
                      */}
                    {!m.is_deleted && noHover && actionsFor !== m.uuid && (
                      <button
                        type="button"
                        aria-label="Message actions"
                        className={clsx('absolute -top-3 flex size-6 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-400 shadow-sm dark:border-slate-700 dark:bg-slate-800', m.is_own ? 'right-0' : 'left-0')}
                        onClick={() => { setActionsFor(m.uuid); setReactFor(null) }}
                      >
                        <MoreVertical className="size-3.5" />
                      </button>
                    )}
                    {!m.is_deleted && (!noHover || actionsFor === m.uuid) && (
                      <div
                        className={clsx(
                          'absolute -top-3 gap-0.5 rounded-lg border border-slate-200 bg-white p-0.5 shadow-sm dark:border-slate-700 dark:bg-slate-800',
                          m.is_own ? 'right-0' : 'left-0',
                          // Open on tap for touch; on hover, as before, for a mouse.
                          noHover ? 'flex' : 'hidden group-hover:flex',
                        )}
                        // Whatever was tapped in here, the row has done its
                        // job — collapse it back to the handle. Bubbles after
                        // the button's own handler, so the action still runs.
                        onClick={() => { if (noHover) setActionsFor(null) }}
                      >
                        <button className="rounded p-1 text-slate-400 hover:text-brand-600" title="React" onClick={() => setReactFor(reactFor === m.uuid ? null : m.uuid)}>
                          <Smile className="size-3.5" />
                        </button>
                        <button className="rounded p-1 text-slate-400 hover:text-brand-600" title="Reply" onClick={() => { setReplyTo(m); setEditing(null) }}>
                          <Reply className="size-3.5" />
                        </button>
                        {/*
                          * Into selection mode, with this one already ticked.
                          *
                          * The way in is here rather than a second gesture on
                          * the bubble: long-press already opens this row, and
                          * a press-and-hold that sometimes opens a menu and
                          * sometimes starts selecting depending on how long
                          * you held it is a coin toss, not an interface.
                          */}
                        <button
                          className="rounded p-1 text-slate-400 hover:text-brand-600"
                          title="Select messages"
                          onClick={() => setSelection(new Set([m.uuid]))}
                        >
                          <CheckSquare className="size-3.5" />
                        </button>
                        {/*
                          * Copy, which the long-press took away.
                          *
                          * Holding a bubble now opens this row, so on a touch
                          * device the browser's own press-to-select-text no
                          * longer happens — and "copy what someone sent me"
                          * is far too ordinary a thing to lose. Text only:
                          * there is nothing to put on a clipboard for a
                          * message that is just a file.
                          */}
                        {!!m.body && (
                          <button
                            className="rounded p-1 text-slate-400 hover:text-brand-600"
                            title="Copy text"
                            onClick={() => {
                              navigator.clipboard.writeText(m.body ?? '')
                                .then(() => toast('Copied.', 'success'))
                                .catch(() => toastError('This browser would not let the app copy. Select the text by hand.'))
                            }}
                          >
                            <Copy className="size-3.5" />
                          </button>
                        )}
                        <button
                          className="rounded p-1 text-slate-400 hover:text-brand-600"
                          title="Forward"
                          onClick={() => { setForwarding([m]); setPickedChats(new Set()) }}
                        >
                          <Forward className="size-3.5" />
                        </button>
                        {/* Kept privately — nobody else in the thread is told. */}
                        <button
                          className={clsx('rounded p-1 hover:text-amber-500',
                            m.is_starred ? 'text-amber-500' : 'text-slate-400')}
                          title={m.is_starred ? 'Remove from starred' : 'Star'}
                          onClick={() => starMutation.mutate(m)}
                        >
                          <Star className={clsx('size-3.5', m.is_starred && 'fill-current')} />
                        </button>
                        {/* Held up for everyone. */}
                        <button
                          className={clsx('rounded p-1 hover:text-brand-600',
                            m.pinned_at ? 'text-brand-600' : 'text-slate-400')}
                          title={m.pinned_at ? 'Unpin' : 'Pin for everyone'}
                          onClick={() => pinMutation.mutate(m)}
                        >
                          <Pin className={clsx('size-3.5', m.pinned_at && 'fill-current')} />
                        </button>
                        {m.is_own && m.type === 'text' && withinEditWindow(m.created_at) && (
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
                          onClick={() => setDeleting(m)}
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
                <EmojiPicker onPick={insertEmoji} />
                <Input
                  ref={draftInputRef}
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

/**
 * Choosing how long this conversation keeps things. The setting is the
 * room's, not one member's, so the dialog says so plainly and everyone is
 * told in the thread when it changes.
 */
function RetentionModal({ conversationUuid, current, onClose, onSaved }: {
  conversationUuid: string
  current: number | null
  onClose: () => void
  onSaved: () => void
}) {
  const [pending, setPending] = useState<number | null | 'busy'>(null)

  const choose = async (hours: number | null) => {
    setPending('busy')
    try {
      await chat.setRetention(conversationUuid, hours)
      onSaved()
    } finally {
      setPending(null)
    }
  }

  return (
    <Modal title="Auto-delete messages" onClose={onClose}>
      <div className="space-y-2">
        <p className="text-sm text-slate-500">
          This applies to everyone in the conversation, and older messages go for good — attachments
          included. Everybody here is told in the thread when it changes.
        </p>
        {RETENTION_CHOICES.map((choice) => {
          const active = (choice.hours ?? null) === current

          return (
            <button
              key={String(choice.hours)}
              type="button"
              disabled={pending === 'busy'}
              onClick={() => choose(choice.hours)}
              className={clsx(
                'flex w-full items-start gap-3 rounded-xl px-3 py-2.5 text-left text-sm',
                active
                  ? 'bg-brand-50 text-brand-700 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/30'
                  : 'bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/60 dark:hover:bg-slate-800',
              )}
            >
              <span className="min-w-0 flex-1">
                <span className="block font-medium">{choice.label}</span>
                <span className="block text-xs text-slate-400">{choice.hint}</span>
              </span>
              {active && <Check className="mt-0.5 size-4 shrink-0" />}
            </button>
          )
        })}
      </div>
    </Modal>
  )
}

/**
 * Who is in this room, what they are in it, and who may take them out.
 *
 * All three used to be one: a list of names. So the group's second admin —
 * appointed by its owner, with every power the badge implies — was drawn
 * exactly like everybody else, and there was no way to remove anybody from
 * here at all. Both are the same omission: the roles were never asked for.
 *
 * Removing is removing from the group, not from the chat. There is no third
 * thing to be a member of, and a chat-only removal would put the person back
 * the next time anybody opened the room.
 */
function MembersModal({
  conversationUuid,
  onClose,
  onChanged,
}: {
  conversationUuid: string
  onClose: () => void
  /** The member count in the header and the list behind it are now wrong. */
  onChanged: () => void
}) {
  const { confirm } = usePrompt()
  const { toast, toastError } = useToast()
  const queryClient = useQueryClient()
  const livePresence = usePresenceMap()
  const [removing, setRemoving] = useState<string | null>(null)

  const { data: members, isLoading } = useQuery({
    queryKey: ['conversation-members', conversationUuid],
    queryFn: () => conversationMembers(conversationUuid),
  })

  const remove = async (m: ConversationMember) => {
    const leaving = m.is_me
    const ok = await confirm({
      title: leaving ? 'Leave this group?' : `Remove ${m.name}?`,
      message: leaving
        ? 'You will lose access to this chat and to the group it belongs to.'
        : `${m.name} will be removed from the group as well as from this chat.`,
      actionLabel: leaving ? 'Leave' : 'Remove',
      danger: true,
    })
    if (!ok) return

    setRemoving(m.uuid)
    try {
      const res = await removeConversationMember(conversationUuid, m.uuid)
      toast(res.message)
      queryClient.invalidateQueries({ queryKey: ['conversation-members', conversationUuid] })
      // The group screens hold the same membership under another name.
      queryClient.invalidateQueries({ queryKey: ['groups'] })
      onChanged()
      if (leaving) onClose()
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setRemoving(null)
    }
  }

  return (
    <Modal title="Members" onClose={onClose}>
      {isLoading ? (
        <SkeletonList rows={4} />
      ) : (
        <div className="space-y-1.5">
          {members?.map((m) => (
            <div key={m.uuid} className="flex items-center gap-2 rounded-lg border border-slate-100 px-3 py-2 text-sm dark:border-slate-800">
              <div className="relative shrink-0">
                <Avatar name={m.name} photoPath={m.photo_path} avatar={m.avatar} size={30} />
                <PresenceDot state={resolvePresence(livePresence, m.uuid, m.presence)} />
              </div>
              <div className="min-w-0 flex-1">
                <p className="truncate font-medium">
                  {m.name}
                  {m.is_me && <span className="ml-1 text-xs font-normal text-slate-400">(you)</span>}
                </p>
                {/* No dot here: this row has an avatar carrying one already,
                    and two dots for one person is worse than none. */}
                <p className="flex items-center gap-1.5 text-xs text-slate-400">
                  {m.username && <span className="truncate">@{m.username}</span>}
                </p>
              </div>
              {/* Owner and admin are the two that carry authority and the two
                  worth a badge. Manager, member and viewer are the ordinary
                  case, and a badge on everybody is a badge on nobody. */}
              {(m.role === 'owner' || m.role === 'admin') && (
                <Badge value={m.role} className="shrink-0 capitalize" />
              )}
              {m.can_remove && (
                <button
                  className="tap shrink-0 rounded p-1 text-slate-400 hover:text-red-600 disabled:opacity-50"
                  title={m.is_me ? 'Leave group' : `Remove ${m.name}`}
                  disabled={removing === m.uuid}
                  onClick={() => remove(m)}
                >
                  <Trash2 className="size-3.5" />
                </button>
              )}
            </div>
          ))}
        </div>
      )}
    </Modal>
  )
}
