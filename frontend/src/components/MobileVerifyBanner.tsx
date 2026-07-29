import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { auth } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { Button, Input } from './ui'

/**
 * Shown until the mobile number is verified. The OTP is delivered app-to-app:
 * it appears in the user's notification bell (and admins can view/resend it).
 */
export default function MobileVerifyBanner() {
  const { user, setUser } = useAuthStore()
  const queryClient = useQueryClient()
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<string | null>(null)

  if (!user || user.mobile_verified !== false) return null

  const verify = async () => {
    if (!code.trim()) return
    setBusy(true)
    setMessage(null)
    try {
      await auth.verifyMobile(code.trim())
      const fresh = await auth.me()
      setUser(fresh)
      queryClient.invalidateQueries({ queryKey: ['badges'] })
    } catch (err) {
      setMessage(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  const resend = async () => {
    setBusy(true)
    setMessage(null)
    try {
      await auth.resendMobileOtp()
      setMessage('A new code is in your notifications (bell icon).')
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
      queryClient.invalidateQueries({ queryKey: ['notifications-count'] })
    } catch (err) {
      setMessage(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="border-b border-brand-200 bg-brand-50 px-4 py-2 dark:border-brand-900 dark:bg-brand-950">
      <div className="mx-auto flex max-w-3xl flex-wrap items-center justify-center gap-2 text-xs text-brand-800 dark:text-brand-200">
        <span>
          Verify your mobile <b>{user.mobile}</b> — the code is in your <b>notifications</b> (🔔).
        </span>
        <Input
          className="h-7 w-28 py-1 text-center tracking-widest"
          placeholder="123456"
          inputMode="numeric"
          maxLength={6}
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
          onKeyDown={(e) => e.key === 'Enter' && verify()}
        />
        <Button size="sm" onClick={verify} disabled={busy || code.length < 6}>
          Verify
        </Button>
        <button className="underline" onClick={resend} disabled={busy}>
          Resend code
        </button>
        {message && <span className="w-full text-center">{message}</span>}
      </div>
    </div>
  )
}
