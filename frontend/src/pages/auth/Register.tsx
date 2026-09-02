import { useEffect, useRef, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { Check, Loader2, MailCheck } from 'lucide-react'
import { api, errorMessage } from '../../api/client'
import NetvorkMark from '../../components/Logo'
import { auth } from '../../api/endpoints'
import { useAuthStore } from '../../stores/auth'
import { Button, ErrorNote, Input, Label, Select } from '../../components/ui'
import { DEFAULT_DIAL, MobileField } from '../../components/MobileField'
import { returnState, returnTo } from '../../lib/returnTo'

export default function Register() {
  const navigate = useNavigate()
  const location = useLocation()
  // Someone who made an account to attend a meeting should arrive at that
  // meeting, not at an empty dashboard.
  const next = returnTo(location.state)
  const { setAuth, setUser } = useAuthStore()
  const [step, setStep] = useState<'form' | 'verify'>('form')
  const [form, setForm] = useState({
    name: '',
    email: '',
    username: '',
    password: '',
    password_confirmation: '',
    mobile: '',
    country_code: DEFAULT_DIAL,
    account_type: 'personal',
  })
  const [usernameTouched, setUsernameTouched] = useState(false)
  const [usernameStatus, setUsernameStatus] = useState<'unknown' | 'checking' | 'available' | 'taken' | 'invalid'>('unknown')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [code, setCode] = useState('')

  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const set = (key: string, value: string) => setForm((f) => ({ ...f, [key]: value }))

  // Auto-suggest a unique username from the full name (until the user edits it).
  useEffect(() => {
    if (usernameTouched || form.name.trim().length < 3) return
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(async () => {
      try {
        const res = await api.get<{ data: { suggestion: string } }>('/auth/suggest-username', {
          params: { name: form.name.trim() },
        })
        setForm((f) => (usernameTouched ? f : { ...f, username: res.data.data.suggestion }))
        setUsernameStatus('available')
      } catch {
        /* best-effort */
      }
    }, 500)
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [form.name, usernameTouched])

  // Availability check when the user types their own username.
  useEffect(() => {
    if (!usernameTouched || form.username.length < 4) {
      if (usernameTouched) setUsernameStatus(form.username ? 'invalid' : 'unknown')
      return
    }
    setUsernameStatus('checking')
    const timer = setTimeout(async () => {
      try {
        const res = await api.get<{ data: { available: boolean; valid: boolean } }>('/auth/suggest-username', {
          params: { username: form.username },
        })
        setUsernameStatus(!res.data.data.valid ? 'invalid' : res.data.data.available ? 'available' : 'taken')
      } catch {
        setUsernameStatus('unknown')
      }
    }, 400)
    return () => clearTimeout(timer)
  }, [form.username, usernameTouched])

  /** The invite this sign-up came from, if it came from one. */
  const inviteCode = new URLSearchParams(location.search).get('invite') ?? ''

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const res = await auth.register({
        ...form,
        mobile: form.mobile ? form.country_code + form.mobile : null,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        device_name: 'web',
        /*
         * Whoever's link brought them here, carried through from /i/<code>.
         *
         * Without it the two people who just found each other would land on
         * either side of an empty address book and have to go looking again.
         * A code the server does not recognise is simply ignored, so a
         * mistyped link still ends in an account.
         */
        ...(inviteCode ? { invite_code: inviteCode } : {}),
      })
      setAuth(res.token, res.data)
      setStep('verify')
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }

  const verify = async () => {
    if (code.length < 6) return
    setError(null)
    setLoading(true)
    try {
      await auth.verifyEmailOtp(code)
      const fresh = await auth.me()
      setUser(fresh)
      navigate(next, { replace: true })
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }

  const resend = async () => {
    setError(null)
    try {
      await auth.resendEmailOtp()
      alert('A new code has been emailed to you.')
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  return (
    <div className="flex min-h-full items-center justify-center p-4">
      <div className="w-full max-w-md">
        <div className="mb-6 text-center">
          <NetvorkMark className="mx-auto mb-3 size-14" />
          <h1 className="text-xl font-semibold">
            {step === 'form' ? 'Create your account' : 'Confirm your email'}
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            {step === 'form'
              ? 'Register with your email — we send a code to confirm it.'
              : `Enter the 6-digit code we emailed to ${form.email}.`}
          </p>
        </div>

        {step === 'form' ? (
          <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <ErrorNote message={error} />
            <div>
              <Label>Full name</Label>
              <Input value={form.name} onChange={(e) => set('name', e.target.value)} required autoFocus />
            </div>

            <div>
              <Label>Email</Label>
              <Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} required />
              <p className="mt-1 text-[11px] text-slate-400">
                Your account is confirmed with a code sent to this address.
              </p>
            </div>

            <div>
              <Label>Username (auto-suggested — you can change it)</Label>
              <Input
                value={form.username}
                onChange={(e) => {
                  setUsernameTouched(true)
                  set('username', e.target.value.replace(/[^a-zA-Z0-9]/g, '').toLowerCase().slice(0, 20))
                }}
                required
                minLength={4}
              />
              <p className="mt-1 text-[11px]">
                {usernameStatus === 'checking' && <span className="text-slate-400">Checking availability…</span>}
                {usernameStatus === 'available' && form.username && (
                  <span className="text-emerald-600">✓ {form.username} is available</span>
                )}
                {usernameStatus === 'taken' && <span className="text-red-500">✗ Taken — try another</span>}
                {usernameStatus === 'invalid' && <span className="text-amber-600">4–20 letters and numbers only</span>}
                {usernameStatus === 'unknown' && (
                  <span className="text-slate-400">You can log in with either your email or username.</span>
                )}
              </p>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Password</Label>
                <Input type="password" value={form.password} onChange={(e) => set('password', e.target.value)} required />
              </div>
              <div>
                <Label>Confirm password</Label>
                <Input
                  type="password"
                  value={form.password_confirmation}
                  onChange={(e) => set('password_confirmation', e.target.value)}
                  required
                />
              </div>
            </div>

            <MobileField
              countryCode={form.country_code}
              number={form.mobile}
              onCountryCode={(code) => set('country_code', code)}
              onNumber={(national) => set('mobile', national)}
            />

            <div>
              <Label>Account type</Label>
              <Select value={form.account_type} onChange={(e) => set('account_type', e.target.value)}>
                <option value="personal">Personal</option>
                <option value="business">Business</option>
              </Select>
            </div>

            <Button type="submit" disabled={loading || usernameStatus === 'taken'} className="w-full">
              {loading ? 'Creating account…' : 'Continue'}
            </Button>
            <p className="text-center text-xs">
              Already have an account?{' '}
              <Link to="/login" state={returnState(next)} className="text-brand-600 hover:underline">
                Sign in
              </Link>
            </p>
          </form>
        ) : (
          <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <ErrorNote message={error} />

            <div className="flex items-start gap-2 rounded-lg bg-brand-50 p-3 text-sm text-brand-800 dark:bg-brand-950 dark:text-brand-200">
              <MailCheck className="mt-0.5 size-4 shrink-0" />
              <span>
                We emailed a 6-digit code to <b>{form.email}</b>. Check your inbox (and spam folder).
              </span>
            </div>

            <div>
              <Label>Verification code</Label>
              <Input
                className="text-center text-lg tracking-[0.5em]"
                inputMode="numeric"
                maxLength={6}
                placeholder="••••••"
                value={code}
                onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
                onKeyDown={(e) => e.key === 'Enter' && verify()}
                autoFocus
              />
            </div>

            <Button className="w-full" onClick={verify} disabled={loading || code.length < 6}>
              {loading ? <Loader2 className="size-4 animate-spin" /> : <Check className="size-4" />}
              Verify & continue
            </Button>

            <div className="text-center text-xs">
              <button className="text-brand-600 hover:underline" onClick={resend}>
                Resend code
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
