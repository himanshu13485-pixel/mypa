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

/**
 * Screen share with the sharer's camera inset in a corner.
 *
 * Sharing sends one video track, so swapping the camera for the screen took
 * the sharer's face off the call entirely — not hidden, not transmitted.
 * Sending both as separate tracks would mean a second transceiver and a
 * renegotiation with every peer in the mesh.
 *
 * Compositing avoids all of that: screen and camera are drawn onto one canvas
 * and captured as a single track, so the connection still carries exactly one
 * video track and nothing about the call setup changes. Everyone sees the
 * screen with the speaker's face on it.
 *
 * The camera is optional — with it off, or unavailable, this is a plain
 * passthrough of the screen and the result is what sharing produced before.
 */
export function createSharePipeline(
  displayTrack: MediaStreamTrack,
  cameraTrack: MediaStreamTrack | null,
): BlurPipeline {
  const screen = document.createElement('video')
  screen.muted = true
  screen.playsInline = true
  screen.srcObject = new MediaStream([displayTrack])
  void screen.play().catch(() => undefined)

  const cam = cameraTrack ? document.createElement('video') : null
  if (cam && cameraTrack) {
    cam.muted = true
    cam.playsInline = true
    cam.srcObject = new MediaStream([cameraTrack])
    void cam.play().catch(() => undefined)
  }

  const settings = displayTrack.getSettings()
  const canvas = document.createElement('canvas')
  canvas.width = settings.width ?? 1280
  canvas.height = settings.height ?? 720
  const ctx = canvas.getContext('2d')!

  let running = true
  let timer: ReturnType<typeof setTimeout> | undefined

  /*
   * A timer, deliberately — not requestAnimationFrame and not
   * requestVideoFrameCallback. Sharing your screen is the one time you are
   * certain to switch away from the meeting tab, and measuring in a hidden
   * tab showed neither of those fires there at all: the share would freeze
   * for everyone the moment the sharer started presenting. Timers keep
   * running while hidden, which is why the blur pipeline above uses one too.
   *
   * Hidden tabs do throttle timers hard — around 1 fps when a page is
   * otherwise idle. A meeting is not idle: it is playing audio throughout,
   * and browsers exempt audible pages from that throttling. Worst case the
   * shared picture slows down; it does not stop.
   */
  const draw = () => {
    if (!running) return
    timer = setTimeout(draw, 66) // ~15 fps

    // A shared window can resize mid-share; follow it so text stays sharp.
    const s = displayTrack.getSettings()
    if (s.width && s.height && (canvas.width !== s.width || canvas.height !== s.height)) {
      canvas.width = s.width
      canvas.height = s.height
    }

    ctx.fillStyle = '#000'
    ctx.fillRect(0, 0, canvas.width, canvas.height)

    // The screen is the point of the share — fit it whole, never crop.
    if (screen.videoWidth) {
      const scale = Math.min(canvas.width / screen.videoWidth, canvas.height / screen.videoHeight)
      const w = screen.videoWidth * scale
      const h = screen.videoHeight * scale
      ctx.drawImage(screen, (canvas.width - w) / 2, (canvas.height - h) / 2, w, h)
    }

    // Camera inset: a quarter of the width, bottom-right, with a margin.
    if (cam?.videoWidth) {
      const iw = Math.round(canvas.width * 0.22)
      const ih = Math.round((iw * cam.videoHeight) / cam.videoWidth)
      const pad = Math.round(canvas.width * 0.015)
      const x = canvas.width - iw - pad
      const y = canvas.height - ih - pad

      ctx.save()
      ctx.shadowColor = 'rgba(0,0,0,0.5)'
      ctx.shadowBlur = Math.round(canvas.width * 0.008)
      ctx.fillStyle = '#0f172a'
      ctx.fillRect(x, y, iw, ih)
      ctx.restore()

      ctx.drawImage(cam, x, y, iw, ih)
      ctx.strokeStyle = 'rgba(255,255,255,0.35)'
      ctx.lineWidth = Math.max(1, Math.round(canvas.width * 0.0015))
      ctx.strokeRect(x, y, iw, ih)
    }
  }
  // Starts the repeating draw. The first pass runs before the video has
  // metadata and paints only black; the loop is what corrects that, so this
  // must repeat rather than fire once.
  draw()

  // Screens are mostly static, so a lower rate keeps text legible without
  // spending the bitrate a camera needs.
  const track = canvas.captureStream(15).getVideoTracks()[0]

  return {
    track,
    stop: () => {
      running = false
      if (timer) clearTimeout(timer)
      track.stop()
      screen.srcObject = null
      if (cam) cam.srcObject = null
    },
  }
}
