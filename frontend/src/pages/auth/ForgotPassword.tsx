import { useState } from 'react'
import { Link } from 'react-router-dom'
import { auth } from '../../api/endpoints'
import { errorMessage } from '../../api/client'
import { Button, ErrorNote, Input, Label } from '../../components/ui'

export default function ForgotPassword() {
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      await auth.forgotPassword(email)
      setSent(true)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-full items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <h1 className="mb-4 text-center text-xl font-semibold">Reset your password</h1>
        <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          {sent ? (
            <p className="text-sm text-emerald-600">
              If that email exists, a reset link has been sent. Check your inbox.
            </p>
          ) : (
            <>
              <ErrorNote message={error} />
              <div>
                <Label>Email</Label>
                <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />
              </div>
              <Button type="submit" disabled={loading} className="w-full">
                {loading ? 'Sending…' : 'Send reset link'}
              </Button>
            </>
          )}
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
