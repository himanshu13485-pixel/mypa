import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { groups as groupsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { Button, Card, ErrorNote } from '../components/ui'
import { useToast } from '../components/Toast'
import type { GroupJoinPreview } from '../types'

/**
 * The page a group's invite link opens.
 *
 * Behind the auth guard, because joining a group is something an account
 * does — a link handed to a stranger with no Netvork account sends them to
 * sign in first and lands them back here.
 *
 * What it says depends on what the link actually does. A group whose link
 * admits says "Join"; one whose link asks says so before the tap rather
 * than after it, because "you are in" and "you have been put in a queue"
 * are different enough that finding out afterwards feels like a bait.
 */
export default function JoinGroupPage() {
  const { token = '' } = useParams()
  const navigate = useNavigate()
  const { toast } = useToast()

  const [preview, setPreview] = useState<GroupJoinPreview | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [joining, setJoining] = useState(false)

  useEffect(() => {
    let alive = true

    groupsApi.previewJoin(token)
      .then((p) => { if (alive) setPreview(p) })
      .catch((err) => { if (alive) setError(errorMessage(err)) })
      .finally(() => { if (alive) setLoading(false) })

    return () => { alive = false }
  }, [token])

  const join = async () => {
    setJoining(true)
    setError(null)
    try {
      const res = await groupsApi.join(token)
      toast(res.message, 'success')
      // Straight to the group either way: somebody admitted wants the group,
      // and somebody queued wants to see that the ask registered.
      navigate('/groups')
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setJoining(false)
    }
  }

  if (loading) {
    return (
      <div className="flex min-h-[60vh] items-center justify-center">
        <p className="text-sm text-slate-400">Opening the invitation…</p>
      </div>
    )
  }

  if (!preview) {
    return (
      <div className="flex min-h-[60vh] items-center justify-center p-6">
        <Card className="w-full max-w-sm text-center">
          <h1 className="text-lg font-semibold">This invite link is no longer active</h1>
          <p className="mt-1 text-sm text-slate-500">
            It may have been turned off or replaced. Ask an admin of the group for a new one.
          </p>
          <Button className="mt-4" variant="secondary" onClick={() => navigate('/groups')}>
            Back to your groups
          </Button>
        </Card>
      </div>
    )
  }

  const asks = preview.mode === 'request'

  return (
    <div className="flex min-h-[60vh] items-center justify-center p-6">
      <Card className="w-full max-w-sm text-center">
        <p className="text-xs uppercase tracking-wide text-slate-400">{preview.type} group</p>
        <h1 className="mt-1 text-lg font-semibold">{preview.name}</h1>
        <p className="text-sm text-slate-400">
          {preview.member_count} {preview.member_count === 1 ? 'member' : 'members'}
        </p>
        {preview.description && (
          <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">{preview.description}</p>
        )}

        <ErrorNote message={error} />

        {preview.already_member ? (
          <>
            <p className="mt-4 text-sm text-emerald-600 dark:text-emerald-400">You are already in this group.</p>
            <Button className="mt-3 w-full" onClick={() => navigate('/groups')}>Open it</Button>
          </>
        ) : preview.already_requested ? (
          <>
            <p className="mt-4 text-sm text-amber-600 dark:text-amber-400">
              You have asked to join. An admin will decide, and you will hear either way.
            </p>
            <Button className="mt-3 w-full" variant="secondary" onClick={() => navigate('/groups')}>
              Back to your groups
            </Button>
          </>
        ) : (
          <>
            {/* Said before the tap, not after it. */}
            <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">
              {asks
                ? 'This group is reviewed. Asking puts you in front of its admins, who decide.'
                : 'Anyone with this link can join. You will be in straight away.'}
            </p>
            <Button className="mt-3 w-full" disabled={joining} onClick={join}>
              {joining ? 'One moment…' : asks ? 'Ask to join' : 'Join group'}
            </Button>
          </>
        )}
      </Card>
    </div>
  )
}
