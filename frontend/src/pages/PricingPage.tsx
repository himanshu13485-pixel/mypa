import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Check, Sparkles } from 'lucide-react'
import { clsx } from 'clsx'
import { subscription as subscriptionApi } from '../api/endpoints'
import { useAuthStore } from '../stores/auth'
import { Button, Card, Spinner } from '../components/ui'
import CheckoutDialog from '../components/CheckoutDialog'
import type { PlanInfo } from '../types'

function formatLimit(key: string, value: number | null): string {
  if (value === null) return 'Unlimited'
  if (key === 'storage_bytes') {
    const gb = value / (1024 * 1024 * 1024)
    return gb >= 1 ? `${gb} GB` : `${Math.round(value / (1024 * 1024))} MB`
  }
  return String(value)
}

const LIMIT_LABELS: Record<string, string> = {
  max_tasks: 'tasks',
  storage_bytes: 'storage',
  max_groups: 'groups',
  max_group_members: 'members per group',
  max_categories: 'custom categories',
}

const FEATURE_LABELS: Record<string, string> = {
  calls: 'Audio & video calls',
  reports_export: 'Report exports',
  subadmins: 'Subadmin accounts',
  voice_assistant: 'Voice assistant',
}

export default function PricingPage() {
  const navigate = useNavigate()
  const token = useAuthStore((s) => s.token)
  const [annual, setAnnual] = useState(true)
  const [checkoutPlan, setCheckoutPlan] = useState<PlanInfo | null>(null)

  const { data: plans, isLoading } = useQuery({ queryKey: ['plans'], queryFn: subscriptionApi.plans })
  const { data: mySub } = useQuery({
    queryKey: ['my-subscription'],
    queryFn: subscriptionApi.mine,
    enabled: !!token,
  })

  const subscribe = (plan: PlanInfo) => {
    if (!token) {
      navigate('/login')
      return
    }
    setCheckoutPlan(plan)
  }

  return (
    <div className="mx-auto max-w-6xl space-y-8 p-4 lg:p-8">
      <div className="text-center">
        <h1 className="text-2xl font-bold">Plans & pricing</h1>
        <p className="mt-1 text-sm text-slate-500">
          Start free. Upgrade when your family or team grows.
        </p>

        {/* Monthly / annual toggle */}
        <div className="mt-4 inline-flex items-center gap-3 rounded-full border border-slate-200 p-1 dark:border-slate-700">
          <button
            className={clsx('rounded-full px-4 py-1.5 text-sm', !annual && 'bg-brand-600 text-white')}
            onClick={() => setAnnual(false)}
          >
            Monthly
          </button>
          <button
            className={clsx('rounded-full px-4 py-1.5 text-sm', annual && 'bg-brand-600 text-white')}
            onClick={() => setAnnual(true)}
          >
            Annual
          </button>
        </div>
      </div>

      {isLoading ? (
        <Spinner />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
          {plans?.map((plan) => {
            const monthly = Number(plan.monthly_price)
            const yearly = Number(plan.annual_price)
            const effectiveMonthly = annual && yearly > 0 ? yearly / 12 : monthly
            const savings = annual && monthly > 0 ? Math.max(0, monthly * 12 - yearly) : 0
            const isCurrent = mySub?.plan.slug === plan.slug
            const isFree = monthly === 0

            return (
              <Card
                key={plan.slug}
                className={clsx('relative flex flex-col', plan.is_recommended && 'ring-2 ring-brand-500')}
              >
                {plan.is_recommended && (
                  <span className="absolute -top-2.5 left-1/2 flex -translate-x-1/2 items-center gap-1 rounded-full bg-brand-600 px-3 py-0.5 text-[11px] font-semibold text-white">
                    <Sparkles className="size-3" /> Recommended
                  </span>
                )}
                <h2 className="text-base font-semibold">{plan.name}</h2>
                <p className="mt-0.5 min-h-8 text-xs text-slate-500">{plan.description}</p>

                <div className="mt-3">
                  <span className="text-2xl font-bold">
                    {isFree ? 'Free' : `₹${annual ? Math.round(effectiveMonthly) : monthly}`}
                  </span>
                  {!isFree && <span className="text-xs text-slate-400"> /month</span>}
                  {!isFree && annual && (
                    <p className="text-[11px] text-slate-400">
                      ₹{yearly} billed yearly
                      {savings > 0 && <span className="text-emerald-600"> · save ₹{savings}</span>}
                    </p>
                  )}
                  {!isFree && plan.trial_days > 0 && (
                    <p className="text-[11px] text-brand-600">{plan.trial_days}-day free trial</p>
                  )}
                </div>

                <ul className="mt-4 flex-1 space-y-1.5 text-xs">
                  {Object.entries(plan.limits ?? {}).map(([key, value]) => (
                    <li key={key} className="flex items-start gap-1.5">
                      <Check className="mt-0.5 size-3 shrink-0 text-emerald-500" />
                      {formatLimit(key, value)} {LIMIT_LABELS[key] ?? key.replaceAll('_', ' ')}
                    </li>
                  ))}
                  {Object.entries(plan.features ?? {})
                    .filter(([key, enabled]) => enabled && FEATURE_LABELS[key])
                    .map(([key]) => (
                      <li key={key} className="flex items-start gap-1.5">
                        <Check className="mt-0.5 size-3 shrink-0 text-emerald-500" />
                        {FEATURE_LABELS[key]}
                      </li>
                    ))}
                </ul>

                <div className="mt-4">
                  {isCurrent ? (
                    <Button variant="secondary" className="w-full" disabled>
                      Current plan
                    </Button>
                  ) : isFree ? (
                    <Button variant="secondary" className="w-full" onClick={() => navigate(token ? '/settings' : '/register')}>
                      {token ? 'Included' : 'Get started'}
                    </Button>
                  ) : (
                    <Button className="w-full" onClick={() => subscribe(plan)}>
                      {mySub && mySub.plan.slug !== 'free' ? 'Switch plan' : 'Subscribe'}
                    </Button>
                  )}
                </div>
              </Card>
            )
          })}
        </div>
      )}

      <p className="text-center text-xs text-slate-400">
        Prices in INR, exclusive of GST. Payments are processed securely by Cashfree.
        Cancel anytime — your plan stays active until the end of the paid period.
      </p>

      {checkoutPlan && (
        <CheckoutDialog
          plan={checkoutPlan}
          frequency={annual ? 'annual' : 'monthly'}
          onClose={() => setCheckoutPlan(null)}
        />
      )}
    </div>
  )
}
