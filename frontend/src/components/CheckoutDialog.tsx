import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Loader2, Lock } from 'lucide-react'
import { subscription as subscriptionApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { Button, ErrorNote, Input, Label, Modal } from './ui'
import type { BillingQuote, PlanInfo } from '../types'

declare global {
  interface Window {
    Cashfree?: (config: { mode: string }) => {
      checkout: (options: { paymentSessionId: string; redirectTarget: string }) => Promise<unknown>
    }
  }
}

/** Load the official Cashfree JS SDK once. */
function loadCashfreeSdk(): Promise<void> {
  if (window.Cashfree) return Promise.resolve()
  return new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = 'https://sdk.cashfree.com/js/v3/cashfree.js'
    script.onload = () => resolve()
    script.onerror = () => reject(new Error('Could not load the payment SDK.'))
    document.head.appendChild(script)
  })
}

export default function CheckoutDialog({
  plan,
  frequency,
  onClose,
}: {
  plan: PlanInfo
  frequency: 'monthly' | 'annual'
  onClose: () => void
}) {
  const navigate = useNavigate()
  const [coupon, setCoupon] = useState('')
  const [appliedCoupon, setAppliedCoupon] = useState<string | undefined>(undefined)
  const [quote, setQuote] = useState<BillingQuote | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [paying, setPaying] = useState(false)
  const [accepted, setAccepted] = useState(false)

  const refreshQuote = async (couponCode?: string) => {
    setBusy(true)
    setError(null)
    try {
      const data = await subscriptionApi.quote(plan.slug, frequency, couponCode)
      setQuote(data)
      setAppliedCoupon(data.coupon_applied ?? undefined)
    } catch (err) {
      setError(errorMessage(err))
      // Keep the last good quote (without the failing coupon).
      if (couponCode) {
        setAppliedCoupon(undefined)
        subscriptionApi.quote(plan.slug, frequency).then(setQuote).catch(() => undefined)
      }
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    refreshQuote()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [plan.slug, frequency])

  const pay = async () => {
    setPaying(true)
    setError(null)
    try {
      const session = await subscriptionApi.checkout(plan.slug, frequency, appliedCoupon)
      await loadCashfreeSdk()
      if (!window.Cashfree) throw new Error('Payment SDK unavailable.')

      const cashfree = window.Cashfree({ mode: session.gateway_mode })
      // Opens the hosted Cashfree checkout; on completion Cashfree redirects to
      // /payment/status?order=… where the backend verifies the payment.
      await cashfree.checkout({
        paymentSessionId: session.payment_session_id,
        redirectTarget: '_self',
      })
      // Popup-mode fallback: if we're still here, go verify.
      navigate(`/payment/status?order=${session.order_uuid}`)
    } catch (err) {
      setError(errorMessage(err))
      setPaying(false)
    }
  }

  return (
    <Modal title={`Subscribe to ${plan.name}`} onClose={onClose}>
      <div className="space-y-4">
        <ErrorNote message={error} />

        {/* Order summary */}
        <div className="rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
          <div className="flex justify-between">
            <span>{plan.name} plan — {frequency}</span>
            <span>₹{quote?.base ?? '…'}</span>
          </div>
          {quote && Number(quote.discount) > 0 && (
            <div className="flex justify-between text-emerald-600">
              <span>Coupon {quote.coupon_applied}</span>
              <span>− ₹{quote.discount}</span>
            </div>
          )}
          {quote && (
            <div className="flex justify-between text-slate-500">
              <span>{quote.tax_label} ({quote.tax_percent}%)</span>
              <span>₹{quote.tax}</span>
            </div>
          )}
          <div className="mt-2 flex justify-between border-t border-slate-200 pt-2 font-semibold dark:border-slate-700">
            <span>Total</span>
            <span>₹{quote?.total ?? '…'}</span>
          </div>
        </div>

        {/* Coupon */}
        <div>
          <Label>Coupon code (optional)</Label>
          <div className="flex gap-2">
            <Input
              value={coupon}
              onChange={(e) => setCoupon(e.target.value.toUpperCase())}
              placeholder="WELCOME10"
            />
            <Button
              type="button"
              variant="secondary"
              disabled={busy || !coupon.trim()}
              onClick={() => refreshQuote(coupon.trim())}
            >
              Apply
            </Button>
          </div>
        </div>

        <label className="flex items-start gap-2 text-xs text-slate-500">
          <input type="checkbox" checked={accepted} onChange={(e) => setAccepted(e.target.checked)} className="mt-0.5" />
          <span>
            I agree to the subscription terms: billing is {frequency}, access continues until the
            end of the paid period after cancellation, and refunds follow the refund policy.
          </span>
        </label>

        <Button className="w-full" disabled={!quote || busy || paying || !accepted} onClick={pay}>
          {paying ? (
            <>
              <Loader2 className="size-4 animate-spin" /> Opening secure checkout…
            </>
          ) : (
            <>
              <Lock className="size-4" /> Pay ₹{quote?.total ?? ''} with Cashfree
            </>
          )}
        </Button>

        <p className="text-center text-[11px] text-slate-400">
          Card details are entered on Cashfree's secure page — Netvork never sees them.
        </p>
      </div>
    </Modal>
  )
}
