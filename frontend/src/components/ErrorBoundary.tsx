import { Component, type ErrorInfo, type ReactNode } from 'react'
import { Button, Card } from './ui'
import { reportError } from '../lib/report'

interface Props {
  children: ReactNode
}

interface State {
  error: Error | null
}

/**
 * A render error anywhere below this used to unmount the whole tree and leave
 * a blank white page — indistinguishable, to the person looking at it, from
 * the app having lost their data. This shows what happened and offers a way
 * out that does not involve knowing to press F5.
 */
export default class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('[ui] render failed', error, info.componentStack)
    // The component stack is the useful half: a minified frame number says
    // nothing, "in MeetingRoomPage" says where to look.
    reportError(error, `render${info.componentStack ? ' in' + firstComponent(info.componentStack) : ''}`)
  }

  render() {
    if (!this.state.error) return this.props.children

    // A page that would not download is almost always a browser holding the
    // previous build after a deploy, not anything broken. lazyRoute reloads by
    // itself; if that did not take, saying "something broke" is both wrong and
    // useless — the person needs a button that clears the old copy out.
    const stale = isStaleBuild(this.state.error)

    return (
      <div className="flex min-h-dvh items-center justify-center p-4">
        <Card className="w-full max-w-md text-center">
          <h1 className="text-base font-semibold">
            {stale ? 'Netvork has been updated' : 'Something broke on this screen'}
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {stale
              ? 'This tab is still running the previous version, so part of the app would not load. Reloading picks up the new one — nothing is lost.'
              : 'Your data is safe — this is a display problem on our side.'}
          </p>
          {!stale && (
            <p className="mt-3 break-words rounded-lg bg-slate-100 px-3 py-2 text-left font-mono text-[11px] text-slate-500 dark:bg-slate-800 dark:text-slate-400">
              {this.state.error.message || String(this.state.error)}
            </p>
          )}
          <div className="mt-4 flex justify-center gap-2">
            {stale ? (
              <Button onClick={() => void reloadFresh()}>Reload</Button>
            ) : (
              <>
                <Button onClick={() => this.setState({ error: null })}>Try again</Button>
                <Button variant="secondary" onClick={() => window.location.assign('/')}>
                  Back to dashboard
                </Button>
              </>
            )}
          </div>
        </Card>
      </div>
    )
  }
}

/** A page that would not download, rather than a page that threw. */
function isStaleBuild(error: Error): boolean {
  return /dynamically imported module|Importing a module script failed|ChunkLoadError/i
    .test(error.message ?? '')
}

/** Reload with nothing left over from the build that just went missing. */
async function reloadFresh(): Promise<void> {
  try {
    if ('caches' in window) {
      const keys = await caches.keys()
      await Promise.all(keys.map((k) => caches.delete(k)))
    }
    const reg = await navigator.serviceWorker?.getRegistration?.()
    await reg?.unregister()
  } catch {
    /* the reload is the point; the cleanup is a bonus */
  }
  window.location.reload()
}

/** The topmost named component in a React stack, e.g. " MeetingRoomPage". */
function firstComponent(stack: string): string {
  const line = stack.split('\n').find((l) => l.trim().startsWith('at '))

  return line ? ' ' + line.trim().replace(/^at\s+/, '').split(/[\s(]/)[0] : ''
}
