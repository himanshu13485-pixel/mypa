import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { AlertTriangle, CheckCircle2, Info, X } from 'lucide-react'
import { clsx } from 'clsx'

type ToastKind = 'error' | 'success' | 'info'

interface Toast {
  id: number
  kind: ToastKind
  message: string
}

interface ToastApi {
  toast: (message: string, kind?: ToastKind) => void
  /** Shorthand for the overwhelmingly common case. */
  toastError: (message: string) => void
}

const ToastContext = createContext<ToastApi | null>(null)

const STYLES: Record<ToastKind, { wrap: string; Icon: typeof Info }> = {
  error: {
    wrap: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200',
    Icon: AlertTriangle,
  },
  success: {
    wrap: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
    Icon: CheckCircle2,
  },
  info: {
    wrap: 'border-slate-200 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200',
    Icon: Info,
  },
}

/**
 * Replaces window.alert() for anything the user did not explicitly ask to be
 * interrupted by. A native dialog steals focus, cannot be styled, and on a
 * phone drops a system modal over whatever you were doing — including, until
 * now, over a live video call.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])

  const dismiss = useCallback((id: number) => {
    setToasts((t) => t.filter((x) => x.id !== id))
  }, [])

  const toast = useCallback((message: string, kind: ToastKind = 'info') => {
    // Date.now() collides when two fire in the same millisecond; the random
    // suffix keeps React keys unique.
    const id = Date.now() + Math.random()
    setToasts((t) => [...t, { id, kind, message }])
    setTimeout(() => dismiss(id), kind === 'error' ? 6000 : 3500)
  }, [dismiss])

  const api = useMemo<ToastApi>(() => ({
    toast,
    toastError: (message: string) => toast(message, 'error'),
  }), [toast])

  return (
    <ToastContext.Provider value={api}>
      {children}
      {createPortal(
        <div className="pointer-events-none fixed inset-x-0 bottom-0 z-[100] flex flex-col items-center gap-2 p-4 pb-safe sm:bottom-auto sm:right-0 sm:top-0 sm:items-end">
          {toasts.map((t) => {
            const { wrap, Icon } = STYLES[t.kind]
            return (
              <div
                key={t.id}
                role={t.kind === 'error' ? 'alert' : 'status'}
                className={clsx(
                  'pointer-events-auto flex w-full max-w-sm items-start gap-2 rounded-xl border px-3 py-2.5 text-sm shadow-lg',
                  wrap,
                )}
              >
                <Icon className="mt-0.5 size-4 shrink-0" />
                <span className="min-w-0 flex-1">{t.message}</span>
                <button
                  className="tap shrink-0 rounded p-0.5 opacity-60 hover:opacity-100"
                  aria-label="Dismiss"
                  onClick={() => dismiss(t.id)}
                >
                  <X className="size-4" />
                </button>
              </div>
            )
          })}
        </div>,
        document.body,
      )}
    </ToastContext.Provider>
  )
}

export function useToast(): ToastApi {
  const ctx = useContext(ToastContext)
  if (!ctx) throw new Error('useToast must be used inside <ToastProvider>')
  return ctx
}
