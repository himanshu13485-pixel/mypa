import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Cake, Music, VolumeX, X } from 'lucide-react'
import { crm, type CrmMe } from '../../api/crm'

const DISMISS_KEY = 'crm-birthday-banner-dismissed'

/**
 * The birthday vibe: on the person's own birthday the CRM shell turns
 * festive — a celebratory banner, confetti, and the song the Admin picked
 * playing in the background (browsers only allow sound after a click, so
 * the banner offers the play button). Everyone else sees the normal CRM;
 * this day belongs to one person.
 */
export function BirthdayVibes({ me }: { me: CrmMe | undefined }) {
  const isBirthday = !!me?.enabled && !!me?.member?.birthday_today
  const [dismissed, setDismissed] = useState(() => {
    try { return sessionStorage.getItem(DISMISS_KEY) === '1' } catch { return false }
  })
  const [playing, setPlaying] = useState(false)
  const audioRef = useRef<HTMLAudioElement | null>(null)

  const { data: settings } = useQuery({
    queryKey: ['crm', 'birthday-settings'],
    queryFn: crm.masterData.birthdaySettings,
    enabled: isBirthday,
  })

  // Try to start the song straight away; if the browser blocks autoplay the
  // Play button on the banner does it on the first click instead.
  useEffect(() => {
    if (!isBirthday || !settings?.enabled || !settings.song_url || !audioRef.current) return
    audioRef.current.volume = 0.35
    audioRef.current.play().then(() => setPlaying(true)).catch(() => setPlaying(false))
  }, [isBirthday, settings?.enabled, settings?.song_url])

  if (!isBirthday || !settings?.enabled || dismissed) return null

  const toggleSong = () => {
    const audio = audioRef.current
    if (!audio) return
    if (playing) {
      audio.pause()
      setPlaying(false)
    } else {
      audio.volume = 0.35
      audio.play().then(() => setPlaying(true)).catch(() => setPlaying(false))
    }
  }

  const dismiss = () => {
    audioRef.current?.pause()
    setDismissed(true)
    try { sessionStorage.setItem(DISMISS_KEY, '1') } catch { /* fine */ }
  }

  return (
    <>
      {settings.song_url && <audio ref={audioRef} src={settings.song_url} loop />}

      {/* The festive banner riding above every CRM page today. */}
      <div className="pointer-events-none fixed inset-x-0 top-0 z-40 flex justify-center md:pl-60">
        <div className="pointer-events-auto mx-3 mt-2 flex min-w-0 max-w-xl items-center gap-3 rounded-2xl bg-gradient-to-r from-pink-500 via-fuchsia-500 to-amber-400 px-4 py-2.5 text-white shadow-lg">
          <Cake className="size-5 shrink-0" />
          <div className="min-w-0">
            <div className="truncate text-sm font-semibold">
              Happy Birthday{me?.member?.name ? `, ${me.member.name}` : ''}! 🎂🎉
            </div>
            <div className="text-[11px] text-white/85">
              Today the workspace celebrates YOU. Have a wonderful year ahead!
            </div>
          </div>
          {settings.song_url && (
            <button
              onClick={toggleSong}
              title={playing ? 'Pause the birthday song' : 'Play the birthday song'}
              className="shrink-0 rounded-full bg-white/20 p-2 hover:bg-white/30"
            >
              {playing ? <VolumeX className="size-4" /> : <Music className="size-4" />}
            </button>
          )}
          <button onClick={dismiss} aria-label="Dismiss" className="shrink-0 rounded-full p-1.5 hover:bg-white/20">
            <X className="size-4" />
          </button>
        </div>
      </div>

      {/* A little confetti, CSS only — cheerful without being heavy. */}
      <div className="pointer-events-none fixed inset-0 z-30 overflow-hidden" aria-hidden>
        {Array.from({ length: 24 }, (_, i) => (
          <span
            key={i}
            className="absolute block animate-bounce text-lg"
            style={{
              left: `${(i * 41) % 100}%`,
              top: `${(i * 29) % 90}%`,
              animationDelay: `${(i % 7) * 0.35}s`,
              animationDuration: `${2 + (i % 5) * 0.6}s`,
              opacity: 0.5,
            }}
          >
            {['🎈', '🎉', '✨', '🎂', '🎁'][i % 5]}
          </span>
        ))}
      </div>
    </>
  )
}
