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

export async function createBlurredTrack(cameraTrack: MediaStreamTrack): Promise<BlurPipeline> {
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
    // sharp person
    ctx.drawImage(results.segmentationMask, 0, 0, canvas.width, canvas.height)
    ctx.globalCompositeOperation = 'source-in'
    ctx.drawImage(results.image, 0, 0, canvas.width, canvas.height)
    // blurred background behind it
    ctx.globalCompositeOperation = 'destination-over'
    ctx.filter = 'blur(14px)'
    ctx.drawImage(results.image, 0, 0, canvas.width, canvas.height)
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
