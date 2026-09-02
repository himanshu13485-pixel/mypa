import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api } from '../api/client'
import { Avatar } from '../lib/avatars'
import { Button, Card } from '../components/ui'
import { useAuthStore } from '../stores/auth'

interface Inviter {
  name: string
  username: string | null
  avatar: string | null
}

/**
 * The page an invite link opens.
 *
 * It is public on purpose: whoever follows it has no account, which is the
 * whole reason the link exists. It says who invited them and offers the two
 * doors — sign up, carrying the code so the pair end up connected, or sign
 * in if it turns out they were here all along.
 */
export default function InvitePage() {
  const { code = '' } = useParams()
  const navigate = useNavigate()
  const signedIn = useAuthStore((s) => !!s.token)

  const [inviter, setInviter] = useState<Inviter | null>(null)
  const [state, setState] = useState<'loading' | 'ready' | 'bad'>('loading')

  useEffect(() => {
    let alive = true

    api.get<{ data: Inviter }>(`/invite/${code}`)
      .then((r) => { if (alive) { setInviter(r.data.data); setState('ready') } })
      .catch(() => { if (alive) setState('bad') })

    return () => { alive = false }
  }, [code])

  /*
   * Somebody already signed in followed the link.
   *
   * They do not need an invitation to a place they are already in, so this
   * hands them to the screen where they can act on it rather than showing a
   * sign-up they cannot use.
   */
  useEffect(() => {
    if (signedIn && state === 'ready') navigate('/connections', { replace: true })
  }, [signedIn, state, navigate])

  if (state === 'loading') {
    return (
      <div className="flex min-h-dvh items-center justify-center p-6">
        <p className="text-sm text-slate-400">Opening the invitation…</p>
      </div>
    )
  }

  if (state === 'bad' || !inviter) {
    return (
      <div className="flex min-h-dvh items-center justify-center p-6">
        <Card className="w-full max-w-sm text-center">
          <h1 className="text-lg font-semibold">This invitation has expired</h1>
          <p className="mt-1 text-sm text-slate-500">
            The link may have been withdrawn, or copied incompletely. Ask whoever sent it for a new one.
          </p>
          <Link to="/login" className="mt-4 inline-block text-sm text-brand-600 underline">
            Sign in instead
          </Link>
        </Card>
      </div>
    )
  }

  return (
    <div className="flex min-h-dvh items-center justify-center p-6">
      <Card className="w-full max-w-sm text-center">
        <div className="flex justify-center">
          <Avatar name={inviter.name} avatar={inviter.avatar} size={64} />
        </div>

        <h1 className="mt-3 text-lg font-semibold">{inviter.name} invited you to Netvork</h1>
        {inviter.username && (
          <p className="text-sm text-slate-400">@{inviter.username}</p>
        )}
        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
          One app for messages, calls, meetings and everything the two of you are keeping track of.
          Make an account and you will be connected to {inviter.name} straight away.
        </p>

        {/* The code rides along, so the two of them are joined up at the end
            of the sign-up rather than having to find each other again. */}
        <Button className="mt-4 w-full" onClick={() => navigate(`/register?invite=${encodeURIComponent(code)}`)}>
          Create your account
        </Button>

        <p className="mt-3 text-xs text-slate-400">
          Already on Netvork?{' '}
          <Link to={`/login?invite=${encodeURIComponent(code)}`} className="text-brand-600 underline">
            Sign in
          </Link>
        </p>
      </Card>
    </div>
  )
}
