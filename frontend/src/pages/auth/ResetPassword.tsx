import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { auth } from '../../api/endpoints'
import { errorMessage } from '../../api/client'
import { Button, ErrorNote, Input, Label } from '../../components/ui'

export default function ResetPassword() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      await auth.resetPassword({
        token: params.get('token') ?? '',
        email: params.get('email') ?? '',
        password,
        password_confirmation: confirmation,
      })
      navigate('/login')
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-full items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <h1 className="mb-4 text-center text-xl font-semibold">Choose a new password</h1>
        <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <ErrorNote message={error} />
          <div>
            <Label>New password</Label>
            <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required autoFocus />
          </div>
          <div>
            <Label>Confirm password</Label>
            <Input type="password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} required />
          </div>
          <Button type="submit" disabled={loading} className="w-full">
            {loading ? 'Saving…' : 'Reset password'}
          </Button>
          <p className="text-center text-xs">
            <Link to="/login" className="text-brand-600 hover:underline">
              Back to sign in
            </Link>
          </p>
        </form>
      </div>
    </div>
  )
}
