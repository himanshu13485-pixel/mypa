/**
 * "App on app" — keep the meeting floating above every other window while
 * the user works somewhere else.
 *
 * Two mechanisms, best first:
 *  - Document Picture-in-Picture (Chromium 116+): a real always-on-top window
 *    we can put arbitrary DOM in, so everyone's tile stays visible.
 *  - Classic element PiP: one <video> only, so we float the speaker. Safari
 *    and Firefox land here.
 */

export interface PipTile {
  id: string
  name: string
  stream: MediaStream | null
  /** Our own tile must stay muted or we echo ourselves. */
  muted?: boolean
  speaking?: boolean
}

export interface PipSession {
  kind: 'document' | 'video'
  update: (tiles: PipTile[]) => void
  close: () => void
}

interface DocumentPipApi {
  requestWindow: (opts?: { width?: number; height?: number }) => Promise<Window>
  window: Window | null
}

function documentPip(): DocumentPipApi | null {
  return (window as unknown as { documentPictureInPicture?: DocumentPipApi }).documentPictureInPicture ?? null
}

export function pipSupport(): 'document' | 'video' | null {
  if (documentPip()) return 'document'
  if (typeof document !== 'undefined' && document.pictureInPictureEnabled) return 'video'
  return null
}

const PIP_CSS = `
  * { box-sizing: border-box; }
  body {
    margin: 0; background: #020617; color: #f8fafc;
    font: 12px/1.4 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
    height: 100vh; overflow: hidden;
  }
  #grid { display: grid; gap: 4px; padding: 4px; height: 100%; align-content: center; }
  .tile { position: relative; background: #0f172a; border-radius: 8px; overflow: hidden; aspect-ratio: 16 / 9; }
  .tile.speaking { outline: 2px solid #34d399; outline-offset: -2px; }
  .tile video { width: 100%; height: 100%; object-fit: cover; display: block; }
  .tile .name {
    position: absolute; left: 4px; bottom: 4px; max-width: calc(100% - 8px);
    padding: 1px 5px; border-radius: 4px; background: rgba(0,0,0,.6);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .tile .off {
    position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
    font-size: 20px; font-weight: 600; background: #1e293b; color: #94a3b8;
  }
  #empty { display: flex; align-items: center; justify-content: center; height: 100%; color: #64748b; }
`

/** Square-ish grid: 1 col for 1, 2 for 2-4, 3 beyond that. */
function columnsFor(count: number): number {
  if (count <= 1) return 1
  if (count <= 4) return 2
  return 3
}

function renderDocumentPip(doc: Document, tiles: PipTile[]) {
  const grid = doc.getElementById('grid')
  if (!grid) return

  grid.style.gridTemplateColumns = `repeat(${columnsFor(tiles.length)}, minmax(0, 1fr))`

  const seen = new Set<string>()
  for (const tile of tiles) {
    seen.add(tile.id)
    let el = doc.getElementById(`tile-${tile.id}`)
    if (!el) {
      el = doc.createElement('div')
      el.id = `tile-${tile.id}`
      el.className = 'tile'
      el.innerHTML = '<video autoplay playsinline></video><span class="off"></span><span class="name"></span>'
      grid.appendChild(el)
    }

    const video = el.querySelector('video') as HTMLVideoElement
    const off = el.querySelector('.off') as HTMLElement
    const name = el.querySelector('.name') as HTMLElement

    video.muted = !!tile.muted
    if (video.srcObject !== tile.stream) {
      video.srcObject = tile.stream
      video.play().catch(() => undefined)
    }
    // A camera-off peer still sends an audio track, so fall back to an initial.
    const live = !!tile.stream?.getVideoTracks().some((t) => t.enabled && t.readyState === 'live')
    off.style.display = live ? 'none' : 'flex'
    off.textContent = tile.name.charAt(0).toUpperCase()
    name.textContent = tile.name
    el.classList.toggle('speaking', !!tile.speaking)
  }

  // Drop tiles for people who left.
  for (const el of [...grid.children]) {
    if (!seen.has(el.id.replace(/^tile-/, ''))) el.remove()
  }

  const empty = doc.getElementById('empty')
  if (empty) empty.style.display = tiles.length ? 'none' : 'flex'
}

/**
 * Must be called from a user gesture (click) — both APIs require it.
 * `fallbackVideo` is the on-page element to float when only element PiP
 * is available.
 */
export async function openPip(opts: {
  tiles: PipTile[]
  fallbackVideo?: HTMLVideoElement | null
  onClose?: () => void
}): Promise<PipSession | null> {
  const api = documentPip()

  if (api) {
    const count = Math.max(1, opts.tiles.length)
    const cols = columnsFor(count)
    const rows = Math.ceil(count / cols)
    const pipWindow = await api.requestWindow({
      width: Math.min(640, 200 * cols + 16),
      height: Math.min(480, 116 * rows + 16),
    })

    const style = pipWindow.document.createElement('style')
    style.textContent = PIP_CSS
    pipWindow.document.head.appendChild(style)

    const grid = pipWindow.document.createElement('div')
    grid.id = 'grid'
    const empty = pipWindow.document.createElement('div')
    empty.id = 'empty'
    empty.textContent = 'Waiting for video…'
    pipWindow.document.body.append(grid, empty)

    renderDocumentPip(pipWindow.document, opts.tiles)
    pipWindow.addEventListener('pagehide', () => opts.onClose?.())

    return {
      kind: 'document',
      update: (tiles) => {
        if (pipWindow.closed) return
        renderDocumentPip(pipWindow.document, tiles)
      },
      close: () => pipWindow.close(),
    }
  }

  const video = opts.fallbackVideo
  if (!video || !document.pictureInPictureEnabled || video.disablePictureInPicture) return null

  await video.requestPictureInPicture()
  const onLeave = () => {
    video.removeEventListener('leavepictureinpicture', onLeave)
    opts.onClose?.()
  }
  video.addEventListener('leavepictureinpicture', onLeave)

  return {
    kind: 'video',
    // Element PiP mirrors one element; the page keeps its srcObject current,
    // so there is nothing to push here.
    update: () => undefined,
    close: () => {
      document.exitPictureInPicture().catch(() => undefined)
    },
  }
}

/**
 * Stop the screen dimming mid-meeting. Chromium/Safari only; a rejection just
 * means the user's screen behaves as normal.
 */
export async function keepScreenAwake(): Promise<{ release: () => void } | null> {
  const wakeLock = (navigator as Navigator & {
    wakeLock?: { request: (type: 'screen') => Promise<{ release: () => Promise<void> }> }
  }).wakeLock
  if (!wakeLock) return null

  try {
    let sentinel = await wakeLock.request('screen')
    // The lock is dropped whenever the tab is hidden — take it again on return.
    const reacquire = async () => {
      if (document.visibilityState !== 'visible') return
      try {
        sentinel = await wakeLock.request('screen')
      } catch {
        /* denied — nothing to do */
      }
    }
    document.addEventListener('visibilitychange', reacquire)

    return {
      release: () => {
        document.removeEventListener('visibilitychange', reacquire)
        sentinel.release().catch(() => undefined)
      },
    }
  } catch {
    return null
  }
}
