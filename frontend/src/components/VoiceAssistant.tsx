import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { Check, Loader2, Mic, MonitorUp, Phone, Users, Video, X } from 'lucide-react'
import { clsx } from 'clsx'
import { api, errorMessage } from '../api/client'
import {
  bills as billsApi, chat, goals as goalsApi, habits as habitsApi,
  meetings as meetingsApi, tasks as tasksApi,
} from '../api/endpoints'
import { playChime } from '../lib/alerts'
import { useCalls } from './CallManager'
import { Button, Input, Label, Select } from './ui'
import { TASK_PRIORITIES } from '../types'

/* Minimal Web Speech API typings (not in lib.dom for all targets). */
interface SpeechRecognitionLike {
  lang: string
  continuous: boolean
  interimResults: boolean
  onresult: ((event: { results: ArrayLike<ArrayLike<{ transcript: string }>> }) => void) | null
  onerror: ((event: { error: string }) => void) | null
  onend: (() => void) | null
  start: () => void
  stop: () => void
  abort: () => void
}

type SpeechRecognitionCtor = new () => SpeechRecognitionLike

function getRecognizer(): SpeechRecognitionCtor | null {
  const w = window as unknown as {
    SpeechRecognition?: SpeechRecognitionCtor
    webkitSpeechRecognition?: SpeechRecognitionCtor
  }
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null
}

/** A connection the assistant matched against a spoken name. */
interface Candidate {
  uuid: string
  name: string
  username: string | null
  app_id: string | null
}

interface Interpretation {
  intent:
    | 'create_task' | 'complete_task' | 'query_tasks' | 'unknown'
    | 'call_person' | 'message_person' | 'start_meeting' | 'share_screen' | 'navigate'
    | 'create_habit' | 'log_habit' | 'create_goal' | 'create_bill' | 'pay_bill'
  language: string
  transcript: string
  speech: string
  data: {
    // Communication intents
    candidates?: Candidate[]
    person_spoken?: string
    call_type?: 'audio' | 'video'
    text?: string
    people?: { spoken: string; candidates: Candidate[] }[]
    page?: string
    // Life intents
    habit?: { uuid?: string; name: string; frequency?: string; reminder_time?: string } | null
    goal?: { title: string; target_date?: string }
    bill?: { uuid?: string; name: string; amount?: number | null; due_on: string; repeat_frequency?: string; remind_days_before?: number; mark_paid?: boolean }
    heard_name?: string
    // Task intents
    task?: {
      uuid?: string
      title?: string
      status?: string
      due_at?: string
      priority?: string
      is_important?: boolean
      repeat_config?: { frequency: string; interval?: number }
      category_uuid?: string
      category_name?: string
      reminders?: { offset_minutes: number }[]
    } | null
    filters?: Record<string, string | number>
    heard_title?: string
    confidence?: string
  }
}

/** Where "open <page>" commands land. */
const PAGE_ROUTES: Record<string, string> = {
  dashboard: '/dashboard', connections: '/connections', messages: '/messages',
  calls: '/calls', meetings: '/meetings', screen: '/screen', tasks: '/tasks',
  projects: '/projects', notes: '/notes', files: '/files', calendar: '/calendar',
  settings: '/settings', habits: '/habits', goals: '/goals', bills: '/bills',
  reports: '/reports',
}

/**
 * Spoken forms that should count as the wake word. Speech engines rarely
 * transcribe "NV" literally, so its common mis-hearings are included; custom
 * words match on themselves (with an optional "hey" prefix).
 */
function wakeVariants(word: string): string[] {
  const w = word.trim().toLowerCase()
  if (!w) return []
  const variants = new Set([w, w.replace(/\s+/g, '')])
  if (variants.has('nv')) {
    ['envy', 'en v', 'n v', 'en vee', 'envee', 'and v', 'anwe'].forEach((v) => variants.add(v))
  }
  return [...variants]
}

/** Index just after the wake word in the transcript, or -1. */
function findWakeWord(text: string, word: string): number {
  const t = ` ${text.toLowerCase()} `
  let best = -1
  for (const variant of wakeVariants(word)) {
    for (const probe of [` hey ${variant} `, ` ${variant} `, ` ${variant},`]) {
      const i = t.lastIndexOf(probe)
      if (i !== -1) best = Math.max(best, i + probe.length - 1)
    }
  }
  return best === -1 ? -1 : best
}

/** Assistant voice (male/female), shared with the module-level speak(). */
let ttsGender: 'male' | 'female' =
  (localStorage.getItem('mypa-voice-gender') as 'male' | 'female') || 'male'

function pickVoice(language: string): SpeechSynthesisVoice | null {
  try {
    const voices = window.speechSynthesis.getVoices()
    const prefix = language === 'hi' ? 'hi' : 'en'
    const candidates = voices.filter((v) => v.lang.toLowerCase().startsWith(prefix))
    if (!candidates.length) return null

    const femaleHints = ['female', 'woman', 'zira', 'susan', 'heera', 'kalpana', 'swara', 'neerja', 'samantha', 'victoria', 'aria', 'jenny']
    const maleHints = ['male', 'man', 'david', 'mark', 'ravi', 'hemant', 'madhur', 'rishi', 'daniel', 'guy', 'prabhat']
    const hints = ttsGender === 'female' ? femaleHints : maleHints

    return candidates.find((v) => hints.some((h) => v.name.toLowerCase().includes(h))) ?? candidates[0]
  } catch {
    return null
  }
}

function speak(text: string, language: string, queue = false) {
  try {
    const utterance = new SpeechSynthesisUtterance(text)
    utterance.lang = language === 'hi' ? 'hi-IN' : 'en-IN'
    const voice = pickVoice(language)
    if (voice) utterance.voice = voice
    if (!queue) window.speechSynthesis.cancel()
    window.speechSynthesis.speak(utterance)
  } catch {
    // TTS unavailable — silent fallback.
  }
}

export default function VoiceAssistant() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { startCall } = useCalls()
  const [open, setOpen] = useState(false)
  /** Which candidate is picked when a spoken name matched several people. */
  const [picked, setPicked] = useState<Record<string, string>>({})
  const [language, setLanguage] = useState<'en' | 'hi'>(
    () => (localStorage.getItem('mypa-voice-lang') as 'en' | 'hi') ?? 'en',
  )
  const [listening, setListening] = useState(false)
  const [transcript, setTranscript] = useState('')
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState<Interpretation | null>(null)
  const [error, setError] = useState<string | null>(null)
  const recognitionRef = useRef<SpeechRecognitionLike | null>(null)

  // Hands-free wake word ("NV" by default, user-changeable, per device).
  const [wakeEnabled, setWakeEnabled] = useState(() => localStorage.getItem('mypa-voice-wake') === '1')
  const [wakeWord, setWakeWord] = useState(() => localStorage.getItem('mypa-voice-wakeword') || 'NV')
  const wakeRef = useRef<SpeechRecognitionLike | null>(null)

  // Assistant voice + the conversational loop.
  const [voiceGender, setVoiceGender] = useState<'male' | 'female'>(
    () => (localStorage.getItem('mypa-voice-gender') as 'male' | 'female') || 'male',
  )
  const confirmRef = useRef<SpeechRecognitionLike | null>(null)
  /** Set when an action was confirmed by voice: stay open and ask for more. */
  const continueAfterRef = useRef(false)

  const supported = getRecognizer() !== null

  useEffect(() => {
    localStorage.setItem('mypa-voice-wake', wakeEnabled ? '1' : '0')
  }, [wakeEnabled])
  useEffect(() => {
    localStorage.setItem('mypa-voice-wakeword', wakeWord)
  }, [wakeWord])
  useEffect(() => {
    localStorage.setItem('mypa-voice-gender', voiceGender)
    ttsGender = voiceGender
  }, [voiceGender])

  useEffect(() => {
    localStorage.setItem('mypa-voice-lang', language)
  }, [language])

  const interpret = useCallback(async (text: string) => {
    if (!text.trim()) return
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: Interpretation }>('/voice/interpret', {
        transcript: text.trim(),
        language,
      })
      const interpretation = res.data.data
      speak(interpretation.speech, language)

      // Navigation is harmless and reversible — act on it immediately instead
      // of asking for confirmation.
      const route = interpretation.intent === 'navigate' ? PAGE_ROUTES[interpretation.data.page ?? ''] : undefined
      if (route) {
        close()
        navigate(route)
        return
      }

      setResult(interpretation)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }, [language])

  const startListening = () => {
    const Ctor = getRecognizer()
    if (!Ctor) return
    setResult(null)
    setError(null)
    setTranscript('')

    const recognition = new Ctor()
    recognitionRef.current = recognition
    recognition.lang = language === 'hi' ? 'hi-IN' : 'en-IN'
    recognition.continuous = false
    recognition.interimResults = true

    recognition.onresult = (event) => {
      const text = Array.from({ length: event.results.length })
        .map((_, i) => event.results[i][0].transcript)
        .join(' ')
      setTranscript(text)
    }
    recognition.onerror = (event) => {
      setListening(false)
      if (event.error === 'not-allowed') {
        setError('Microphone permission was denied.')
      } else if (event.error !== 'aborted') {
        setError(`Speech recognition error: ${event.error}`)
      }
    }
    recognition.onend = () => {
      setListening(false)
      recognitionRef.current = null
    }

    recognition.start()
    setListening(true)
  }

  const stopListening = (thenInterpret: boolean) => {
    recognitionRef.current?.stop()
    setListening(false)
    if (thenInterpret && transcript.trim()) {
      interpret(transcript)
    }
  }

  // Auto-interpret once recognition finishes with a final transcript.
  useEffect(() => {
    if (!listening && transcript.trim() && !result && !busy && open) {
      // "No / nothing / bas" as the whole reply ends the conversation.
      if (/^\s*(no|nope|nothing|nothing else|cancel|stop|bas|nahi|नहीं|बस|कुछ नहीं|रहने दो)[\s.,!]*$/i.test(transcript)) {
        speak(language === 'hi' ? 'ठीक है।' : 'Okay.', language)
        close()
        return
      }
      interpret(transcript)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [listening])

  // --- Hands-free wake word --------------------------------------------------
  // Runs a continuous background recognizer whenever the panel is closed.
  // Saying the wake word chimes and opens the assistant; saying the wake word
  // followed by a command ("NV call rahul") interprets the command directly.
  const wakeActive = wakeEnabled && supported && !open && !listening && !busy && wakeWord.trim() !== ''
  useEffect(() => {
    if (!wakeActive) {
      wakeRef.current?.abort()
      wakeRef.current = null
      return
    }

    let stopped = false
    const start = () => {
      if (stopped) return
      const Ctor = getRecognizer()
      if (!Ctor) return
      const rec = new Ctor()
      wakeRef.current = rec
      rec.lang = language === 'hi' ? 'hi-IN' : 'en-IN'
      rec.continuous = true
      rec.interimResults = true
      rec.onresult = (event) => {
        const text = Array.from({ length: event.results.length })
          .map((_, i) => event.results[i][0].transcript)
          .join(' ')
        const after = findWakeWord(text, wakeWord)
        if (after === -1) return

        const command = text.slice(after).trim()
        stopped = true
        rec.abort()
        wakeRef.current = null
        playChime()
        setOpen(true)
        if (command.length > 2) {
          setTranscript(command)
          interpret(command)
        } else {
          // Give the mic a moment to free up, then capture the command.
          setTimeout(() => startListening(), 350)
        }
      }
      rec.onerror = (event) => {
        // Without mic permission a restart loop would spin forever.
        if (event.error === 'not-allowed') {
          stopped = true
          setWakeEnabled(false)
          setError('Microphone permission is needed for the wake word.')
        }
      }
      // Browsers end continuous sessions after a while — quietly restart.
      rec.onend = () => {
        if (!stopped) setTimeout(start, 700)
      }
      try {
        rec.start()
      } catch {
        // Another session is still winding down; onend will retry.
      }
    }
    start()

    return () => {
      stopped = true
      wakeRef.current?.abort()
      wakeRef.current = null
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [wakeActive, wakeWord, language])

  const executeCreate = async () => {
    const task = result?.data.task
    if (!task) return
    setBusy(true)
    try {
      await tasksApi.create({
        title: task.title,
        due_at: task.due_at ?? null,
        priority: task.priority ?? 'normal',
        is_important: task.is_important ?? false,
        repeat_config: task.repeat_config ?? null,
        category_uuid: task.category_uuid ?? null,
        reminders: task.reminders ?? [],
      })
      queryClient.invalidateQueries({ queryKey: ['tasks'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      speak(language === 'hi' ? 'टास्क सेव हो गया।' : 'Task saved.', language)
      close()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  const executeComplete = async () => {
    const task = result?.data.task
    if (!task?.uuid) return
    setBusy(true)
    try {
      await tasksApi.setStatus(task.uuid, 'completed')
      queryClient.invalidateQueries({ queryKey: ['tasks'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      speak(language === 'hi' ? 'टास्क पूरा मार्क कर दिया।' : 'Marked as completed.', language)
      close()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  const executeQuery = () => {
    const filters = result?.data.filters ?? {}
    const params = new URLSearchParams()
    Object.entries(filters).forEach(([key, value]) => params.set(key, String(value)))
    close()
    navigate(`/tasks?${params.toString()}`)
  }

  // --- Life intents (habits, goals, bills) -----------------------------------

  /** Create the reviewed habit/goal/bill, invalidate its list, close. */
  const executeLife = async (kind: 'create_habit' | 'create_goal' | 'create_bill' | 'log_habit' | 'pay_bill') => {
    setBusy(true)
    try {
      if (kind === 'create_habit' && result?.data.habit) {
        await habitsApi.create(result.data.habit as Record<string, unknown>)
        queryClient.invalidateQueries({ queryKey: ['habits'] })
        speak(language === 'hi' ? 'आदत बन गई।' : 'Habit created.', language)
      } else if (kind === 'log_habit' && result?.data.habit?.uuid) {
        await habitsApi.log(result.data.habit.uuid)
        queryClient.invalidateQueries({ queryKey: ['habits'] })
        speak(language === 'hi' ? 'आज के लिए पूरी मार्क कर दी।' : 'Marked done for today.', language)
      } else if (kind === 'create_goal' && result?.data.goal) {
        await goalsApi.create(result.data.goal as Record<string, unknown>)
        queryClient.invalidateQueries({ queryKey: ['goals'] })
        speak(language === 'hi' ? 'लक्ष्य बन गया।' : 'Goal created.', language)
      } else if (kind === 'create_bill' && result?.data.bill) {
        const { mark_paid, ...payload } = result.data.bill
        const created = await billsApi.create(payload as Record<string, unknown>)
        if (mark_paid && created.uuid) await billsApi.pay(created.uuid)
        queryClient.invalidateQueries({ queryKey: ['bills'] })
        speak(
          mark_paid
            ? (language === 'hi' ? 'बिल जोड़कर पेड मार्क कर दिया।' : 'Bill added and marked paid.')
            : (language === 'hi' ? 'बिल जुड़ गया।' : 'Bill added.'),
          language,
        )
      } else if (kind === 'pay_bill' && result?.data.bill?.uuid) {
        await billsApi.pay(result.data.bill.uuid)
        queryClient.invalidateQueries({ queryKey: ['bills'] })
        speak(language === 'hi' ? 'बिल पेड मार्क कर दिया।' : 'Bill marked as paid.', language)
      }
      close()
    } catch (err) {
      setError(errorMessage(err))
      setBusy(false)
    }
  }

  const updateLife = (
    field: 'habit' | 'goal' | 'bill',
    patch: Record<string, unknown>,
  ) => {
    setResult((r) =>
      r ? { ...r, data: { ...r.data, [field]: { ...(r.data[field] as object), ...patch } } } : r,
    )
  }

  // --- Communication intents ------------------------------------------------

  /** Start the call the user confirmed (opens the in-app call UI). */
  const executeCall = async (person: Candidate) => {
    if (!person.app_id) return
    setBusy(true)
    try {
      const conversation = await chat.start(person.app_id)
      close()
      await startCall(conversation.uuid, result?.data.call_type ?? 'audio', person.name)
    } catch (err) {
      setError(errorMessage(err))
      setBusy(false)
    }
  }

  /** Send the dictated message (or just open the conversation). */
  const executeMessage = async (person: Candidate) => {
    if (!person.app_id) return
    setBusy(true)
    try {
      const text = result?.data.text?.trim()
      if (text) {
        const conversation = await chat.start(person.app_id)
        await chat.send(conversation.uuid, { body: text })
        queryClient.invalidateQueries({ queryKey: ['conversations'] })
        speak(language === 'hi' ? 'मैसेज भेज दिया।' : 'Message sent.', language)
      }
      close()
      navigate(`/messages?start=${encodeURIComponent(person.app_id)}`)
    } catch (err) {
      setError(errorMessage(err))
      setBusy(false)
    }
  }

  /**
   * Create a meeting or screen session, message the invite link to everyone
   * the user named, and enter the room.
   */
  const executeGathering = async (kind: 'start_meeting' | 'share_screen', invitees: Candidate[]) => {
    setBusy(true)
    try {
      const isScreen = kind === 'share_screen'
      const meeting = await meetingsApi.create(
        isScreen
          ? { is_screen: true, type: 'video', title: 'Screen share' }
          : { type: 'video', title: 'Meeting' },
      )
      const path = isScreen ? `/screen/session/${meeting.code}` : `/meetings/room/${meeting.code}`
      const link = `${window.location.origin}${path}`
      const inviteText = isScreen
        ? `I'm sharing my screen on Netvork — join here: ${link}`
        : `Join my Netvork meeting: ${link}`

      await Promise.all(
        invitees
          .filter((p) => p.app_id)
          .map(async (p) => {
            const conversation = await chat.start(p.app_id!)
            await chat.send(conversation.uuid, { body: inviteText })
          }),
      )

      close()
      navigate(path)
    } catch (err) {
      setError(errorMessage(err))
      setBusy(false)
    }
  }


  const close = () => {
    // When an action was confirmed by voice, the conversation continues:
    // clear the card, ask for the next command, and keep listening.
    if (continueAfterRef.current) {
      continueAfterRef.current = false
      setResult(null)
      setTranscript('')
      setError(null)
      setPicked({})
      speak(language === 'hi' ? 'और कुछ?' : 'Anything else?', language, true)
      setTimeout(() => startListening(), 1500)
      return
    }

    recognitionRef.current?.abort()
    confirmRef.current?.abort()
    confirmRef.current = null
    setOpen(false)
    setListening(false)
    setTranscript('')
    setResult(null)
    setError(null)
    setPicked({})
  }

  const updateTask = (patch: Partial<NonNullable<Interpretation['data']['task']>>) => {
    setResult((r) => (r ? { ...r, data: { ...r.data, task: { ...r.data.task, ...patch } } } : r))
  }

  // --- Conversational confirmation ------------------------------------------
  // While a card is showing, the mic listens for "yes/send/call…", "no/cancel"
  // or "try again", so the whole flow works without touching the screen.

  /** The same action the card's primary button would run. */
  const runPrimaryAction = () => {
    if (!result) return
    switch (result.intent) {
      case 'create_task': executeCreate(); break
      case 'complete_task': if (result.data.task?.uuid) executeComplete(); break
      case 'query_tasks': executeQuery(); break
      case 'call_person':
      case 'message_person': {
        const candidates = result.data.candidates ?? []
        const chosen = candidates.find((c) => c.uuid === (picked.person ?? candidates[0]?.uuid))
        if (chosen) (result.intent === 'call_person' ? executeCall : executeMessage)(chosen)
        break
      }
      case 'start_meeting':
      case 'share_screen': {
        const invitees = (result.data.people ?? [])
          .map((p, i) => p.candidates.find((c) => c.uuid === (picked[`p${i}`] ?? p.candidates[0]?.uuid)))
          .filter((c): c is Candidate => !!c)
        executeGathering(result.intent, invitees)
        break
      }
      case 'create_habit': executeLife('create_habit'); break
      case 'log_habit': if (result.data.habit?.uuid) executeLife('log_habit'); break
      case 'create_goal': executeLife('create_goal'); break
      case 'create_bill': executeLife('create_bill'); break
      case 'pay_bill': if (result.data.bill?.uuid) executeLife('pay_bill'); break
    }
  }

  const handleVoiceReply = (said: string) => {
    const t = ` ${said.toLowerCase().trim()} `
    const isRetry = /(try again|retry|दोबारा|फिर से)/.test(t)
    const isNo = /\s(no|nope|cancel|stop|don'?t|nahi|नहीं|मत|रहने दो|कैंसिल)[\s.,!]/.test(t)
    const isYes = /\s(yes|yeah|yep|ok|okay|confirm|sure|send|call|do it|go ahead|start|pay|save|done|haan|ha|हाँ|हां|ठीक है|ठीक|भेजो|भेज दो|कर दो|करो|हो जाए)[\s.,!]/.test(t)

    if (isRetry) {
      setResult(null)
      setTimeout(() => startListening(), 300)
      return
    }
    if (isNo) {
      continueAfterRef.current = false
      setResult(null)
      speak(language === 'hi' ? 'ठीक है, रहने दिया।' : 'Okay, cancelled.', language)
      return
    }
    if (isYes) {
      // Calls, meetings, screen shares and navigation move the user elsewhere;
      // only the "quiet" actions continue the conversation afterwards.
      continueAfterRef.current = !['call_person', 'start_meeting', 'share_screen', 'query_tasks'].includes(result?.intent ?? '')
      runPrimaryAction()
    }
    // Anything else is ignored — the card stays for buttons or another try.
  }

  useEffect(() => {
    const confirmable = open && !!result && !busy && supported
      && result.intent !== 'unknown' && result.intent !== 'navigate'
    if (!confirmable) {
      confirmRef.current?.abort()
      confirmRef.current = null
      return
    }

    let cancelled = false
    let attempts = 0
    const listen = () => {
      if (cancelled || attempts >= 3) return
      attempts += 1
      const Ctor = getRecognizer()
      if (!Ctor) return
      const rec = new Ctor()
      confirmRef.current = rec
      rec.lang = language === 'hi' ? 'hi-IN' : 'en-IN'
      rec.continuous = false
      rec.interimResults = false
      rec.onresult = (event) => {
        const said = Array.from({ length: event.results.length })
          .map((_, i) => event.results[i][0].transcript)
          .join(' ')
        handleVoiceReply(said)
      }
      rec.onerror = () => undefined
      rec.onend = () => {
        if (confirmRef.current === rec) {
          confirmRef.current = null
          if (!cancelled) setTimeout(listen, 400)
        }
      }
      try {
        rec.start()
      } catch {
        // Mic still busy — the retry via onend covers it.
      }
    }

    // Let the spoken question finish first, so the mic doesn't hear us.
    const startedAt = Date.now()
    const poll = setInterval(() => {
      if (cancelled) {
        clearInterval(poll)
        return
      }
      if (!window.speechSynthesis.speaking || Date.now() - startedAt > 6000) {
        clearInterval(poll)
        listen()
      }
    }, 250)

    return () => {
      cancelled = true
      clearInterval(poll)
      confirmRef.current?.abort()
      confirmRef.current = null
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, result, busy, language])

  return (
    <>
      {/* Floating mic button */}
      <button
        onClick={() => (open ? close() : setOpen(true))}
        className={clsx(
          // Lifted clear of the mobile bottom bar; back to the corner on desktop.
          'fixed bottom-20 right-4 z-40 flex size-13 items-center justify-center rounded-full p-4 shadow-lg transition-colors lg:bottom-5 lg:right-5',
          open ? 'bg-slate-700 text-white' : 'bg-brand-600 text-white hover:bg-brand-700',
        )}
        title={wakeActive ? `Voice assistant — listening for "${wakeWord}"` : 'Voice assistant'}
      >
        {open ? <X className="size-5" /> : <Mic className="size-5" />}
        {wakeActive && (
          <span className="absolute -right-0.5 -top-0.5 size-3 animate-pulse rounded-full bg-emerald-500 ring-2 ring-white dark:ring-slate-900" />
        )}
      </button>

      {open && (
        <div className="fixed bottom-36 right-4 z-40 w-96 max-w-[calc(100vw-2rem)] rounded-xl border border-slate-200 bg-white p-4 shadow-2xl dark:border-slate-700 dark:bg-slate-900 lg:bottom-24 lg:right-5">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="text-sm font-semibold">Voice assistant</h2>
            <div className="flex rounded-lg border border-slate-200 p-0.5 text-xs dark:border-slate-700">
              {(['en', 'hi'] as const).map((lang) => (
                <button
                  key={lang}
                  className={clsx(
                    'rounded-md px-2 py-0.5',
                    language === lang ? 'bg-brand-600 text-white' : 'text-slate-500',
                  )}
                  onClick={() => setLanguage(lang)}
                >
                  {lang === 'en' ? 'EN' : 'हिं'}
                </button>
              ))}
            </div>
          </div>

          {!supported && (
            <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
              Speech recognition isn't available in this browser. Type your command below instead.
            </p>
          )}

          {/* Listening state / manual input */}
          {!result && (
            <div className="space-y-3">
              <div className="flex items-center justify-center py-2">
                <button
                  onClick={() => (listening ? stopListening(true) : startListening())}
                  disabled={!supported || busy}
                  className={clsx(
                    'flex size-16 items-center justify-center rounded-full transition-all',
                    listening
                      ? 'animate-pulse bg-red-500 text-white'
                      : 'bg-brand-50 text-brand-600 hover:bg-brand-100 dark:bg-brand-950',
                    !supported && 'opacity-40',
                  )}
                >
                  {busy ? <Loader2 className="size-6 animate-spin" /> : <Mic className="size-6" />}
                </button>
              </div>
              <p className="text-center text-xs text-slate-400">
                {listening
                  ? language === 'hi' ? 'सुन रही हूँ… बोलिए' : 'Listening… speak now'
                  : language === 'hi' ? 'माइक दबाकर बोलें' : 'Tap the mic and speak'}
              </p>
              <Input
                placeholder={language === 'hi' ? 'या यहाँ लिखें…' : 'Or type a command…'}
                value={transcript}
                onChange={(e) => setTranscript(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && interpret(transcript)}
              />
              <p className="text-[11px] leading-relaxed text-slate-400">
                {language === 'hi'
                  ? 'उदाहरण: “Rahul को कॉल करो” · “मुझे कल शाम 5 बजे दवाई लेना याद दिलाओ” · “मेरे बाकी टास्क दिखाओ”'
                  : 'Try: "Call Rahul" · "Message Priya saying I\'m running late" · "Start a meeting with Rahul" · "Open messages" · "Remind me to call the bank tomorrow at 3 PM"'}
              </p>
              {error && <p className="text-xs text-red-500">{error}</p>}

              {/* Hands-free wake word */}
              <div className="flex items-center justify-between gap-2 border-t border-slate-100 pt-2 dark:border-slate-800">
                <label className={clsx('flex items-center gap-2 text-xs', supported ? 'text-slate-500' : 'text-slate-300')}>
                  <input
                    type="checkbox"
                    checked={wakeEnabled}
                    disabled={!supported}
                    onChange={(e) => setWakeEnabled(e.target.checked)}
                  />
                  {language === 'hi' ? 'बोलकर जगाएँ:' : 'Hands-free — wake on:'}
                </label>
                <Input
                  className="w-24 text-center text-xs"
                  value={wakeWord}
                  maxLength={20}
                  disabled={!supported}
                  onChange={(e) => setWakeWord(e.target.value)}
                  onBlur={() => !wakeWord.trim() && setWakeWord('NV')}
                />
              </div>
              {wakeEnabled && (
                <p className="text-[10px] leading-relaxed text-slate-400">
                  {language === 'hi'
                    ? `टैब खुली रहने पर माइक "${wakeWord}" सुनता रहेगा। "${wakeWord} Rahul को कॉल करो" एक साथ भी बोल सकते हैं।`
                    : `While a Netvork tab is open, the mic listens for "${wakeWord}". You can also say the command in one go: "${wakeWord}, call Rahul".`}
                </p>
              )}

              {/* Assistant voice */}
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs text-slate-500">{language === 'hi' ? 'आवाज़' : 'Voice'}</span>
                <div className="flex rounded-lg border border-slate-200 p-0.5 text-xs dark:border-slate-700">
                  {(['male', 'female'] as const).map((g) => (
                    <button
                      key={g}
                      className={clsx(
                        'rounded-md px-2 py-0.5',
                        voiceGender === g ? 'bg-brand-600 text-white' : 'text-slate-500',
                      )}
                      onClick={() => {
                        setVoiceGender(g)
                        ttsGender = g
                        speak(
                          language === 'hi' ? 'नमस्ते, मैं आपकी सहायक हूँ।' : 'Hi, this is how I sound.',
                          language,
                        )
                      }}
                    >
                      {g === 'male' ? (language === 'hi' ? 'पुरुष' : 'Male') : (language === 'hi' ? 'महिला' : 'Female')}
                    </button>
                  ))}
                </div>
              </div>
            </div>
          )}

          {/* Review card */}
          {result && (
            <div className="space-y-3">
              <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs italic text-slate-500 dark:bg-slate-800">
                “{result.transcript}”
              </p>

              {result.intent === 'create_task' && result.data.task && (
                <>
                  <div>
                    <Label>Task title</Label>
                    <Input
                      value={result.data.task.title ?? ''}
                      onChange={(e) => updateTask({ title: e.target.value })}
                    />
                  </div>
                  <div className="grid grid-cols-2 gap-2">
                    <div>
                      <Label>Due</Label>
                      <Input
                        type="datetime-local"
                        value={result.data.task.due_at ? result.data.task.due_at.replace(' ', 'T').slice(0, 16) : ''}
                        onChange={(e) =>
                          updateTask({ due_at: e.target.value ? e.target.value.replace('T', ' ') + ':00' : undefined })
                        }
                      />
                    </div>
                    <div>
                      <Label>Priority</Label>
                      <Select
                        value={result.data.task.priority ?? 'normal'}
                        onChange={(e) => updateTask({ priority: e.target.value })}
                      >
                        {TASK_PRIORITIES.map((p) => (
                          <option key={p} value={p}>{p}</option>
                        ))}
                      </Select>
                    </div>
                  </div>
                  <div className="flex flex-wrap gap-2 text-xs text-slate-500">
                    {result.data.task.repeat_config && (
                      <span className="rounded-full bg-slate-100 px-2 py-0.5 dark:bg-slate-800">
                        repeats {result.data.task.repeat_config.frequency}
                      </span>
                    )}
                    {result.data.task.category_name && (
                      <span className="rounded-full bg-slate-100 px-2 py-0.5 dark:bg-slate-800">
                        {result.data.task.category_name}
                      </span>
                    )}
                    {result.data.task.reminders?.length ? (
                      <span className="rounded-full bg-slate-100 px-2 py-0.5 dark:bg-slate-800">
                        reminder {result.data.task.reminders[0].offset_minutes > 0
                          ? `${result.data.task.reminders[0].offset_minutes} min before`
                          : 'at due time'}
                      </span>
                    ) : null}
                    <label className="flex items-center gap-1">
                      <input
                        type="checkbox"
                        checked={result.data.task.is_important ?? false}
                        onChange={(e) => updateTask({ is_important: e.target.checked })}
                      />
                      Important
                    </label>
                  </div>
                  <div className="flex justify-end gap-2">
                    <Button variant="secondary" size="sm" onClick={() => setResult(null)}>
                      Try again
                    </Button>
                    <Button size="sm" onClick={executeCreate} disabled={busy}>
                      <Check className="size-3.5" /> {language === 'hi' ? 'सेव करें' : 'Save task'}
                    </Button>
                  </div>
                </>
              )}

              {result.intent === 'complete_task' && (
                <>
                  {result.data.task ? (
                    <>
                      <p className="text-sm">
                        {language === 'hi' ? 'पूरा मार्क करें: ' : 'Mark as completed: '}
                        <span className="font-semibold">{result.data.task.title}</span>?
                      </p>
                      <div className="flex justify-end gap-2">
                        <Button variant="secondary" size="sm" onClick={() => setResult(null)}>
                          {language === 'hi' ? 'नहीं' : 'No'}
                        </Button>
                        <Button size="sm" onClick={executeComplete} disabled={busy}>
                          <Check className="size-3.5" /> {language === 'hi' ? 'हाँ' : 'Yes, complete it'}
                        </Button>
                      </div>
                    </>
                  ) : (
                    <>
                      <p className="text-sm text-slate-500">
                        {language === 'hi'
                          ? `"${result.data.heard_title}" नाम का कोई खुला टास्क नहीं मिला।`
                          : `No open task matching "${result.data.heard_title}" was found.`}
                      </p>
                      <div className="flex justify-end">
                        <Button variant="secondary" size="sm" onClick={() => setResult(null)}>
                          Try again
                        </Button>
                      </div>
                    </>
                  )}
                </>
              )}

              {result.intent === 'query_tasks' && (
                <div className="flex justify-end gap-2">
                  <Button variant="secondary" size="sm" onClick={() => setResult(null)}>
                    Try again
                  </Button>
                  <Button size="sm" onClick={executeQuery}>
                    {language === 'hi' ? 'टास्क दिखाओ' : 'Show tasks'}
                  </Button>
                </div>
              )}

              {(result.intent === 'call_person' || result.intent === 'message_person') && (() => {
                const candidates = result.data.candidates ?? []
                const chosenUuid = picked.person ?? candidates[0]?.uuid
                const chosen = candidates.find((c) => c.uuid === chosenUuid)
                const isCall = result.intent === 'call_person'
                const isVideo = result.data.call_type === 'video'

                if (candidates.length === 0) {
                  return (
                    <>
                      <p className="text-sm text-slate-500">
                        {language === 'hi'
                          ? `"${result.data.person_spoken}" आपके कनेक्शन में नहीं मिला।`
                          : `"${result.data.person_spoken}" isn't in your connections.`}
                      </p>
                      <div className="flex justify-end">
                        <Button variant="secondary" size="sm" onClick={() => setResult(null)}>
                          Try again
                        </Button>
                      </div>
                    </>
                  )
                }

                return (
                  <>
                    {candidates.length > 1 && (
                      <div className="space-y-1">
                        <Label>{language === 'hi' ? 'किसे?' : 'Who did you mean?'}</Label>
                        {candidates.map((c) => (
                          <label
                            key={c.uuid}
                            className="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-sm dark:border-slate-700"
                          >
                            <input
                              type="radio"
                              name="voice-person"
                              checked={chosenUuid === c.uuid}
                              onChange={() => setPicked((p) => ({ ...p, person: c.uuid }))}
                            />
                            <span className="font-medium">{c.name}</span>
                            <span className="text-xs text-slate-400">{c.app_id}</span>
                          </label>
                        ))}
                      </div>
                    )}
                    <p className="text-sm">
                      {isCall ? (
                        <>
                          {language === 'hi'
                            ? `${isVideo ? 'वीडियो ' : ''}कॉल करें: `
                            : `Start ${isVideo ? 'a video' : 'an audio'} call with `}
                          <span className="font-semibold">{chosen?.name}</span>?
                        </>
                      ) : result.data.text ? (
                        <>
                          {language === 'hi' ? 'भेजें: ' : 'Send '}
                          <span className="italic">“{result.data.text}”</span>
                          {language === 'hi' ? ` (${chosen?.name} को)?` : (
                            <> to <span className="font-semibold">{chosen?.name}</span>?</>
                          )}
                        </>
                      ) : (
                        <>
                          {language === 'hi' ? 'चैट खोलें: ' : 'Open the chat with '}
                          <span className="font-semibold">{chosen?.name}</span>?
                        </>
                      )}
                    </p>
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" size="sm" onClick={() => setResult(null)}>
                        {language === 'hi' ? 'नहीं' : 'Cancel'}
                      </Button>
                      <Button
                        size="sm"
                        disabled={busy || !chosen}
                        onClick={() => chosen && (isCall ? executeCall(chosen) : executeMessage(chosen))}
                      >
                        {isCall
                          ? (isVideo ? <Video className="size-3.5" /> : <Phone className="size-3.5" />)
                          : <Check className="size-3.5" />}
                        {isCall
                          ? language === 'hi' ? 'कॉल करें' : 'Call'
                          : result.data.text
                            ? language === 'hi' ? 'भेजें' : 'Send'
                            : language === 'hi' ? 'खोलें' : 'Open chat'}
                      </Button>
                    </div>
                  </>
                )
              })()}

              {(result.intent === 'start_meeting' || result.intent === 'share_screen') && (() => {
                const people = result.data.people ?? []
                const isScreen = result.intent === 'share_screen'
                const rows = people.map((p, i) => ({
                  ...p,
                  index: i,
                  chosen: p.candidates.find(
                    (c) => c.uuid === (picked[`p${i}`] ?? p.candidates[0]?.uuid),
                  ),
                }))
                const invitees = rows.map((r) => r.chosen).filter((c): c is Candidate => !!c)

                return (
                  <>
                    <p className="text-sm">
                      {isScreen
                        ? language === 'hi' ? 'स्क्रीन शेयरिंग शुरू करें?' : 'Start sharing your screen?'
                        : language === 'hi' ? 'मीटिंग शुरू करें?' : 'Start a meeting now?'}
                      {invitees.length > 0 && (
                        <span className="text-slate-500">
                          {' '}{language === 'hi' ? 'इन्हें लिंक भेजा जाएगा:' : 'The invite link will be messaged to:'}
                        </span>
                      )}
                    </p>
                    {rows.map((row) =>
                      row.candidates.length === 0 ? (
                        <p key={row.index} className="text-xs text-amber-600 dark:text-amber-400">
                          {language === 'hi'
                            ? `"${row.spoken}" कनेक्शन में नहीं मिला — इन्हें निमंत्रण नहीं जाएगा।`
                            : `"${row.spoken}" isn't in your connections — they won't be invited.`}
                        </p>
                      ) : row.candidates.length > 1 ? (
                        <div key={row.index}>
                          <Label>“{row.spoken}”</Label>
                          <Select
                            value={row.chosen?.uuid ?? ''}
                            onChange={(e) => setPicked((p) => ({ ...p, [`p${row.index}`]: e.target.value }))}
                          >
                            {row.candidates.map((c) => (
                              <option key={c.uuid} value={c.uuid}>{c.name} ({c.app_id})</option>
                            ))}
                          </Select>
                        </div>
                      ) : (
                        <p key={row.index} className="flex items-center gap-1.5 text-xs text-slate-500">
                          <Users className="size-3.5" /> {row.chosen?.name}
                        </p>
                      ),
                    )}
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" size="sm" onClick={() => setResult(null)}>
                        {language === 'hi' ? 'नहीं' : 'Cancel'}
                      </Button>
                      <Button
                        size="sm"
                        disabled={busy}
                        onClick={() => executeGathering(result.intent as 'start_meeting' | 'share_screen', invitees)}
                      >
                        {isScreen ? <MonitorUp className="size-3.5" /> : <Video className="size-3.5" />}
                        {isScreen
                          ? language === 'hi' ? 'शेयर करें' : 'Start sharing'
                          : language === 'hi' ? 'शुरू करें' : 'Start meeting'}
                      </Button>
                    </div>
                  </>
                )
              })()}

              {result.intent === 'create_habit' && result.data.habit && (
                <>
                  <div className="grid grid-cols-2 gap-2">
                    <div>
                      <Label>Habit</Label>
                      <Input
                        value={result.data.habit.name}
                        onChange={(e) => updateLife('habit', { name: e.target.value })}
                      />
                    </div>
                    <div>
                      <Label>Frequency</Label>
                      <Select
                        value={result.data.habit.frequency ?? 'daily'}
                        onChange={(e) => updateLife('habit', { frequency: e.target.value })}
                      >
                        <option value="daily">daily</option>
                        <option value="weekly">weekly</option>
                        <option value="monthly">monthly</option>
                      </Select>
                    </div>
                  </div>
                  <div>
                    <Label>Reminder time (optional)</Label>
                    <Input
                      type="time"
                      value={result.data.habit.reminder_time ?? ''}
                      onChange={(e) => updateLife('habit', { reminder_time: e.target.value || undefined })}
                    />
                  </div>
                  <div className="flex justify-end gap-2">
                    <Button variant="secondary" size="sm" onClick={() => setResult(null)}>Try again</Button>
                    <Button size="sm" disabled={busy} onClick={() => executeLife('create_habit')}>
                      <Check className="size-3.5" /> {language === 'hi' ? 'आदत बनाएं' : 'Create habit'}
                    </Button>
                  </div>
                </>
              )}

              {result.intent === 'log_habit' && (
                result.data.habit ? (
                  <>
                    <p className="text-sm">
                      {language === 'hi' ? 'आज के लिए पूरी मार्क करें: ' : 'Mark as done for today: '}
                      <span className="font-semibold">{result.data.habit.name}</span>?
                    </p>
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" size="sm" onClick={() => setResult(null)}>
                        {language === 'hi' ? 'नहीं' : 'No'}
                      </Button>
                      <Button size="sm" disabled={busy} onClick={() => executeLife('log_habit')}>
                        <Check className="size-3.5" /> {language === 'hi' ? 'हाँ' : 'Yes, done'}
                      </Button>
                    </div>
                  </>
                ) : (
                  <>
                    <p className="text-sm text-slate-500">
                      {language === 'hi'
                        ? `"${result.data.heard_name}" नाम की कोई आदत नहीं मिली।`
                        : `No habit matching "${result.data.heard_name}" was found.`}
                    </p>
                    <div className="flex justify-end">
                      <Button variant="secondary" size="sm" onClick={() => setResult(null)}>Try again</Button>
                    </div>
                  </>
                )
              )}

              {result.intent === 'create_goal' && result.data.goal && (
                <>
                  <div>
                    <Label>Goal</Label>
                    <Input
                      value={result.data.goal.title}
                      onChange={(e) => updateLife('goal', { title: e.target.value })}
                    />
                  </div>
                  <div>
                    <Label>Target date (optional)</Label>
                    <Input
                      type="date"
                      value={result.data.goal.target_date ?? ''}
                      onChange={(e) => updateLife('goal', { target_date: e.target.value || undefined })}
                    />
                  </div>
                  <div className="flex justify-end gap-2">
                    <Button variant="secondary" size="sm" onClick={() => setResult(null)}>Try again</Button>
                    <Button size="sm" disabled={busy} onClick={() => executeLife('create_goal')}>
                      <Check className="size-3.5" /> {language === 'hi' ? 'लक्ष्य बनाएं' : 'Create goal'}
                    </Button>
                  </div>
                </>
              )}

              {result.intent === 'create_bill' && result.data.bill && (
                <>
                  <div className="grid grid-cols-2 gap-2">
                    <div>
                      <Label>Bill</Label>
                      <Input
                        value={result.data.bill.name}
                        onChange={(e) => updateLife('bill', { name: e.target.value })}
                      />
                    </div>
                    <div>
                      <Label>Amount</Label>
                      <Input
                        type="number"
                        min={0}
                        value={result.data.bill.amount ?? ''}
                        onChange={(e) => updateLife('bill', { amount: e.target.value ? Number(e.target.value) : undefined })}
                      />
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-2">
                    <div>
                      <Label>Due on</Label>
                      <Input
                        type="date"
                        value={result.data.bill.due_on}
                        onChange={(e) => updateLife('bill', { due_on: e.target.value })}
                      />
                    </div>
                    <div>
                      <Label>Repeats</Label>
                      <Select
                        value={result.data.bill.repeat_frequency ?? ''}
                        onChange={(e) => updateLife('bill', { repeat_frequency: e.target.value || undefined })}
                      >
                        <option value="">one-time</option>
                        <option value="weekly">weekly</option>
                        <option value="monthly">monthly</option>
                        <option value="quarterly">quarterly</option>
                        <option value="half_yearly">half-yearly</option>
                        <option value="yearly">yearly</option>
                      </Select>
                    </div>
                  </div>
                  <label className="flex items-center gap-2 text-xs text-slate-500">
                    <input
                      type="checkbox"
                      checked={result.data.bill.mark_paid ?? false}
                      onChange={(e) => updateLife('bill', { mark_paid: e.target.checked || undefined })}
                    />
                    {language === 'hi' ? 'पहले से पेड है (रिकॉर्ड के लिए)' : 'Already paid (record only)'}
                  </label>
                  <div className="flex justify-end gap-2">
                    <Button variant="secondary" size="sm" onClick={() => setResult(null)}>Try again</Button>
                    <Button size="sm" disabled={busy} onClick={() => executeLife('create_bill')}>
                      <Check className="size-3.5" /> {language === 'hi' ? 'बिल जोड़ें' : 'Add bill'}
                    </Button>
                  </div>
                </>
              )}

              {result.intent === 'pay_bill' && (
                result.data.bill ? (
                  <>
                    <p className="text-sm">
                      {language === 'hi' ? 'पेड मार्क करें: ' : 'Mark as paid: '}
                      <span className="font-semibold">{result.data.bill.name}</span>
                      {result.data.bill.amount != null && (
                        <span className="text-slate-500"> · {result.data.bill.amount}</span>
                      )}
                      {result.data.bill.due_on && (
                        <span className="text-slate-500"> · due {result.data.bill.due_on}</span>
                      )}
                      ?
                    </p>
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" size="sm" onClick={() => setResult(null)}>
                        {language === 'hi' ? 'नहीं' : 'No'}
                      </Button>
                      <Button size="sm" disabled={busy} onClick={() => executeLife('pay_bill')}>
                        <Check className="size-3.5" /> {language === 'hi' ? 'हाँ, पेड' : 'Yes, paid'}
                      </Button>
                    </div>
                  </>
                ) : (
                  <>
                    <p className="text-sm text-slate-500">
                      {language === 'hi'
                        ? `"${result.data.heard_name}" नाम का कोई बकाया बिल नहीं मिला।`
                        : `No unpaid bill matching "${result.data.heard_name}" was found.`}
                    </p>
                    <div className="flex justify-end">
                      <Button variant="secondary" size="sm" onClick={() => setResult(null)}>Try again</Button>
                    </div>
                  </>
                )
              )}

              {(result.intent === 'unknown' || result.intent === 'navigate') && (
                <div className="flex justify-end">
                  <Button variant="secondary" size="sm" onClick={() => setResult(null)}>
                    Try again
                  </Button>
                </div>
              )}

              {error && <p className="text-xs text-red-500">{error}</p>}
            </div>
          )}
        </div>
      )}
    </>
  )
}
