import { useState, type FormEvent } from 'react'
import { KeyRound, Lock, Mail } from 'lucide-react'
import { chatLock, type ChatLockStatus } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { useChatUnlock } from '../lib/chatUnlock'
import { Button, ErrorNote, Input, Label, Modal } from './ui'

/**
 * The chat password's screens.
 *
 * One password per person opens every chat they locked and the folder of
 * the ones they hid. Each screen here ends the same way when it succeeds:
 * the server hands back a fresh unlock, since the password was just given,
 * and it is kept in memory so the next screen does not ask again.
 */

function keep(token: string) {
  useChatUnlock.getState().set(token)
}

/** Password and its confirmation, checked before anything is sent. */
function usePair() {
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const problem = password.length > 0 && password.length < 4
    ? 'At least four characters - a PIN like 123456 is fine.'
    : confirm.length > 0 && confirm !== password
      ? 'The two do not match.'
      : null

  return { password, setPassword, confirm, setConfirm, problem, ready: password.length >= 4 && confirm === password }
}

/**
 * The first password, or a new one in place of the old.
 *
 * `then` is what the person was trying to do when they found they had no
 * password - lock a chat, hide one - so it can simply carry on afterwards.
 */
export function SetChatPassword({ change, onDone, onClose }: {
  change?: boolean
  onDone: () => void
  onClose: () => void
}) {
  const pair = usePair()
  const [current, setCurrent] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!pair.ready) return
    setBusy(true)
    setError(null)
    try {
      const res = await chatLock.set(pair.password, pair.confirm, change ? current : undefined)
      keep(res.data.token)
      onDone()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title={change ? 'Change chat password' : 'Set a chat password'} onClose={onClose}>
      <form onSubmit={submit} className="space-y-3">
        <p className="text-sm text-slate-500">
          {change
            ? 'The new one opens everything the old one did.'
            : 'One password for every chat you lock, and for your hidden chats. It is yours alone - the people you chat with are never told a chat is locked.'}
        </p>
        <ErrorNote message={error} />
        {change && (
          <div>
            <Label>Current password</Label>
            <Input type="password" value={current} onChange={(e) => setCurrent(e.target.value)} autoComplete="off" className="w-full" autoFocus />
          </div>
        )}
        <div>
          <Label>{change ? 'New password' : 'Password'}</Label>
          <Input
            type="password"
            value={pair.password}
            onChange={(e) => pair.setPassword(e.target.value)}
            autoComplete="new-password"
            className="w-full"
            autoFocus={!change}
            maxLength={32}
          />
        </div>
        <div>
          <Label>Type it again</Label>
          <Input
            type="password"
            value={pair.confirm}
            onChange={(e) => pair.setConfirm(e.target.value)}
            autoComplete="new-password"
            className="w-full"
            maxLength={32}
          />
        </div>
        {pair.problem && <p className="text-xs text-red-500">{pair.problem}</p>}
        <p className="text-xs text-slate-400">
          Forgotten later? A code to your account e-mail resets it.
        </p>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={!pair.ready || busy || (change && !current)}>
            {busy ? 'Saving…' : 'Save password'}
          </Button>
        </div>
      </form>
    </Modal>
  )
}

/**
 * "Enter your chat password" - for locking, unlocking, hiding, unhiding.
 *
 * `run` is the action itself, sent with the password; whatever it returns
 * carries the new unlock.
 */
export function ChatPasswordPrompt({ title, hint, action, run, onDone, onForgot, onClose }: {
  title: string
  hint: string
  action: string
  run: (password: string) => Promise<{ message?: string; data: { token: string } }>
  onDone: (message?: string) => void
  onForgot: () => void
  onClose: () => void
}) {
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!password) return
    setBusy(true)
    setError(null)
    try {
      const res = await run(password)
      keep(res.data.token)
      onDone(res.message)
    } catch (err) {
      setError(errorMessage(err))
      setPassword('')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title={title} onClose={onClose}>
      <form onSubmit={submit} className="space-y-3">
        <p className="text-sm text-slate-500">{hint}</p>
        <ErrorNote message={error} />
        <Input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          placeholder="Chat password"
          autoComplete="off"
          className="w-full"
          autoFocus
          maxLength={32}
        />
        <div className="flex items-center justify-between gap-2">
          <button type="button" onClick={onForgot} className="text-xs font-medium text-brand-600 hover:underline">
            Forgot password?
          </button>
          <div className="flex gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={!password || busy}>{busy ? 'Checking…' : action}</Button>
          </div>
        </div>
      </form>
    </Modal>
  )
}

/**
 * Forgotten: a code to the account's e-mail, then a new password.
 *
 * The inbox is the one thing somebody who merely picked up the phone does
 * not have, so it is what proves the owner. The locks stay where they were -
 * the point is to get back in, not to undo them.
 */
export function ForgotChatPassword({ onDone, onClose }: { onDone: () => void; onClose: () => void }) {
  const pair = usePair()
  const [sentTo, setSentTo] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const send = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await chatLock.forgot()
      setSentTo(res.data.sent_to)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  const reset = async (e: FormEvent) => {
    e.preventDefault()
    if (!pair.ready || code.trim().length < 6) return
    setBusy(true)
    setError(null)
    try {
      const res = await chatLock.reset(code.trim(), pair.password, pair.confirm)
      keep(res.data.token)
      onDone()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title="Forgot chat password" onClose={onClose}>
      {!sentTo ? (
        <div className="space-y-3">
          <p className="text-sm text-slate-500">
            We will e-mail a six-digit code to the address on your account. Enter it here with a new
            password. Your locked and hidden chats stay exactly as they are.
          </p>
          <ErrorNote message={error} />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={onClose}>Cancel</Button>
            <Button onClick={send} disabled={busy}>
              <Mail className="size-4" /> {busy ? 'Sending…' : 'Send code'}
            </Button>
          </div>
        </div>
      ) : (
        <form onSubmit={reset} className="space-y-3">
          <p className="text-sm text-slate-500">
            A code went to <span className="font-medium text-slate-700 dark:text-slate-200">{sentTo}</span>.
            It lasts a few minutes.
          </p>
          <ErrorNote message={error} />
          <div>
            <Label>Code from the e-mail</Label>
            <Input
              inputMode="numeric"
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
              placeholder="123456"
              className="w-full tracking-widest"
              autoFocus
            />
          </div>
          <div>
            <Label>New password</Label>
            <Input type="password" value={pair.password} onChange={(e) => pair.setPassword(e.target.value)} autoComplete="new-password" className="w-full" maxLength={32} />
          </div>
          <div>
            <Label>Type it again</Label>
            <Input type="password" value={pair.confirm} onChange={(e) => pair.setConfirm(e.target.value)} autoComplete="new-password" className="w-full" maxLength={32} />
          </div>
          {pair.problem && <p className="text-xs text-red-500">{pair.problem}</p>}
          <div className="flex items-center justify-between gap-2">
            <button type="button" onClick={send} disabled={busy} className="text-xs font-medium text-brand-600 hover:underline disabled:opacity-50">
              Send a new code
            </button>
            <Button type="submit" disabled={!pair.ready || code.length < 6 || busy}>
              {busy ? 'Saving…' : 'Reset password'}
            </Button>
          </div>
        </form>
      )}
    </Modal>
  )
}

/**
 * The password itself: change it, reset it, or take it away.
 *
 * Taking it away is said plainly for what it does - every locked and hidden
 * chat becomes an ordinary one, since nothing would be left to open them.
 */
export function ChatLockSettings({ status, onChange, onForgot, onChanged, onClose }: {
  status: ChatLockStatus
  onChange: () => void
  onForgot: () => void
  onChanged: (message: string) => void
  onClose: () => void
}) {
  const [removing, setRemoving] = useState(false)

  if (removing) {
    return (
      <ChatPasswordPrompt
        title="Remove chat password"
        hint={
          status.locked_count + status.hidden_count > 0
            ? `This unlocks ${status.locked_count} locked and ${status.hidden_count} hidden chat${status.locked_count + status.hidden_count === 1 ? '' : 's'} - they all become ordinary chats again.`
            : 'You have no locked or hidden chats, so nothing else changes.'
        }
        action="Remove password"
        run={async (password) => {
          const res = await chatLock.remove(password)
          useChatUnlock.getState().clear()

          return { message: res.message, data: { token: '' } }
        }}
        onDone={(message) => {
          useChatUnlock.getState().clear()
          onChanged(message ?? 'Chat password removed.')
        }}
        onForgot={onForgot}
        onClose={() => setRemoving(false)}
      />
    )
  }

  return (
    <Modal title="Chat password" onClose={onClose}>
      <div className="space-y-3">
        <div className="flex items-start gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
          <Lock className="mt-0.5 size-4 shrink-0 text-brand-600" />
          <p className="text-sm text-slate-600 dark:text-slate-300">
            {status.locked_count} locked chat{status.locked_count === 1 ? '' : 's'}
            {' · '}
            {status.hidden_count} hidden
            <span className="mt-0.5 block text-xs text-slate-400">
              Type <span className="font-mono">#your password#</span> in the chat search to open hidden chats.
              Once entered, locked chats stay open for {status.window_minutes} minutes of use, or until you
              reload the app.
            </span>
          </p>
        </div>
        <div className="grid gap-2">
          <Button variant="secondary" onClick={onChange}><KeyRound className="size-4" /> Change password</Button>
          <Button variant="secondary" onClick={onForgot}><Mail className="size-4" /> Forgot password - reset by e-mail</Button>
          <Button variant="danger" onClick={() => setRemoving(true)}>Remove password</Button>
        </div>
      </div>
    </Modal>
  )
}

/**
 * What a locked chat shows in place of its messages.
 *
 * Its name and nothing of its contents, and one box for the password.
 */
export function LockedThread({ name, onUnlocked, onForgot }: {
  name: string
  onUnlocked: () => void
  onForgot: () => void
}) {
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!password) return
    setBusy(true)
    setError(null)
    try {
      const res = await chatLock.unlock(password)
      keep(res.data.token)
      onUnlocked()
    } catch (err) {
      setError(errorMessage(err))
      setPassword('')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex flex-1 items-center justify-center p-6">
      <form onSubmit={submit} className="w-full max-w-xs space-y-3 text-center">
        <div className="mx-auto flex size-14 items-center justify-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
          <Lock className="size-6" />
        </div>
        <div>
          <p className="text-base font-semibold">{name} is locked</p>
          <p className="mt-0.5 text-sm text-slate-500">Enter your chat password to read it.</p>
        </div>
        <ErrorNote message={error} />
        <Input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          placeholder="Chat password"
          autoComplete="off"
          className="w-full text-center"
          autoFocus
          maxLength={32}
        />
        <Button type="submit" className="w-full" disabled={!password || busy}>
          {busy ? 'Checking…' : 'Unlock'}
        </Button>
        <button type="button" onClick={onForgot} className="text-xs font-medium text-brand-600 hover:underline">
          Forgot password?
        </button>
      </form>
    </div>
  )
}
