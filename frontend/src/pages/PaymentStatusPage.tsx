import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { CheckCircle2, Clock, XCircle } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { subscription as subscriptionApi } from '../api/endpoints'
import { Button, Card, Spinner } from '../components/ui'

type Status = 'verifying' | 'paid' | 'failed' | 'pending'

export default function PaymentStatusPage() {
  const [params] = useSearchParams()
  const queryClient = useQueryClient()
  const orderUuid = params.get('order')
  const [status, setStatus] = useState<Status>('verifying')
  const [plan, setPlan] = useState<string | null>(null)
  const attemptsRef = useRef(0)

  useEffect(() => {
    if (!orderUuid) {
      setStatus('failed')
      return
    }

    let cancelled = false

    const poll = async () => {
      try {
        const result = await subscriptionApi.verify(orderUuid)
        if (cancelled) return
        setPlan(result.plan)

        if (result.status === 'paid') {
          setStatus('paid')
          queryClient.invalidateQueries({ queryKey: ['my-subscription'] })
          return
        }
        if (['failed', 'cancelled', 'expired'].includes(result.status)) {
          setStatus('failed')
          return
        }
        // Still pending at the gateway — poll a few times (webhook may land first).
        attemptsRef.current += 1
        if (attemptsRef.current < 10) {
          setStatus('pending')
          setTimeout(poll, 3000)
        } else {
          setStatus('pending')
        }
      } catch {
        if (!cancelled) setStatus('failed')
      }
    }

    poll()

    return () => {
      cancelled = true
    }
  }, [orderUuid, queryClient])

  return (
    <div className="flex min-h-full items-center justify-center p-4">
      <Card className="w-full max-w-md p-8 text-center">
        {status === 'verifying' && (
          <>
            <Spinner />
            <p className="text-sm text-slate-500">Verifying your payment securely…</p>
          </>
        )}

        {status === 'pending' && (
          <>
            <Clock className="mx-auto size-12 text-amber-500" />
            <h1 className="mt-3 text-lg font-semibold">Payment processing</h1>
            <p className="mt-1 text-sm text-slate-500">
              Your payment is being confirmed by the bank. This can take a minute —
              we'll activate your plan automatically the moment it clears.
            </p>
            <Link to="/settings">
              <Button variant="secondary" className="mt-4">Go to Settings</Button>
            </Link>
          </>
        )}

        {status === 'paid' && (
          <>
            <CheckCircle2 className="mx-auto size-12 text-emerald-500" />
            <h1 className="mt-3 text-lg font-semibold">Payment successful 🎉</h1>
            <p className="mt-1 text-sm text-slate-500">
              Your <span className="font-semibold capitalize">{plan}</span> plan is now active.
              An invoice has been added to your account.
            </p>
            <div className="mt-4 flex justify-center gap-2">
              <Link to="/"><Button>Go to dashboard</Button></Link>
              <Link to="/settings"><Button variant="secondary">View subscription</Button></Link>
            </div>
          </>
        )}

        {status === 'failed' && (
          <>
            <XCircle className="mx-auto size-12 text-red-500" />
            <h1 className="mt-3 text-lg font-semibold">Payment not completed</h1>
            <p className="mt-1 text-sm text-slate-500">
              No plan change was made. If money was deducted, it will be refunded
              automatically by the payment provider.
            </p>
            <div className="mt-4 flex justify-center gap-2">
              <Link to="/pricing"><Button>Try again</Button></Link>
              <Link to="/"><Button variant="secondary">Dashboard</Button></Link>
            </div>
          </>
        )}
      </Card>
    </div>
  )
}
