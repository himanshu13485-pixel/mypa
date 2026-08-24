import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import axios from 'axios'
import { KeyRound } from 'lucide-react'
import { useAuthStore } from '../stores/auth'
import type { User } from '../types'
import { Button, Card, ErrorNote, Input, Label } from '../components/ui'

/**
 * The door for an account that has a token and no password.
 *
 * A service account is created without anybody choosing a password — its
 * password is random and nobody keeps it, because the token is the credential.
 * Which left the panel with no way in at all: the ordinary sign-in form asks
 * for an email and a password, and one of those does not exist.
 *
 * Deliberately not part of the normal sign-in screen. Pasting a bearer token
 * into a login form is a habit worth not teaching, and a person arriving at
 * the front door should never be invited to look for one.
 */
export default function ServiceSignIn() {
  const navigate = useNavigate()
  const setAuth = useAuthStore((s) => s.setAuth)
  const [token, setToken] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async () => {
    const value = token.trim()
    if (!value) return

    setBusy(true)
    setError(null)

    try {
      // Straight to the server rather than through the shared client, which
      // would attach whatever token is already stored — the wrong one here.
      const res = await axios.get<{ data: User }>('/api/v1/me', {
        headers: { Authorization: `Bearer ${value}`, Accept: 'application/json' },
      })
      const user = res.data.data

      // A person's token would work perfectly well here and land them in a
      // panel built for something else. Say so instead.
      if (!user.is_service_account) {
        setError('That token belongs to an ordinary account. Sign in with your email and password instead.')
        return
      }

      setAuth(value, user)
      navigate('/service', { replace: true })
    } catch (err) {
      setError(
        axios.isAxiosError(err) && err.response?.status === 401
          ? 'That token is not valid. It may have been revoked.'
          : 'Could not check that token. Try again in a moment.',
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto flex min-h-dvh max-w-md items-center p-4">
      <Card className="w-full">
        <h1 className="flex items-center gap-2 text-base font-semibold">
          <KeyRound className="size-4" /> Service account sign-in
        </h1>
        <p className="mt-1 text-sm text-slate-500">
          For an account an application signs in as. Paste one of its tokens — there is no password.
        </p>

        <form
          className="mt-4 space-y-3"
          onSubmit={(e) => {
            e.preventDefault()
            void submit()
          }}
        >
          <ErrorNote message={error} />
          <div>
            <Label>Token</Label>
            <Input
              type="password"
              autoFocus
              autoComplete="off"
              placeholder="117|…"
              value={token}
              onChange={(e) => setToken(e.target.value)}
            />
          </div>
          <Button type="submit" className="w-full" disabled={!token.trim() || busy}>
            {busy ? 'Checking…' : 'Open the panel'}
          </Button>
        </form>
      </Card>
    </div>
  )
}
