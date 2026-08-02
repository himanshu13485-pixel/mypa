import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { clsx } from 'clsx'
import { auth } from '../../api/endpoints'
import { errorMessage } from '../../api/client'
import NetvorkMark from '../../components/Logo'
import { useAuthStore } from '../../stores/auth'
import { Button, ErrorNote, Input, Label } from '../../components/ui'

export default function Login() {
  const navigate = useNavigate()
  const setAuth = useAuthStore((s) => s.setAuth)
  const [mode, setMode] = useState<'password' | 'otp'>('password')
  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [codeSent, setCodeSent] = useState(false)
  const [info, setInfo] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  const submitPassword = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const res = await auth.login({ identifier, password, device_name: 'web' })
      setAuth(res.token, res.data)
      navigate('/')
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
      navigate('/')
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }

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
                  <Link to="/register" className="text-brand-600 hover:underline">
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
                  <Link to="/register" className="text-brand-600 hover:underline">
                    Create account
                  </Link>
                </div>
              </>
            )}
          </form>
        </div>
      </div>
    </div>
  )
}
