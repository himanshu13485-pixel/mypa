import { lazy, type ComponentType, type LazyExoticComponent } from 'react'
import { reportError } from './report'

/**
 * A lazily-loaded route that survives a deploy.
 *
 * Every page in this app is a separate hashed file fetched on demand. Ship a
 * new build and those filenames change; a browser still holding the previous
 * index.html then asks for a chunk that no longer exists, the import rejects,
 * and the whole screen is replaced by an error — for somebody whose only
 * mistake was leaving the tab open since yesterday. It reads as "the app is
 * broken" and it is entirely self-inflicted.
 *
 * So: try again once in case it was a blip, and if it fails again, throw away
 * the cached shell and reload. The fresh index.html names the chunks that
 * actually exist and the page they asked for opens. The rate limit below is
 * what stops that becoming a reload loop when the file is genuinely missing
 * rather than merely renamed.
 */
const LAST_RELOAD = 'mypa-chunk-reload-at'
/** Long enough that a loop is impossible, short enough to allow the next deploy. */
const RELOAD_COOLDOWN_MS = 30_000

/**
 * The shapes browsers use to say "that module would not load".
 *
 * Worth being generous: the wording differs per engine and per cause. Chrome
 * says "Failed to fetch dynamically imported module" on a 404 and "error
 * loading dynamically imported module" when what came back was not JavaScript
 * — which is what a server handing the SPA shell to a script request produces.
 * Firefox and Safari phrase it differently again.
 */
export function isChunkLoadFailure(err: unknown): boolean {
  const message = err instanceof Error ? err.message : String(err)

  return /dynamically imported module|Importing a module script failed|Failed to fetch|ChunkLoadError|error loading/i
    .test(message)
}

function recentlyReloaded(): boolean {
  try {
    const at = Number(sessionStorage.getItem(LAST_RELOAD) ?? 0)
    return Date.now() - at < RELOAD_COOLDOWN_MS
  } catch {
    // Private mode with no sessionStorage: refusing to reload is the safe
    // half of the trade — an error screen beats a loop.
    return true
  }
}

/**
 * Drop everything that could still be pointing at the old build.
 *
 * The service worker caches /assets/* forever on purpose — hashed names are
 * immutable — which also means a bad entry stays bad. Unregistering it as well
 * means the reload fetches the shell from the network; the next load registers
 * it again from scratch.
 */
async function discardCachedShell(): Promise<void> {
  try {
    if ('caches' in window) {
      const keys = await caches.keys()
      await Promise.all(keys.map((k) => caches.delete(k)))
    }
    const reg = await navigator.serviceWorker?.getRegistration?.()
    await reg?.unregister()
  } catch {
    /* nothing here is worth failing the reload over */
  }
}

/** Every route here is a page: it renders from the URL and takes no props. */
type RouteComponent = ComponentType<Record<string, never>>

/** A lazy route that can also be fetched before anyone asks for it. */
export type PreloadableRoute = LazyExoticComponent<RouteComponent> & {
  preload: () => void
}

export function lazyRoute(
  name: string,
  load: () => Promise<{ default: RouteComponent }>,
): PreloadableRoute {
  const component = lazy(async () => {
    try {
      return await load()
    } catch (first) {
      if (!isChunkLoadFailure(first)) throw first

      // A blip — a flaky connection, a proxy hiccup — costs one retry.
      try {
        return await load()
      } catch (second) {
        if (recentlyReloaded()) {
          // Already tried the reload and it still will not load, so this is a
          // real fault rather than a stale shell. Say so, and let the error
          // boundary show it.
          reportError(second, `chunk ${name} missing after reload`)
          throw second
        }

        reportError(second, `chunk ${name} stale — reloading`)
        try {
          sessionStorage.setItem(LAST_RELOAD, String(Date.now()))
        } catch {
          /* handled by recentlyReloaded above */
        }
        await discardCachedShell()
        window.location.reload()

        // The reload is in flight; resolving would flash the old page first.
        return await new Promise<{ default: RouteComponent }>(() => undefined)
      }
    }
  }) as PreloadableRoute

  /*
   * Fetch the chunk without rendering it.
   *
   * Every page is its own file, so the first visit to each one waits on a
   * network round trip — and because that wait suspends, the screen changes
   * twice: once to a placeholder, once to the page. Doing it while the app is
   * idle means the file is usually already in memory when somebody clicks,
   * and the navigation is a single instant repaint.
   *
   * Failures are ignored on purpose. This is speculative work; if it does not
   * land, the ordinary load path runs later and handles its own errors.
   */
  component.preload = () => {
    void load().catch(() => undefined)
  }

  return component
}
