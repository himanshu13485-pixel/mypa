import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Loader2, Video } from 'lucide-react'
import { guestMeetings } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { Button, Card, ErrorNote, Input, Label } from '../components/ui'

/**
 * Joining a meeting with a passcode and no account.
 *
 * Deliberately outside the app shell and outside the auth guard — the person
 * landing here has no Netvork login and is not going to make one. All they
 * give is a name to appear as and the passcode the host sent them.
 *
 * The pass lasts 30 minutes and is kept in sessionStorage rather than
 * localStorage: it belongs to this tab and this sitting, and should not
 * outlive either.
 */
export default function GuestJoinPage() {
  const { code = '' } = useParams()
  const navigate = useNavigate()

  const [name, setName] = useState('')
  const [passcode, setPasscode] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setBusy(true)
    try {
      const pass = await guestMeetings.join(code, name.trim(), passcode.trim())
      sessionStorage.setItem(
        'mypa-guest-pass',
        JSON.stringify({ code, token: pass.token, expiresAt: pass.expires_at, name: pass.guest.name }),
      )
      navigate(`/meetings/room/${code}?guest=1`, { replace: true })
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex min-h-dvh items-center justify-center bg-slate-50 p-4 dark:bg-slate-950">
      <Card className="w-full max-w-sm space-y-4 p-6">
        <div className="space-y-1 text-center">
          <span className="mx-auto flex size-12 items-center justify-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-950">
            <Video className="size-6" />
          </span>
          <h1 className="text-lg font-semibold">Join the meeting</h1>
          <p className="text-sm text-slate-500">
            No account needed. You can stay for 30 minutes.
          </p>
        </div>

        <form onSubmit={submit} className="space-y-3">
          <ErrorNote message={error} />
          <div>
            <Label>Your name</Label>
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="What should people call you?"
              maxLength={50}
              required
              autoFocus
            />
          </div>
          <div>
            <Label>Passcode</Label>
            <Input
              value={passcode}
              onChange={(e) => setPasscode(e.target.value)}
              placeholder="From whoever invited you"
              maxLength={12}
              required
            />
          </div>
          <Button type="submit" className="w-full" disabled={busy || !name.trim() || !passcode.trim()}>
            {busy ? <><Loader2 className="size-4 animate-spin" /> Joining…</> : 'Join'}
          </Button>
        </form>

        <p className="text-center text-xs text-slate-400">
          Have an account?{' '}
          <button className="text-brand-600 hover:underline" onClick={() => navigate(`/login?next=/meetings/room/${code}`)}>
            Sign in instead
          </button>{' '}
          — there is no time limit then.
        </p>
      </Card>
    </div>
  )
}
