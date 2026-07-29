import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { auth } from '../../api/endpoints'
import { errorMessage } from '../../api/client'
import { useAuthStore } from '../../stores/auth'
import { Button, ErrorNote, Input, Label, Select } from '../../components/ui'

export default function Register() {
  const navigate = useNavigate()
  const setAuth = useAuthStore((s) => s.setAuth)
  const [form, setForm] = useState({
    name: '',
    email: '',
    mobile: '',
    password: '',
    password_confirmation: '',
    country: '',
    account_type: 'personal',
    referral_app_id: '',
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
          <p className="mt-1 text-sm text-slate-500">You'll get a unique My PA App ID instantly</p>
        </div>
        <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <ErrorNote message={error} />
          <div>
            <Label>Full name</Label>
            <Input value={form.name} onChange={(e) => set('name', e.target.value)} required autoFocus />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label>Email</Label>
              <Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} required />
            </div>
            <div>
              <Label>Mobile (optional)</Label>
              <Input value={form.mobile} onChange={(e) => set('mobile', e.target.value)} />
            </div>
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
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label>Country</Label>
              <Input value={form.country} onChange={(e) => set('country', e.target.value)} />
            </div>
            <div>
              <Label>Account type</Label>
              <Select value={form.account_type} onChange={(e) => set('account_type', e.target.value)}>
                <option value="personal">Personal</option>
                <option value="business">Business</option>
              </Select>
            </div>
          </div>
          <div>
            <Label>Referral App ID (optional)</Label>
            <Input placeholder="MYPA-100001" value={form.referral_app_id} onChange={(e) => set('referral_app_id', e.target.value)} />
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
