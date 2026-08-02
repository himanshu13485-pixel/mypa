/**
 * Client-side meeting/call recording: composites every visible <video> tile
 * inside a container onto a canvas (grid layout, 15 fps) and mixes all audio
 * (mic + every peer) through WebAudio, then records with MediaRecorder.
 * Stopping downloads a .webm into the browser's default Downloads folder.
 */
export interface CompositeRecorder {
  stop: () => void
}

export function startCompositeRecording(opts: {
  container: HTMLElement
  audioStreams: () => MediaStream[]
  fileLabel: string
  onStop?: () => void
}): CompositeRecorder {
  const canvas = document.createElement('canvas')
  canvas.width = 1280
  canvas.height = 720
  const ctx = canvas.getContext('2d')!

  // --- audio mix (poll for new participants while recording) ---------------
  const audioCtx = new AudioContext()
  const dest = audioCtx.createMediaStreamDestination()
  const wired = new Set<string>()
  const wireAudio = () => {
    for (const stream of opts.audioStreams()) {
      if (!stream || wired.has(stream.id)) continue
      const tracks = stream.getAudioTracks()
      if (!tracks.length) continue
      try {
        audioCtx.createMediaStreamSource(new MediaStream(tracks)).connect(dest)
        wired.add(stream.id)
      } catch {
        /* stream ended between poll and wiring */
      }
    }
  }
  wireAudio()

  // --- video composite ------------------------------------------------------
  const draw = () => {
    wireAudio()
    const videos = Array.from(opts.container.querySelectorAll('video')).filter(
      (v) => v.videoWidth > 0 && !v.ended,
    )
    ctx.fillStyle = '#0f172a'
    ctx.fillRect(0, 0, canvas.width, canvas.height)

    if (videos.length) {
      const cols = Math.ceil(Math.sqrt(videos.length))
      const rows = Math.ceil(videos.length / cols)
      const cw = canvas.width / cols
      const ch = canvas.height / rows
      videos.forEach((video, i) => {
        const cx = (i % cols) * cw
        const cy = Math.floor(i / cols) * ch
        // letterbox each tile to preserve aspect
        const scale = Math.min(cw / video.videoWidth, ch / video.videoHeight)
        const w = video.videoWidth * scale
        const h = video.videoHeight * scale
        try {
          ctx.drawImage(video, cx + (cw - w) / 2, cy + (ch - h) / 2, w, h)
        } catch {
          /* video briefly unreadable */
        }
      })
    } else {
      ctx.fillStyle = '#94a3b8'
      ctx.font = '28px sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText('Audio only', canvas.width / 2, canvas.height / 2)
    }

    // REC watermark so the file itself proves it was a recording
    ctx.fillStyle = 'rgba(239,68,68,0.9)'
    ctx.beginPath()
    ctx.arc(28, 28, 8, 0, Math.PI * 2)
    ctx.fill()
    ctx.fillStyle = 'rgba(255,255,255,0.85)'
    ctx.font = 'bold 16px sans-serif'
    ctx.textAlign = 'left'
    ctx.fillText('REC', 44, 34)
  }
  const drawTimer = setInterval(draw, 66) // ~15 fps
  draw()

  const mixed = new MediaStream([
    ...canvas.captureStream(15).getVideoTracks(),
    ...dest.stream.getAudioTracks(),
  ])

  const mime = MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus')
    ? 'video/webm;codecs=vp8,opus'
    : 'video/webm'
  const recorder = new MediaRecorder(mixed, {
    mimeType: mime,
    videoBitsPerSecond: 1_200_000, // ~9 MB/min - good 720p15 quality at half the size
    audioBitsPerSecond: 96_000,
  })
  const chunks: Blob[] = []
  recorder.ondataavailable = (e) => {
    if (e.data.size) chunks.push(e.data)
  }
  recorder.onstop = () => {
    clearInterval(drawTimer)
    audioCtx.close().catch(() => undefined)
    const blob = new Blob(chunks, { type: 'video/webm' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    const stamp = new Date().toISOString().slice(0, 16).replace(/[T:]/g, '-')
    a.href = url
    a.download = `${opts.fileLabel}-${stamp}.webm`
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 10_000)
    opts.onStop?.()
  }
  recorder.start(1000)

  return {
    stop: () => {
      if (recorder.state !== 'inactive') recorder.stop()
    },
  }
}
