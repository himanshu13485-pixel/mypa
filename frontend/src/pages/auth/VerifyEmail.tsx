import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { auth } from '../../api/endpoints'
import { errorMessage } from '../../api/client'
import { disconnectEcho } from '../../lib/echo'
import { useAuthStore } from '../../stores/auth'
import { Button, Card, ErrorNote, Input, Label } from '../../components/ui'

/**
 * Where a signed-in-but-unverified session lands.
 *
 * Registration hands back a token so the address can be confirmed, and the
 * sign-up form shows this step inline — but a deep link (a meeting invite,
 * say) used to skip straight past it into a working account. Now every
 * protected route sends the session here until the address is proven.
 */
export default function VerifyEmail() {
  const navigate = useNavigate()
  const { user, setUser, clear } = useAuthStore()
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  // The registration response is unauthenticated, so it carries no email
  // address to show here. /me does — and it also catches the case where the
  // address was confirmed in another tab, sending the user straight in.
  useEffect(() => {
    auth.me().then((fresh) => {
      setUser(fresh)
      if (!fresh.email_verification_required) navigate('/', { replace: true })
    }).catch(() => undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const verify = async () => {
    if (code.length < 6) return
    setError(null)
    setLoading(true)
    try {
      await auth.verifyEmailOtp(code)
      setUser(await auth.me())
      navigate('/', { replace: true })
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }

  const resend = async () => {
    setError(null)
    setNotice(null)
    try {
      await auth.resendEmailOtp()
      setNotice('A new code is on its way.')
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  const signOut = () => {
    disconnectEcho()
    clear()
    navigate('/login', { replace: true })
  }

  return (
    <div className="flex min-h-dvh items-center justify-center p-4">
      <Card className="w-full max-w-md">
        <h1 className="text-lg font-semibold">Confirm your email</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          We sent a 6-digit code to{' '}
          <span className="font-medium text-slate-700 dark:text-slate-200">{user?.email ?? 'your address'}</span>.
          Enter it to finish setting up your account.
        </p>

        <div className="mt-4 space-y-3">
          <ErrorNote message={error} />
          {notice && (
            <p className="rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
              {notice}
            </p>
          )}
          <div>
            <Label>Verification code</Label>
            <Input
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
              placeholder="123456"
              inputMode="numeric"
              autoComplete="one-time-code"
              autoFocus
              onKeyDown={(e) => e.key === 'Enter' && verify()}
            />
          </div>
          <Button className="w-full" onClick={verify} disabled={loading || code.length < 6}>
            {loading ? 'Verifying…' : 'Verify & continue'}
          </Button>
          <div className="flex items-center justify-between text-xs">
            <button className="text-brand-600 hover:underline" onClick={resend}>
              Resend the code
            </button>
            <button className="text-slate-400 hover:text-slate-600" onClick={signOut}>
              Sign in as someone else
            </button>
          </div>
          <p className="text-[11px] text-slate-400">
            Used the wrong address? Sign out and register again — an unconfirmed
            account cannot be used until the address is verified.
          </p>
        </div>
      </Card>
    </div>
  )
}
