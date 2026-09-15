import { useCallback, useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Camera, Check, RefreshCw, RotateCcw, X } from 'lucide-react'
import { hasMultipleCameras, openMedia } from '../lib/devices'

/**
 * Take a photo right here and put it on the message - the WhatsApp camera.
 *
 * The live camera fills the screen; the shutter freezes a frame; Retake goes
 * back, Use puts the picture in the message box as an attachment, where a
 * caption can be typed beside it before it is sent. Nothing leaves the
 * device until Send.
 *
 * A browser that will not open the camera - permission refused, an old phone,
 * a page not served over https - gets the phone's own camera app instead,
 * through a file input that asks for a capture, which every mobile browser
 * honours.
 */
export function CameraCapture({ onCapture, onClose }: {
  onCapture: (file: File) => void
  onClose: () => void
}) {
  const videoRef = useRef<HTMLVideoElement>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const fallbackRef = useRef<HTMLInputElement>(null)

  // A phone starts on the back camera, the way you would point it at
  // something; a laptop has only the one facing you.
  const [facing, setFacing] = useState<'user' | 'environment'>(
    () => (window.matchMedia?.('(pointer: coarse)').matches ? 'environment' : 'user'),
  )
  const [canFlip, setCanFlip] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [starting, setStarting] = useState(true)
  const [shot, setShot] = useState<{ file: File; url: string } | null>(null)

  const stop = useCallback(() => {
    streamRef.current?.getTracks().forEach((t) => t.stop())
    streamRef.current = null
  }, [])

  useEffect(() => {
    if (shot) return undefined
    let cancelled = false

    const start = async () => {
      setStarting(true)
      setError(null)
      stop()
      if (!navigator.mediaDevices?.getUserMedia) {
        setError('This browser cannot open the camera here.')
        setStarting(false)
        return
      }
      try {
        const stream = await openMedia({
          video: { facingMode: { ideal: facing }, width: { ideal: 1920 }, height: { ideal: 1080 } },
          audio: false,
        })
        if (cancelled) {
          stream.getTracks().forEach((t) => t.stop())
          return
        }
        streamRef.current = stream
        if (videoRef.current) {
          videoRef.current.srcObject = stream
          await videoRef.current.play().catch(() => undefined)
        }
        hasMultipleCameras().then((many) => !cancelled && setCanFlip(many)).catch(() => undefined)
      } catch (err) {
        const name = (err as Error)?.name
        setError(
          name === 'NotAllowedError' || name === 'SecurityError'
            ? 'Camera permission was refused. Allow the camera for this site, or use your phone’s camera app.'
            : name === 'NotFoundError' || name === 'OverconstrainedError'
              ? 'No camera was found on this device.'
              : 'The camera could not be opened - another app may be using it.',
        )
      } finally {
        if (!cancelled) setStarting(false)
      }
    }

    void start()

    return () => {
      cancelled = true
      stop()
    }
  }, [facing, shot, stop])

  // The preview image lives in memory until it is used or thrown away.
  useEffect(() => () => { if (shot) URL.revokeObjectURL(shot.url) }, [shot])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const capture = () => {
    const video = videoRef.current
    if (!video || !video.videoWidth) return

    const canvas = document.createElement('canvas')
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight
    const ctx = canvas.getContext('2d')
    if (!ctx) return
    // The preview of the front camera is a mirror, the way a selfie screen
    // is; the photo itself is not, so writing in it reads the right way round.
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height)

    canvas.toBlob((blob) => {
      if (!blob) return
      const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14)
      const file = new File([blob], `photo-${stamp}.jpg`, { type: 'image/jpeg' })
      stop()
      setShot({ file, url: URL.createObjectURL(blob) })
    }, 'image/jpeg', 0.9)
  }

  const use = () => {
    if (!shot) return
    onCapture(shot.file)
  }

  return createPortal(
    <div className="pt-safe pb-safe fixed inset-0 z-[80] flex flex-col bg-black text-white" role="dialog" aria-label="Take a photo">
      <div className="flex items-center justify-between px-3 py-2">
        <button
          type="button"
          onClick={onClose}
          aria-label="Close camera"
          className="tap flex size-11 items-center justify-center rounded-full hover:bg-white/10"
        >
          <X className="size-6" />
        </button>
        <span className="text-sm font-medium">{shot ? 'Send this photo?' : 'Take a photo'}</span>
        {canFlip && !shot ? (
          <button
            type="button"
            onClick={() => setFacing((f) => (f === 'user' ? 'environment' : 'user'))}
            aria-label="Switch camera"
            className="tap flex size-11 items-center justify-center rounded-full hover:bg-white/10"
          >
            <RefreshCw className="size-5" />
          </button>
        ) : <span className="size-11" />}
      </div>

      <div className="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden">
        {shot ? (
          <img src={shot.url} alt="The photo you took" className="max-h-full max-w-full object-contain" />
        ) : (
          <video
            ref={videoRef}
            playsInline
            muted
            autoPlay
            className={`max-h-full max-w-full object-contain ${facing === 'user' ? '-scale-x-100' : ''}`}
          />
        )}

        {!shot && starting && !error && (
          <p className="absolute text-sm text-white/70">Opening the camera…</p>
        )}
        {!shot && error && (
          <div className="absolute mx-6 max-w-sm space-y-3 rounded-2xl bg-white/10 p-5 text-center">
            <p className="text-sm">{error}</p>
            <button
              type="button"
              onClick={() => fallbackRef.current?.click()}
              className="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2 text-sm font-medium text-slate-900"
            >
              <Camera className="size-4" /> Use the camera app
            </button>
          </div>
        )}
      </div>

      <div className="flex items-center justify-center gap-10 px-6 py-5">
        {shot ? (
          <>
            <button
              type="button"
              onClick={() => setShot(null)}
              className="flex flex-col items-center gap-1 text-xs text-white/80"
            >
              <span className="flex size-14 items-center justify-center rounded-full bg-white/15"><RotateCcw className="size-6" /></span>
              Retake
            </button>
            <button
              type="button"
              onClick={use}
              className="flex flex-col items-center gap-1 text-xs text-white/80"
            >
              <span className="flex size-14 items-center justify-center rounded-full bg-emerald-500 text-white"><Check className="size-7" /></span>
              Use photo
            </button>
          </>
        ) : (
          <button
            type="button"
            onClick={capture}
            disabled={starting || !!error}
            aria-label="Take photo"
            className="flex size-[72px] items-center justify-center rounded-full border-4 border-white disabled:opacity-40"
          >
            <span className="size-14 rounded-full bg-white" />
          </button>
        )}
      </div>

      {/* The phone's own camera, when this page cannot open one. */}
      <input
        ref={fallbackRef}
        type="file"
        accept="image/*"
        capture="environment"
        className="hidden"
        onChange={(e) => {
          const file = e.target.files?.[0]
          e.target.value = ''
          if (file) onCapture(file)
        }}
      />
    </div>,
    document.body,
  )
}
