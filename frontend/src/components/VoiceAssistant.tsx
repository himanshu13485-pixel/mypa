import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { Check, Loader2, Mic, X } from 'lucide-react'
import { clsx } from 'clsx'
import { api, errorMessage } from '../api/client'
import { tasks as tasksApi } from '../api/endpoints'
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

interface Interpretation {
  intent: 'create_task' | 'complete_task' | 'query_tasks' | 'unknown'
  language: string
  transcript: string
  speech: string
  data: {
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

function speak(text: string, language: string) {
  try {
    const utterance = new SpeechSynthesisUtterance(text)
    utterance.lang = language === 'hi' ? 'hi-IN' : 'en-IN'
    window.speechSynthesis.cancel()
    window.speechSynthesis.speak(utterance)
  } catch {
    // TTS unavailable — silent fallback.
  }
}

export default function VoiceAssistant() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [language, setLanguage] = useState<'en' | 'hi'>(
    () => (localStorage.getItem('mypa-voice-lang') as 'en' | 'hi') ?? 'en',
  )
  const [listening, setListening] = useState(false)
  const [transcript, setTranscript] = useState('')
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState<Interpretation | null>(null)
  const [error, setError] = useState<string | null>(null)
  const recognitionRef = useRef<SpeechRecognitionLike | null>(null)

  const supported = getRecognizer() !== null

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
      setResult(res.data.data)
      speak(res.data.data.speech, language)
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
      interpret(transcript)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [listening])

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

  const close = () => {
    recognitionRef.current?.abort()
    setOpen(false)
    setListening(false)
    setTranscript('')
    setResult(null)
    setError(null)
  }

  const updateTask = (patch: Partial<NonNullable<Interpretation['data']['task']>>) => {
    setResult((r) => (r ? { ...r, data: { ...r.data, task: { ...r.data.task, ...patch } } } : r))
  }

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
        title="Voice assistant"
      >
        {open ? <X className="size-5" /> : <Mic className="size-5" />}
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
                  ? 'उदाहरण: “मुझे कल शाम 5 बजे दवाई लेना याद दिलाओ” · “मेरे बाकी टास्क दिखाओ”'
                  : 'Try: "Remind me to call Rahul tomorrow at 3 PM" · "Show my pending important tasks" · "Mark the doctor appointment task as completed"'}
              </p>
              {error && <p className="text-xs text-red-500">{error}</p>}
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

              {result.intent === 'unknown' && (
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
