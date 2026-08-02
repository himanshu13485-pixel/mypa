/**
 * Background blur via MediaPipe person-segmentation: the camera frame is
 * split into person vs background, the background is blurred, and the
 * composite is emitted as a replacement video track.
 *
 * The model (~250 KB wasm + weights) loads from the MediaPipe CDN on first
 * use, so blur needs an internet connection the first time.
 */
export interface BlurPipeline {
  track: MediaStreamTrack
  stop: () => void
}

/* The MediaPipe bundle is UMD-only (no ESM exports), so it is loaded from the
   CDN at runtime and exposes window.SelfieSegmentation. */
let segCtorPromise: Promise<new (config: { locateFile: (f: string) => string }) => SelfieSegmentationLike> | null = null

interface SelfieSegmentationLike {
  setOptions(o: { modelSelection: number }): void
  onResults(cb: (r: { image: CanvasImageSource; segmentationMask: CanvasImageSource }) => void): void
  send(i: { image: HTMLVideoElement }): Promise<void>
  close(): Promise<void>
}

function loadSelfieSegmentation() {
  if (!segCtorPromise) {
    segCtorPromise = new Promise((resolve, reject) => {
      const w = window as unknown as { SelfieSegmentation?: new (c: { locateFile: (f: string) => string }) => SelfieSegmentationLike }
      if (w.SelfieSegmentation) return resolve(w.SelfieSegmentation)
      const s = document.createElement('script')
      s.src = 'https://cdn.jsdelivr.net/npm/@mediapipe/selfie_segmentation/selfie_segmentation.js'
      s.onload = () => (w.SelfieSegmentation ? resolve(w.SelfieSegmentation) : reject(new Error('SelfieSegmentation missing after load')))
      s.onerror = () => {
        segCtorPromise = null
        reject(new Error('Could not load the segmentation model (no internet?)'))
      }
      document.head.appendChild(s)
    })
  }
  return segCtorPromise
}

/** Draw an image covering the canvas without distortion (center-crop). */
function drawCover(ctx: CanvasRenderingContext2D, img: CanvasImageSource, w: number, h: number) {
  const iw = (img as HTMLImageElement).naturalWidth ?? (img as HTMLCanvasElement).width ?? w
  const ih = (img as HTMLImageElement).naturalHeight ?? (img as HTMLCanvasElement).height ?? h
  const scale = Math.max(w / iw, h / ih)
  const dw = iw * scale
  const dh = ih * scale
  ctx.drawImage(img, (w - dw) / 2, (h - dh) / 2, dw, dh)
}

export type BackgroundEffect =
  | { type: 'blur' }
  | { type: 'image'; image: CanvasImageSource }

/** Built-in virtual backgrounds drawn at runtime (no image assets needed). */
export function presetBackground(kind: 'office' | 'sunset' | 'forest' | 'night'): CanvasImageSource {
  const c = document.createElement('canvas')
  c.width = 1280
  c.height = 720
  const g = c.getContext('2d')!
  const grad = g.createLinearGradient(0, 0, 0, c.height)
  const stops: Record<string, [string, string, string]> = {
    office: ['#e2e8f0', '#cbd5e1', '#94a3b8'],
    sunset: ['#fbbf24', '#f97316', '#7c2d12'],
    forest: ['#bbf7d0', '#22c55e', '#14532d'],
    night: ['#1e293b', '#0f172a', '#020617'],
  }
  const [a, b, d] = stops[kind]
  grad.addColorStop(0, a)
  grad.addColorStop(0.6, b)
  grad.addColorStop(1, d)
  g.fillStyle = grad
  g.fillRect(0, 0, c.width, c.height)
  if (kind === 'night') {
    g.fillStyle = 'rgba(255,255,255,0.8)'
    for (let i = 0; i < 80; i++) {
      g.beginPath()
      g.arc(Math.random() * c.width, Math.random() * c.height * 0.7, Math.random() * 1.5, 0, Math.PI * 2)
      g.fill()
    }
  }
  if (kind === 'office') {
    // soft vignette instead of fake windows - reads as a studio wall
    const v = g.createRadialGradient(c.width / 2, c.height / 2, c.height / 4, c.width / 2, c.height / 2, c.width / 1.1)
    v.addColorStop(0, 'rgba(255,255,255,0.25)')
    v.addColorStop(1, 'rgba(0,0,0,0.25)')
    g.fillStyle = v
    g.fillRect(0, 0, c.width, c.height)
  }
  return c
}

export async function loadImageBackground(file: File): Promise<CanvasImageSource> {
  const url = URL.createObjectURL(file)
  const img = new Image()
  await new Promise((res, rej) => {
    img.onload = res
    img.onerror = rej
    img.src = url
  })
  return img
}

export async function createEffectTrack(cameraTrack: MediaStreamTrack, effect: BackgroundEffect): Promise<BlurPipeline> {
  const SelfieSegmentation = await loadSelfieSegmentation()
  const settings = cameraTrack.getSettings()
  const width = settings.width ?? 1280
  const height = settings.height ?? 720

  const video = document.createElement('video')
  video.muted = true
  video.playsInline = true
  video.srcObject = new MediaStream([cameraTrack])
  await video.play()

  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d')!

  const seg = new SelfieSegmentation({
    locateFile: (f) => `https://cdn.jsdelivr.net/npm/@mediapipe/selfie_segmentation/${f}`,
  })
  seg.setOptions({ modelSelection: 1 })

  let running = true
  seg.onResults((results) => {
    if (!running) return
    ctx.save()
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    // person mask, slightly feathered so edges blend instead of halo-ing
    ctx.filter = 'blur(4px)'
    ctx.drawImage(results.segmentationMask, 0, 0, canvas.width, canvas.height)
    ctx.filter = 'none'
    ctx.globalCompositeOperation = 'source-in'
    ctx.drawImage(results.image, 0, 0, canvas.width, canvas.height)
    // background behind it: blurred camera frame OR the virtual image
    ctx.globalCompositeOperation = 'destination-over'
    if (effect.type === 'blur') {
      ctx.filter = 'blur(14px)'
      ctx.drawImage(results.image, 0, 0, canvas.width, canvas.height)
      ctx.filter = 'none'
    } else {
      drawCover(ctx, effect.image, canvas.width, canvas.height)
    }
    ctx.restore()
  })

  const pump = async () => {
    while (running) {
      if (video.readyState >= 2) {
        try {
          await seg.send({ image: video })
        } catch {
          /* dropped frame */
        }
      }
      await new Promise((r) => setTimeout(r, 50)) // ~20 fps — CPU-friendly
    }
  }
  void pump()

  const track = canvas.captureStream(20).getVideoTracks()[0]

  return {
    track,
    stop: () => {
      running = false
      track.stop()
      video.srcObject = null
      seg.close().catch(() => undefined)
    },
  }
}

/** Back-compat wrapper: plain background blur. */
export function createBlurredTrack(cameraTrack: MediaStreamTrack): Promise<BlurPipeline> {
  return createEffectTrack(cameraTrack, { type: 'blur' })
}
