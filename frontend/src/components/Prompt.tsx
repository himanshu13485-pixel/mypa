import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react'
import { Button, Input, Modal } from './ui'

/**
 * Asking a question, without window.prompt.
 *
 * `prompt()` and `confirm()` are not merely ugly. Several in-app browsers —
 * Instagram's, and a number of Android WebViews — refuse them outright and
 * return null without showing anything, so "Rename file" and "New folder"
 * silently did nothing for those users, with no error and nothing in the log.
 * They also block the whole page, cannot be styled, and announce themselves
 * as "netvork.app says".
 *
 * The shape is deliberately the same as the thing it replaces, so a call site
 * changes by one word:
 *
 *   const name = await ask({ title: 'New name', value: file.name })
 *   if (await confirm({ title: 'Delete this?', danger: true })) …
 */
interface AskOptions {
  title: string
  message?: string
  /** Pre-filled, and selected, exactly as prompt()'s second argument was. */
  value?: string
  placeholder?: string
  actionLabel?: string
  /** Read-only: for showing something to copy rather than asking for input. */
  readOnly?: boolean
}

interface ConfirmOptions {
  title: string
  message?: string
  actionLabel?: string
  danger?: boolean
}

interface PromptContextValue {
  ask: (options: AskOptions) => Promise<string | null>
  confirm: (options: ConfirmOptions) => Promise<boolean>
}

const PromptContext = createContext<PromptContextValue>({
  ask: async () => null,
  confirm: async () => false,
})

export const usePrompt = () => useContext(PromptContext)

type Pending =
  | { kind: 'ask'; options: AskOptions }
  | { kind: 'confirm'; options: ConfirmOptions }

export function PromptProvider({ children }: { children: ReactNode }) {
  const [pending, setPending] = useState<Pending | null>(null)
  const [value, setValue] = useState('')
  // Held in a ref rather than state: settling the promise must not depend on
  // a render having happened.
  const settle = useRef<((result: string | null | boolean) => void) | null>(null)

  const close = (result: string | null | boolean) => {
    settle.current?.(result)
    settle.current = null
    setPending(null)
    setValue('')
  }

  const ask = useCallback((options: AskOptions) => {
    setValue(options.value ?? '')
    setPending({ kind: 'ask', options })
    return new Promise<string | null>((resolve) => {
      settle.current = resolve as (result: string | null | boolean) => void
    })
  }, [])

  const confirm = useCallback((options: ConfirmOptions) => {
    setPending({ kind: 'confirm', options })
    return new Promise<boolean>((resolve) => {
      settle.current = resolve as (result: string | null | boolean) => void
    })
  }, [])

  return (
    <PromptContext.Provider value={{ ask, confirm }}>
      {children}
      {pending && (
        <Modal
          title={pending.options.title}
          onClose={() => close(pending.kind === 'ask' ? null : false)}
        >
          <div className="space-y-4">
            {pending.options.message && (
              <p className="text-sm text-slate-500 dark:text-slate-400">{pending.options.message}</p>
            )}

            {pending.kind === 'ask' && (
              <Input
                autoFocus
                value={value}
                readOnly={pending.options.readOnly}
                placeholder={pending.options.placeholder}
                onChange={(e) => setValue(e.target.value)}
                onFocus={(e) => e.currentTarget.select()}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') close(value)
                  if (e.key === 'Escape') close(null)
                }}
              />
            )}

            <div className="flex justify-end gap-2">
              <Button variant="ghost" onClick={() => close(pending.kind === 'ask' ? null : false)}>
                Cancel
              </Button>
              <Button
                variant={pending.kind === 'confirm' && pending.options.danger ? 'danger' : 'primary'}
                disabled={pending.kind === 'ask' && !pending.options.readOnly && !value.trim()}
                onClick={() => close(pending.kind === 'ask' ? value : true)}
              >
                {pending.options.actionLabel ?? (pending.kind === 'ask' ? 'Save' : 'Confirm')}
              </Button>
            </div>
          </div>
        </Modal>
      )}
    </PromptContext.Provider>
  )
}
