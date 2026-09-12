import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Cake, Palette, UserRound } from 'lucide-react'
import { useOutletContext } from 'react-router-dom'
import { crm, type CrmMe } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { BirthdayWords, ThemePicker } from '../../components/ThemePicker'
import { Card, Spinner } from '../../components/ui'

type Payload = Parameters<typeof crm.appearance.set>[0]

/**
 * CRM Theme: how your screens look, and how your birthday wishes start.
 *
 * Open to everyone - picking a background needs no billing rights. What you
 * pick follows you across Netvork, the CRM and your personal screens alike.
 * The company Admin also sets what everybody starts with, below their own.
 */
export default function CrmThemePage() {
  const { me } = useOutletContext<{ me: CrmMe }>()
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const { data, isLoading } = useQuery({ queryKey: ['crm', 'appearance'], queryFn: crm.appearance.get })

  const save = useMutation({
    mutationFn: (payload: Payload) => crm.appearance.set(payload),
    onSuccess: (res) => {
      queryClient.setQueryData(['crm', 'appearance'], res.data)
      // The personal shell and the birthday popup read the same choices.
      queryClient.invalidateQueries({ queryKey: ['theme'] })
      queryClient.invalidateQueries({ queryKey: ['crm', 'birthdays-today'] })
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (isLoading || !data) {
    return <div className="flex justify-center py-12"><Spinner /></div>
  }

  const companyName = data.company?.name ?? me?.organization?.name ?? 'Company'
  const b = data.birthday

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div>
        <h1 className="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-white">
          <Palette className="size-5 text-fuchsia-500" /> CRM Theme
        </h1>
        <p className="text-sm text-slate-500">
          Your background, your sidebar, and the words your birthday wishes start with. They follow you across
          Netvork - the CRM and your personal screens.
        </p>
      </div>

      <Card>
        <SectionTitle icon={UserRound} title="My look" hint="Only you see this. Default wears whatever the company picked." />
        <ThemePicker
          background={data.mine.background}
          sidebar={data.mine.sidebar}
          busy={save.isPending}
          inherited={{
            background: data.company?.background || data.netvork.background,
            sidebar: data.company?.sidebar || data.netvork.sidebar,
            from: data.company?.background || data.company?.sidebar ? companyName : 'Netvork',
          }}
          onPick={(change) => save.mutate({ scope: 'me', ...change })}
        />
      </Card>

      <Card>
        <SectionTitle icon={Cake} title="My birthday words" hint="What your wishes, belated wishes and thank-yous say before you edit them." />
        <BirthdayWords
          wish={b.mine.wish}
          reply={b.mine.reply}
          belated={b.mine.belated}
          fallbackWish={b.company?.wish || b.netvork.wish || b.built_in.wish}
          fallbackReply={b.company?.reply || b.netvork.reply || b.built_in.reply}
          fallbackBelated={b.company?.belated || b.netvork.belated || b.built_in.belated}
          busy={save.isPending}
          onSave={(w) => save.mutate({ scope: 'me', default_wish: w.wish, default_reply: w.reply, default_belated: w.belated })}
        />
      </Card>

      {data.can_edit_company && (
        <>
          <Card className="ring-emerald-200 dark:ring-emerald-900/60">
            <SectionTitle
              icon={Building2}
              title={`${companyName} default look`}
              hint="What everybody in the company sees until they pick their own. Admin only; recorded in the activity log."
            />
            <ThemePicker
              background={data.company?.background ?? null}
              sidebar={data.company?.sidebar ?? null}
              busy={save.isPending}
              inherited={{ background: data.netvork.background, sidebar: data.netvork.sidebar, from: 'Netvork' }}
              onPick={(change) => save.mutate({ scope: 'company', ...change })}
            />
          </Card>

          <Card className="ring-emerald-200 dark:ring-emerald-900/60">
            <SectionTitle
              icon={Cake}
              title={`${companyName} default birthday words`}
              hint="Used by everybody who has not written their own."
            />
            <BirthdayWords
              wish={b.company?.wish ?? null}
              reply={b.company?.reply ?? null}
              belated={b.company?.belated ?? null}
              fallbackWish={b.netvork.wish || b.built_in.wish}
              fallbackReply={b.netvork.reply || b.built_in.reply}
              fallbackBelated={b.netvork.belated || b.built_in.belated}
              busy={save.isPending}
              onSave={(w) => save.mutate({ scope: 'company', default_wish: w.wish, default_reply: w.reply, default_belated: w.belated })}
            />
          </Card>
        </>
      )}
    </div>
  )
}

function SectionTitle({ icon: Icon, title, hint }: { icon: typeof Palette; title: string; hint: string }) {
  return (
    <div>
      <h2 className="flex items-center gap-1.5 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <Icon className="size-4 text-slate-400" /> {title}
      </h2>
      <p className="mt-0.5 text-xs text-slate-400">{hint}</p>
    </div>
  )
}
