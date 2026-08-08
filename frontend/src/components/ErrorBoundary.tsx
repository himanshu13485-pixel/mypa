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

    return (
      <div className="flex min-h-dvh items-center justify-center p-4">
        <Card className="w-full max-w-md text-center">
          <h1 className="text-base font-semibold">Something broke on this screen</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Your data is safe — this is a display problem on our side.
          </p>
          <p className="mt-3 break-words rounded-lg bg-slate-100 px-3 py-2 text-left font-mono text-[11px] text-slate-500 dark:bg-slate-800 dark:text-slate-400">
            {this.state.error.message || String(this.state.error)}
          </p>
          <div className="mt-4 flex justify-center gap-2">
            <Button onClick={() => this.setState({ error: null })}>Try again</Button>
            <Button variant="secondary" onClick={() => window.location.assign('/')}>
              Back to dashboard
            </Button>
          </div>
        </Card>
      </div>
    )
  }
}

/** The topmost named component in a React stack, e.g. " MeetingRoomPage". */
function firstComponent(stack: string): string {
  const line = stack.split('\n').find((l) => l.trim().startsWith('at '))

  return line ? ' ' + line.trim().replace(/^at\s+/, '').split(/[\s(]/)[0] : ''
}
