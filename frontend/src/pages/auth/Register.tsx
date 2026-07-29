import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { BellRing, Check, Loader2 } from 'lucide-react'
import { api, errorMessage } from '../../api/client'
import { auth } from '../../api/endpoints'
import { useAuthStore } from '../../stores/auth'
import { Button, ErrorNote, Input, Label, Select } from '../../components/ui'
import { ISD_CODES } from '../../types'

export default function Register() {
  const navigate = useNavigate()
  const { setAuth, setUser } = useAuthStore()
  const [step, setStep] = useState<'form' | 'verify'>('form')
  const [form, setForm] = useState({
    name: '',
    country_code: '+91',
    mobile: '',
    username: '',
    account_type: 'personal',
  })
  const [usernameTouched, setUsernameTouched] = useState(false)
  const [usernameStatus, setUsernameStatus] = useState<'unknown' | 'checking' | 'available' | 'taken' | 'invalid'>('unknown')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  // Verify step
  const [otpMessage, setOtpMessage] = useState<string | null>(null)
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
        /* suggestion is best-effort */
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

  /** Pull the OTP message from the user's in-app inbox (app-to-app delivery). */
  const loadOtpMessage = async () => {
    try {
      const res = await api.get<{ data: { data: { kind?: string; message?: string } }[] }>('/notifications?unread=1')
      const otp = res.data.data.find((n) => n.data.kind === 'mobile_otp')
      setOtpMessage(otp?.data.message ?? null)
    } catch {
      setOtpMessage(null)
    }
  }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const res = await auth.register({
        ...form,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        device_name: 'web',
      })
      setAuth(res.token, res.data)
      setStep('verify')
      loadOtpMessage()
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
      await auth.verifyMobile(code)
      const fresh = await auth.me()
      setUser(fresh)
      navigate('/')
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }

  const resend = async () => {
    setError(null)
    try {
      await auth.resendMobileOtp()
      await loadOtpMessage()
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  return (
    <div className="flex min-h-full items-center justify-center p-4">
      <div className="w-full max-w-md">
        <div className="mb-6 text-center">
          <div className="mx-auto mb-3 flex size-12 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">
            PA
          </div>
          <h1 className="text-xl font-semibold">
            {step === 'form' ? 'Create your account' : 'Verify your mobile'}
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            {step === 'form'
              ? "Register with your mobile number — we'll verify it inside the app."
              : `Enter the code sent to ${form.country_code}${form.mobile} via your app inbox.`}
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
              <Label>Mobile number</Label>
              <div className="flex gap-2">
                <Select className="w-40" value={form.country_code} onChange={(e) => set('country_code', e.target.value)}>
                  {ISD_CODES.map(({ code: isd, label }) => (
                    <option key={isd} value={isd}>{label}</option>
                  ))}
                </Select>
                <Input
                  type="tel"
                  inputMode="numeric"
                  placeholder="9876543210"
                  value={form.mobile}
                  onChange={(e) => set('mobile', e.target.value.replace(/\D/g, ''))}
                  required
                />
              </div>
              <p className="mt-1 text-[11px] text-slate-400">
                The verification code arrives in your app inbox — no SMS needed.
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
                {usernameStatus === 'taken' && <span className="text-red-500">✗ Taken — try another or keep typing</span>}
                {usernameStatus === 'invalid' && <span className="text-amber-600">4–20 letters and numbers only</span>}
                {usernameStatus === 'unknown' && (
                  <span className="text-slate-400">Letters and numbers only, 4–20 characters.</span>
                )}
              </p>
            </div>

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
            <p className="text-center text-[11px] text-slate-400">
              No password needed — your account is secured by mobile OTP.
              You can add a password or email later from Settings.
            </p>
            <p className="text-center text-xs">
              Already have an account?{' '}
              <Link to="/login" className="text-brand-600 hover:underline">
                Sign in
              </Link>
            </p>
          </form>
        ) : (
          <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <ErrorNote message={error} />

            {/* The app inbox message, rendered right here (app-to-app delivery). */}
            <div className="flex items-start gap-2 rounded-lg bg-brand-50 p-3 text-sm text-brand-800 dark:bg-brand-950 dark:text-brand-200">
              <BellRing className="mt-0.5 size-4 shrink-0" />
              <span>{otpMessage ?? 'Your verification code is being delivered to your app inbox…'}</span>
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

            <div className="flex justify-between text-xs">
              <button className="text-brand-600 hover:underline" onClick={resend}>
                Resend code
              </button>
              <button className="text-slate-400 hover:underline" onClick={loadOtpMessage}>
                Refresh inbox
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
