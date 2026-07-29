import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { auth } from '../../api/endpoints'
import { errorMessage } from '../../api/client'
import { useAuthStore } from '../../stores/auth'
import { Button, ErrorNote, Input, Label, Select } from '../../components/ui'
import { ISD_CODES } from '../../types'

export default function Register() {
  const navigate = useNavigate()
  const setAuth = useAuthStore((s) => s.setAuth)
  const [form, setForm] = useState({
    name: '',
    country_code: '+91',
    mobile: '',
    username: '',
    email: '',
    password: '',
    password_confirmation: '',
    account_type: 'personal',
  })
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  const set = (key: string, value: string) => setForm((f) => ({ ...f, [key]: value }))

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const res = await auth.register({
        ...form,
        email: form.email || null,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        device_name: 'web',
      })
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
      <div className="w-full max-w-md">
        <div className="mb-6 text-center">
          <div className="mx-auto mb-3 flex size-12 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">
            PA
          </div>
          <h1 className="text-xl font-semibold">Create your account</h1>
          <p className="mt-1 text-sm text-slate-500">
            Register with your mobile number — we'll verify it inside the app.
          </p>
        </div>
        <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <ErrorNote message={error} />
          <div>
            <Label>Full name</Label>
            <Input value={form.name} onChange={(e) => set('name', e.target.value)} required autoFocus />
          </div>

          <div>
            <Label>Mobile number</Label>
            <div className="flex gap-2">
              <Select
                className="w-40"
                value={form.country_code}
                onChange={(e) => set('country_code', e.target.value)}
              >
                {ISD_CODES.map(({ code, label }) => (
                  <option key={code} value={code}>{label}</option>
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
              A verification code will arrive in your app notifications — no SMS needed.
            </p>
          </div>

          <div>
            <Label>Username</Label>
            <Input
              placeholder="letters and numbers only, 4–20 characters"
              value={form.username}
              onChange={(e) => set('username', e.target.value.replace(/[^a-zA-Z0-9]/g, '').slice(0, 20))}
              required
              minLength={4}
            />
            <p className="mt-1 text-[11px] text-slate-400">
              Others can find you by this username, your mobile number, or your App ID.
            </p>
          </div>

          <div>
            <Label>Email (optional — adds another way to log in)</Label>
            <Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} />
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

          <div>
            <Label>Account type</Label>
            <Select value={form.account_type} onChange={(e) => set('account_type', e.target.value)}>
              <option value="personal">Personal</option>
              <option value="business">Business</option>
            </Select>
          </div>

          <Button type="submit" disabled={loading} className="w-full">
            {loading ? 'Creating account…' : 'Create account'}
          </Button>
          <p className="text-center text-xs">
            Already have an account?{' '}
            <Link to="/login" className="text-brand-600 hover:underline">
              Sign in
            </Link>
          </p>
        </form>
      </div>
    </div>
  )
}
