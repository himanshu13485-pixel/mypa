import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Music, PartyPopper, Send, VolumeX, X } from 'lucide-react'
import { api } from '../../api/client'
import { type CrmMe } from '../../api/crm'
import { Button, Input, Modal } from '../../components/ui'

interface CelebrationToday {
  festival: { name: string; color: string; song_url: string | null } | null
  birthdays: string[]
}

interface WishRow { occasion: string; message: string; created_at: string; name: string | null }

const DISMISS_KEY = 'crm-festival-banner-dismissed'

/**
 * Festival vibes: on each occasion the Admin switched on (from the HR
 * Policy's own holiday calendar) the CRM turns festive for EVERYONE — the
 * festival's colour, its song, and the wishes wall where people wish each
 * other from the front-end, history kept per occasion.
 */
export function FestivalVibes({ me }: { me: CrmMe | undefined }) {
  const queryClient = useQueryClient()
  const [dismissed, setDismissed] = useState(() => {
    try { return sessionStorage.getItem(DISMISS_KEY) === new Date().toDateString() } catch { return false }
  })
  const [showWishes, setShowWishes] = useState(false)
  const [playing, setPlaying] = useState(false)
  const [message, setMessage] = useState('')
  const audioRef = useRef<HTMLAudioElement | null>(null)

  const { data } = useQuery({
    queryKey: ['crm', 'celebration-today'],
    queryFn: () => api.get<{ data: CelebrationToday }>('/crm/celebration-today').then((r) => r.data.data),
    enabled: !!me?.enabled,
    staleTime: 10 * 60_000,
  })
  const festival = data?.festival ?? null
  const occasion = festival?.name ?? ''

  const { data: wishes } = useQuery({
    queryKey: ['crm', 'wishes', occasion],
    queryFn: () => api.get<{ data: WishRow[] }>('/crm/wishes', { params: { occasion } }).then((r) => r.data.data),
    enabled: showWishes && !!occasion,
  })

  const sendWish = useMutation({
    mutationFn: () => api.post('/crm/wishes', { occasion, message }),
    onSuccess: () => {
      setMessage('')
      queryClient.invalidateQueries({ queryKey: ['crm', 'wishes', occasion] })
    },
  })

  useEffect(() => {
    if (!festival?.song_url || !audioRef.current) return
    audioRef.current.volume = 0.3
    audioRef.current.play().then(() => setPlaying(true)).catch(() => setPlaying(false))
  }, [festival?.song_url])

  if (!festival || dismissed) return null

  const dismiss = () => {
    audioRef.current?.pause()
    setDismissed(true)
    try { sessionStorage.setItem(DISMISS_KEY, new Date().toDateString()) } catch { /* fine */ }
  }

  const toggleSong = () => {
    const a = audioRef.current
    if (!a) return
    if (playing) { a.pause(); setPlaying(false) } else { a.volume = 0.3; a.play().then(() => setPlaying(true)).catch(() => {}) }
  }

  return (
    <>
      {festival.song_url && <audio ref={audioRef} src={festival.song_url} loop />}

      {/* The festival's own colour paints the banner for everyone. */}
      <div className="pointer-events-none fixed inset-x-0 top-0 z-40 flex justify-center md:pl-60">
        <div
          className="pointer-events-auto mx-3 mt-2 flex min-w-0 max-w-xl items-center gap-3 rounded-2xl px-4 py-2.5 text-white shadow-lg"
          style={{ background: `linear-gradient(100deg, ${festival.color}, ${festival.color}cc)` }}
        >
          <PartyPopper className="size-5 shrink-0" />
          <div className="min-w-0">
            <div className="truncate text-sm font-semibold">Happy {festival.name}! ✨</div>
            <div className="text-[11px] text-white/85">The whole workspace celebrates today.</div>
          </div>
          <Button size="sm" variant="secondary" onClick={() => setShowWishes(true)}>
            <Send className="size-3.5" /> Wishes
          </Button>
          {festival.song_url && (
            <button onClick={toggleSong} title={playing ? 'Pause the song' : 'Play the song'} className="shrink-0 rounded-full bg-white/20 p-2 hover:bg-white/30">
              {playing ? <VolumeX className="size-4" /> : <Music className="size-4" />}
            </button>
          )}
          <button onClick={dismiss} aria-label="Dismiss" className="shrink-0 rounded-full p-1.5 hover:bg-white/20">
            <X className="size-4" />
          </button>
        </div>
      </div>

      {showWishes && (
        <Modal title={`${festival.name} — wishes wall`} onClose={() => setShowWishes(false)}>
          <div className="space-y-3">
            <form
              className="flex items-center gap-2"
              onSubmit={(e) => { e.preventDefault(); if (message.trim()) sendWish.mutate() }}
            >
              <Input value={message} onChange={(e) => setMessage(e.target.value)} placeholder={`Wish everyone a happy ${festival.name}…`} className="w-full" />
              <Button type="submit" disabled={!message.trim() || sendWish.isPending}>
                <Send className="size-4" />
              </Button>
            </form>
            <ul className="max-h-72 space-y-2 overflow-y-auto">
              {(wishes ?? []).length === 0 ? (
                <li className="py-6 text-center text-sm text-slate-400">Be the first to wish! 🎉</li>
              ) : (
                (wishes ?? []).map((w, i) => (
                  <li key={i} className="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                    <div className="text-sm text-slate-700 dark:text-slate-200">{w.message}</div>
                    <div className="mt-0.5 text-[11px] text-slate-400">— {w.name ?? 'someone'} · {String(w.created_at).slice(0, 16).replace('T', ' ')}</div>
                  </li>
                ))
              )}
            </ul>
          </div>
        </Modal>
      )}
    </>
  )
}
