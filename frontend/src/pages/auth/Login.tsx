import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { clsx } from 'clsx'
import { auth } from '../../api/endpoints'
import { errorMessage } from '../../api/client'
import NetvorkMark from '../../components/Logo'
import { useAuthStore } from '../../stores/auth'
import { Button, ErrorNote, Input, Label } from '../../components/ui'
import { returnState, returnTo } from '../../lib/returnTo'
import { forgetDevice, rememberDevice } from '../../lib/deviceTrust'

/** Long enough that a slow mail server is waited for, not queued behind. */
const RESEND_COOLDOWN_SECONDS = 60

export default function Login() {
  const navigate = useNavigate()
  const location = useLocation()
  // Whoever sent us here — the auth guard, or the guest door on a meeting
  // link — said where this ends. Default to the dashboard.
  const next = returnTo(location.state)
  const setAuth = useAuthStore((s) => s.setAuth)
  const [mode, setMode] = useState<'password' | 'otp'>('password')
  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [codeSent, setCodeSent] = useState(false)
  // The password was right and a code went out. Until it is answered there
  // is no token, so nothing about being signed in has happened yet.
  const [signInChallenge, setSignInChallenge] = useState<{ sentTo: string; sentAt: number; expiresInMinutes: number } | null>(null)
  const [resending, setResending] = useState(false)
  /*
   * A clock, only while the code screen is up.
   *
   * The code lasts ten minutes and there was nothing on this screen that
   * could send another one — somebody who came back to it after lunch, or
   * whose mail was slow, had a dead code, no way to say so, and nothing to do
   * but go back and start the sign-in over. This ticks so the screen can say
   * how long is left and offer a fresh one when it matters.
   */
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    if (!signInChallenge) return
    const id = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(id)
  }, [signInChallenge])
  const [rememberDeviceChoice, setRememberDeviceChoice] = useState(true)
  const [info, setInfo] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  const submitPassword = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const res = await auth.login({ identifier, password, device_name: 'web' })

      // A device this account has not signed in on before is asked for a
      // code as well; a device it has is let straight through.
      if (res.otp_required) {
        setSignInChallenge({
          sentTo: res.sent_to ?? 'your email',
          sentAt: Date.now(),
          expiresInMinutes: res.expires_in_minutes ?? 10,
        })
        setCode('')
        setInfo(res.message ?? null)
        return
      }

      setAuth(res.token!, res.data!)
      navigate(next, { replace: true })
    } catch (err) {
      const message = errorMessage(err)
      setError(message)
      // Passwordless account? Guide straight into the OTP flow.
      if (message.includes('no password')) {
        setMode('otp')
      }
    } finally {
      setLoading(false)
    }
  }

  const submitSignInCode = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const res = await auth.verifySignIn({
        identifier: identifier.trim(),
        code,
        device_name: 'web',
        remember_device: rememberDeviceChoice,
      })
      // Handed over once and only here: this browser can skip the code
      // next time, but never the password.
      if (res.device_token) rememberDevice(res.device_token)
      else forgetDevice()
      setAuth(res.token, res.data)
      navigate(next, { replace: true })
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }

  /*
   * Send another sign-in code.
   *
   * Deliberately the login call again rather than a resend endpoint of its
   * own: a route that posts a code to whoever is named would let anybody mail
   * anybody, so the password is the thing that earns a code, and it is still
   * in hand here. The server retires the previous code as it issues the new
   * one, so there is never a moment with two live codes.
   */
  const resendSignInCode = async () => {
    setError(null)
    setResending(true)
    try {
      const res = await auth.login({ identifier: identifier.trim(), password, device_name: 'web' })

      // A device trusted in the meantime is let straight in; there is then
      // no code to wait for and nothing left to ask.
      if (!res.otp_required && res.token && res.data) {
        setAuth(res.token, res.data)
        navigate(next, { replace: true })
        return
      }

      setSignInChallenge({
        sentTo: res.sent_to ?? signInChallenge?.sentTo ?? 'your email',
        sentAt: Date.now(),
        expiresInMinutes: res.expires_in_minutes ?? 10,
      })
      setCode('')
      setInfo('A new code is on its way. The previous one no longer works.')
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setResending(false)
    }
  }

  const requestCode = async () => {
    if (!identifier.trim()) {
      setError('Enter your mobile number, username, or email first.')
      return
    }
    setError(null)
    setLoading(true)
    try {
      await auth.requestLoginOtp(identifier.trim())
      setCodeSent(true)
      setInfo('Code sent to your app inbox — check the bell on a signed-in device, or ask your admin.')
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }

  const submitOtp = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const res = await auth.loginWithOtp({ identifier: identifier.trim(), code, device_name: 'web' })
      setAuth(res.token, res.data)
      navigate(next, { replace: true })
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }

  /*
   * Three numbers off the one clock.
   *
   * `secondsLeft` is what the current code has; `codeExpired` is when that
   * has run out, which is the moment the wait before resending stops making
   * any sense; `resendIn` is that wait, a minute from whenever the last code
   * went out.
   */
  const elapsed = signInChallenge ? Math.floor((now - signInChallenge.sentAt) / 1000) : 0
  const secondsLeft = signInChallenge ? Math.max(0, signInChallenge.expiresInMinutes * 60 - elapsed) : 0
  const codeExpired = !!signInChallenge && secondsLeft === 0
  const resendIn = Math.max(0, RESEND_COOLDOWN_SECONDS - elapsed)

  return (
    <div className="flex min-h-full items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 text-center">
          <NetvorkMark className="mx-auto mb-3 size-14" />
          <h1 className="text-xl font-semibold">Welcome back</h1>
          <p className="mt-1 text-sm text-slate-500">Sign in to your Netvork account</p>
          <p className="mt-0.5 text-xs italic text-brand-600">One App. Every Task. Every Connection.</p>
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          {/*
            * The second step, on a device this account has not used before.
            * It replaces the form rather than sitting under it: the password
            * is already accepted, and the only thing left to do is this.
            */}
          {signInChallenge ? (
            <form onSubmit={submitSignInCode} className="space-y-4">
              <div>
                <h2 className="text-sm font-semibold">Check it&rsquo;s you</h2>
                <p className="mt-1 text-xs text-slate-500">
                  We sent a code to <span className="font-medium">{signInChallenge.sentTo}</span>. If you are
                  already signed in on your phone, it is in your notifications too.
                </p>
                {/* What the code has left, and then plainly that it is gone.
                    A code that silently stopped working looked from here like
                    a code being typed wrong. */}
                <p className={clsx('mt-1 text-xs', codeExpired ? 'text-red-600 dark:text-red-400' : 'text-slate-400')}>
                  {codeExpired
                    ? 'This code has expired. Send a new one below.'
                    : `Expires in ${Math.floor(secondsLeft / 60)}:${String(secondsLeft % 60).padStart(2, '0')}.`}
                </p>
              </div>
              <ErrorNote message={error} />
              <div>
                <Label>Sign-in code</Label>
                <Input
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  placeholder="000000"
                  autoFocus
                  required
                />
              </div>
              <label className="flex items-start gap-2 text-xs text-slate-600 dark:text-slate-300">
                <input
                  type="checkbox"
                  checked={rememberDeviceChoice}
                  onChange={(e) => setRememberDeviceChoice(e.target.checked)}
                  className="mt-0.5 size-4 accent-brand-600"
                />
                <span>
                  Remember this device
                  <span className="block text-slate-400">
                    Untick on a shared or public computer — then a code is asked for every time.
                  </span>
                </span>
              </label>
              <Button type="submit" className="w-full" disabled={loading || code.length < 6}>
                {loading ? 'Checking…' : 'Sign in'}
              </Button>
              {/* Held back for a minute so a slow mail server is waited for
                  rather than sent a queue of codes, each one retiring the
                  last. An expired code skips the wait: there is nothing left
                  to be patient about. */}
              <button
                type="button"
                className="w-full text-center text-xs font-medium text-brand-600 hover:text-brand-700 disabled:font-normal disabled:text-slate-400"
                disabled={resending || loading || (!codeExpired && resendIn > 0)}
                onClick={resendSignInCode}
              >
                {resending
                  ? 'Sending…'
                  : !codeExpired && resendIn > 0
                    ? `Resend code in ${resendIn}s`
                    : 'Resend code'}
              </button>
              <button
                type="button"
                className="w-full text-center text-xs text-slate-400 hover:text-slate-600"
                onClick={() => { setSignInChallenge(null); setCode(''); setInfo(null); setError(null) }}
              >
                Use a different account
              </button>
            </form>
          ) : (
          <>
          {/* Mode toggle */}
          <div className="mb-4 flex rounded-lg border border-slate-200 p-0.5 text-sm dark:border-slate-700">
            {(['password', 'otp'] as const).map((m) => (
              <button
                key={m}
                type="button"
                className={clsx(
                  'flex-1 rounded-md py-1.5 font-medium',
                  mode === m ? 'bg-brand-600 text-white' : 'text-slate-500',
                )}
                onClick={() => {
                  setMode(m)
                  setError(null)
                  setInfo(null)
                }}
              >
                {m === 'password' ? 'Password' : 'Login with code'}
              </button>
            ))}
          </div>

          <form onSubmit={mode === 'password' ? submitPassword : submitOtp} className="space-y-4">
            <ErrorNote message={error} />
            {info && !error && (
              <p className="rounded-lg bg-brand-50 px-3 py-2 text-xs text-brand-700 dark:bg-brand-950 dark:text-brand-300">
                {info}
              </p>
            )}

            <div>
              <Label>Email or username</Label>
              <Input
                value={identifier}
                onChange={(e) => setIdentifier(e.target.value)}
                placeholder="you@mail.com or rahul"
                required
                autoFocus
              />
            </div>

            {mode === 'password' ? (
              <>
                <div>
                  <Label>Password</Label>
                  <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
                </div>
                <Button type="submit" disabled={loading} className="w-full">
                  {loading ? 'Signing in…' : 'Sign in'}
                </Button>
                <div className="flex justify-between text-xs">
                  <Link to="/forgot-password" className="text-brand-600 hover:underline">
                    Forgot password?
                  </Link>
                  <Link to="/register" state={returnState(next)} className="text-brand-600 hover:underline">
                    Create account
                  </Link>
                </div>
              </>
            ) : (
              <>
                {codeSent && (
                  <div>
                    <Label>One-time code</Label>
                    <Input
                      className="text-center text-lg tracking-[0.5em]"
                      inputMode="numeric"
                      maxLength={6}
                      placeholder="••••••"
                      value={code}
                      onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
                    />
                  </div>
                )}
                {codeSent ? (
                  <Button type="submit" disabled={loading || code.length < 6} className="w-full">
                    {loading ? 'Signing in…' : 'Sign in with code'}
                  </Button>
                ) : (
                  <Button type="button" onClick={requestCode} disabled={loading} className="w-full">
                    {loading ? 'Sending…' : 'Send login code'}
                  </Button>
                )}
                <div className="flex justify-between text-xs">
                  {codeSent ? (
                    <button type="button" className="text-brand-600 hover:underline" onClick={requestCode}>
                      Resend code
                    </button>
                  ) : (
                    <span />
                  )}
                  <Link to="/register" state={returnState(next)} className="text-brand-600 hover:underline">
                    Create account
                  </Link>
                </div>
              </>
            )}
          </form>
          </>
          )}
        </div>
      </div>
    </div>
  )
}
