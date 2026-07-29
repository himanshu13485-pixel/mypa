import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { auth, profile as profileApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { Button, Card, ErrorNote, Input, Label, Select } from '../components/ui'

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
    },
    onError: (err) => setMsg('password', errorMessage(err)),
  })

  return (
    <div className="max-w-3xl space-y-6">
      <h1 className="text-lg font-semibold">Settings</h1>

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
