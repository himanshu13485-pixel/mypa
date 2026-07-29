import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { auth, profile as profileApi, subscription as subscriptionApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { Button, Card, ErrorNote, Input, Label, Select } from '../components/ui'

function BillingHistory() {
  const { data: payments } = useQuery({ queryKey: ['my-payments'], queryFn: subscriptionApi.payments })
  const { data: invoices } = useQuery({ queryKey: ['my-invoices'], queryFn: subscriptionApi.invoices })

  const openInvoice = async (uuid: string) => {
    const token = useAuthStore.getState().token
    const res = await fetch(subscriptionApi.invoiceUrl(uuid), { headers: { Authorization: `Bearer ${token}` } })
    const html = await res.text()
    const win = window.open('', '_blank')
    win?.document.write(html)
    win?.document.close()
  }

  if (!payments?.data.length && !invoices?.data.length) return null

  return (
    <Card>
      <h2 className="mb-3 text-sm font-semibold">Billing history</h2>
      {payments?.data.length ? (
        <div className="space-y-1.5">
          {payments.data.map((payment) => (
            <div
              key={payment.uuid}
              className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700"
            >
              <div>
                <p className="font-medium">
                  {payment.plan} · {payment.frequency}
                  <span
                    className={
                      payment.status === 'successful'
                        ? 'ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                        : 'ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300'
                    }
                  >
                    {payment.status.replaceAll('_', ' ')}
                  </span>
                </p>
                <p className="text-xs text-slate-400">
                  {payment.order_number}
                  {payment.paid_at ? ` · ${new Date(payment.paid_at).toLocaleDateString()}` : ''}
                  {Number(payment.refunded) > 0 ? ` · refunded ₹${payment.refunded}` : ''}
                </p>
              </div>
              <div className="flex items-center gap-3">
                <span className="font-semibold">₹{payment.amount}</span>
                {payment.invoice_uuid && (
                  <button
                    className="text-xs text-brand-600 hover:underline"
                    onClick={() => openInvoice(payment.invoice_uuid!)}
                  >
                    Invoice
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      ) : (
        <p className="text-xs text-slate-400">No payments yet.</p>
      )}
    </Card>
  )
}

const PRIVACY_FIELDS: { key: string; label: string }[] = [
  { key: 'who_can_find_me', label: 'Who can find me by App ID' },
  { key: 'who_can_connect', label: 'Who can send connection requests' },
  { key: 'who_can_message', label: 'Who can message me' },
  { key: 'who_can_call', label: 'Who can call me' },
  { key: 'profile_photo_visibility', label: 'Who can view my profile photo' },
  { key: 'online_status_visibility', label: 'Who can see my online status' },
  { key: 'last_seen_visibility', label: 'Who can see my last seen' },
]

export default function SettingsPage() {
  const { user, setUser } = useAuthStore()
  const [profileForm, setProfileForm] = useState({
    name: user?.name ?? '',
    mobile: user?.mobile ?? '',
    country: user?.profile?.country ?? '',
    timezone: user?.profile?.timezone ?? 'Asia/Kolkata',
    bio: user?.profile?.bio ?? '',
  })
  const [privacy, setPrivacy] = useState<Record<string, string>>(
    (user?.settings?.privacy as Record<string, string>) ?? {},
  )
  const [passwordForm, setPasswordForm] = useState({
    current_password: '',
    password: '',
    password_confirmation: '',
  })
  const [messages, setMessages] = useState<Record<string, string | null>>({})

  const setMsg = (key: string, value: string | null) => setMessages((m) => ({ ...m, [key]: value }))

  const profileMutation = useMutation({
    mutationFn: () => profileApi.update(profileForm),
    onSuccess: (updated) => {
      setUser(updated)
      setMsg('profile', 'Profile saved.')
    },
    onError: (err) => setMsg('profile', errorMessage(err)),
  })

  const privacyMutation = useMutation({
    mutationFn: () => profileApi.updateSettings({ privacy }),
    onSuccess: (updated) => {
      setUser(updated)
      setMsg('privacy', 'Privacy settings saved.')
    },
    onError: (err) => setMsg('privacy', errorMessage(err)),
  })

  const passwordMutation = useMutation({
    mutationFn: () => auth.changePassword(passwordForm),
    onSuccess: () => {
      setPasswordForm({ current_password: '', password: '', password_confirmation: '' })
      setMsg('password', 'Password changed. Other sessions were signed out.')
      // Refresh the user so the "change your password" banner clears.
      auth.me().then(setUser).catch(() => undefined)
    },
    onError: (err) => setMsg('password', errorMessage(err)),
  })

  const { data: mySub } = useQuery({ queryKey: ['my-subscription'], queryFn: subscriptionApi.mine })

  const formatBytes = (bytes: number) => {
    if (bytes === 0) return '0 B'
    const units = ['B', 'KB', 'MB', 'GB']
    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)))
    return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`
  }

  return (
    <div className="max-w-3xl space-y-6">
      <h1 className="text-lg font-semibold">Settings</h1>

      {mySub && (
        <Card>
          <div className="mb-3 flex items-center justify-between">
            <h2 className="text-sm font-semibold">Subscription</h2>
            <span className="rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700 dark:bg-brand-950 dark:text-brand-300">
              {mySub.plan.name} plan
            </span>
          </div>
          {mySub.plan.description && (
            <p className="mb-3 text-xs text-slate-500">{mySub.plan.description}</p>
          )}
          <div className="space-y-3">
            {Object.entries(mySub.usage).map(([key, { used, limit }]) => {
              const isStorage = key === 'storage'
              const pct = limit ? Math.min(100, (used / limit) * 100) : 0
              return (
                <div key={key}>
                  <div className="mb-1 flex justify-between text-xs text-slate-500">
                    <span className="capitalize">{key}</span>
                    <span>
                      {isStorage ? formatBytes(used) : used}
                      {' / '}
                      {limit === null ? 'unlimited' : isStorage ? formatBytes(limit) : limit}
                    </span>
                  </div>
                  <div className="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div
                      className={pct >= 90 ? 'h-full rounded-full bg-red-500' : 'h-full rounded-full bg-brand-500'}
                      style={{ width: limit === null ? '4%' : `${pct}%` }}
                    />
                  </div>
                </div>
              )
            })}
          </div>
          <div className="mt-4 flex flex-wrap items-center gap-2">
            <a href="/pricing">
              <Button size="sm">{mySub.plan.slug === 'free' ? 'Upgrade plan' : 'Change plan'}</Button>
            </a>
            {mySub.plan.slug !== 'free' && mySub.ends_at && (
              <>
                <span className="text-xs text-slate-400">
                  Renews/expires {new Date(mySub.ends_at).toLocaleDateString()}
                </span>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => {
                    if (confirm('Cancel your subscription? Your plan stays active until the end of the paid period.')) {
                      subscriptionApi.cancel().then((res) => alert((res as { message?: string }).message ?? 'Cancelled.'))
                    }
                  }}
                >
                  Cancel subscription
                </Button>
              </>
            )}
          </div>
        </Card>
      )}

      <BillingHistory />

      <Card>
        <h2 className="mb-3 text-sm font-semibold">Profile</h2>
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Full name</Label>
            <Input value={profileForm.name} onChange={(e) => setProfileForm({ ...profileForm, name: e.target.value })} />
          </div>
          <div>
            <Label>Mobile</Label>
            <Input value={profileForm.mobile ?? ''} onChange={(e) => setProfileForm({ ...profileForm, mobile: e.target.value })} />
          </div>
          <div>
            <Label>Country</Label>
            <Input value={profileForm.country} onChange={(e) => setProfileForm({ ...profileForm, country: e.target.value })} />
          </div>
          <div>
            <Label>Timezone</Label>
            <Input value={profileForm.timezone} onChange={(e) => setProfileForm({ ...profileForm, timezone: e.target.value })} />
          </div>
          <div className="sm:col-span-2">
            <Label>Bio</Label>
            <Input value={profileForm.bio ?? ''} onChange={(e) => setProfileForm({ ...profileForm, bio: e.target.value })} />
          </div>
        </div>
        <div className="mt-3 flex items-center gap-3">
          <Button onClick={() => profileMutation.mutate()} disabled={profileMutation.isPending}>
            Save profile
          </Button>
          {messages.profile && <span className="text-xs text-slate-500">{messages.profile}</span>}
        </div>
      </Card>

      <Card>
        <h2 className="mb-3 text-sm font-semibold">Privacy</h2>
        <div className="grid gap-3 sm:grid-cols-2">
          {PRIVACY_FIELDS.map(({ key, label }) => (
            <div key={key}>
              <Label>{label}</Label>
              <Select value={privacy[key] ?? 'everyone'} onChange={(e) => setPrivacy({ ...privacy, [key]: e.target.value })}>
                <option value="everyone">Everyone</option>
                <option value="connections">My connections</option>
                <option value="nobody">Nobody</option>
              </Select>
            </div>
          ))}
        </div>
        <div className="mt-3 flex items-center gap-3">
          <Button onClick={() => privacyMutation.mutate()} disabled={privacyMutation.isPending}>
            Save privacy
          </Button>
          {messages.privacy && <span className="text-xs text-slate-500">{messages.privacy}</span>}
        </div>
      </Card>

      <Card>
        <h2 className="mb-3 text-sm font-semibold">Change password</h2>
        <ErrorNote message={messages.password?.includes('.') && !messages.password.startsWith('Password changed') ? messages.password : null} />
        <div className="grid gap-3 sm:grid-cols-3">
          <div>
            <Label>Current password</Label>
            <Input
              type="password"
              value={passwordForm.current_password}
              onChange={(e) => setPasswordForm({ ...passwordForm, current_password: e.target.value })}
            />
          </div>
          <div>
            <Label>New password</Label>
            <Input
              type="password"
              value={passwordForm.password}
              onChange={(e) => setPasswordForm({ ...passwordForm, password: e.target.value })}
            />
          </div>
          <div>
            <Label>Confirm new password</Label>
            <Input
              type="password"
              value={passwordForm.password_confirmation}
              onChange={(e) => setPasswordForm({ ...passwordForm, password_confirmation: e.target.value })}
            />
          </div>
        </div>
        <div className="mt-3 flex items-center gap-3">
          <Button onClick={() => passwordMutation.mutate()} disabled={passwordMutation.isPending}>
            Change password
          </Button>
          {messages.password?.startsWith('Password changed') && (
            <span className="text-xs text-emerald-600">{messages.password}</span>
          )}
        </div>
      </Card>
    </div>
  )
}
