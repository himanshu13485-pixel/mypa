import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useConnectBase } from '../lib/connectBase'
import { CheckSquare, Plus, Trash2, UserPlus, Users } from 'lucide-react'
import { badges as badgesApi, groups as groupsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import UserSuggest from '../components/UserSuggest'
import { useAuthStore } from '../stores/auth'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorNote,
  Input,
  Label,
  LoadError,
  Modal,
  Select,
  SkeletonCards,
  Textarea,
} from '../components/ui'
import { GROUP_TYPES, type GroupItem } from '../types'
import { Avatar } from '../lib/avatars'

export default function GroupsPage() {
  const connectBase = useConnectBase()
  const queryClient = useQueryClient()

  // Attending this section clears its share notifications.
  useEffect(() => {
    badgesApi.readKinds(['group_added']).then(() => {
      queryClient.invalidateQueries({ queryKey: ['notifications-count'] })
    }).catch(() => undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const me = useAuthStore((s) => s.user)
  const [showCreate, setShowCreate] = useState(false)
  const [detail, setDetail] = useState<GroupItem | null>(null)
  const [form, setForm] = useState({ name: '', type: 'family', description: '' })
  const [memberAppId, setMemberAppId] = useState('')
  const [memberRole, setMemberRole] = useState('member')
  const [error, setError] = useState<string | null>(null)

  const { data: list, isLoading, isError, error: loadError, refetch } = useQuery({ queryKey: ['groups'], queryFn: groupsApi.list })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['groups'] })

  const refreshDetail = async (uuid: string) => {
    const fresh = await groupsApi.get(uuid)
    setDetail(fresh)
    invalidate()
  }

  const createMutation = useMutation({
    mutationFn: () => groupsApi.create(form),
    onSuccess: () => {
      invalidate()
      setShowCreate(false)
      setForm({ name: '', type: 'family', description: '' })
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const addMemberMutation = useMutation({
    mutationFn: () => groupsApi.addMember(detail!.uuid, memberAppId, memberRole),
    onSuccess: () => {
      setMemberAppId('')
      refreshDetail(detail!.uuid)
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const canManage = detail?.my_role === 'owner' || detail?.my_role === 'admin'

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-semibold tracking-tight">Family & Teams</h1>
        <Button onClick={() => { setError(null); setShowCreate(true) }}>
          <Plus className="size-4" /> New group
        </Button>
      </div>

      {isLoading ? (
        <SkeletonCards count={4} />
      ) : isError ? (
        <Card>
          <LoadError what="your groups" message={errorMessage(loadError)} onRetry={() => refetch()} />
        </Card>
      ) : !list?.length ? (
        <Card>
          <EmptyState
            title="No groups yet"
            hint="Create a family or team group to share tasks, events, notes, and files."
          />
        </Card>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {list.map((group) => (
            <Card key={group.uuid} className="cursor-pointer transition-shadow hover:shadow-md">
              <div onClick={() => groupsApi.get(group.uuid).then(setDetail)}>
                <div className="flex items-center gap-2">
                  <div
                    className="flex size-9 items-center justify-center rounded-lg text-white"
                    style={{ backgroundColor: group.color ?? '#406cf0' }}
                  >
                    <Users className="size-4" />
                  </div>
                  <div>
                    <h3 className="text-sm font-semibold">{group.name}</h3>
                    <p className="text-xs capitalize text-slate-400">{group.type} · {group.my_role}</p>
                  </div>
                </div>
                {group.description && (
                  <p className="mt-2 line-clamp-2 text-xs text-slate-500">{group.description}</p>
                )}
                <p className="mt-2 text-xs text-slate-400">
                  {group.members_count} member(s) · {group.tasks_count} task(s)
                </p>
              </div>
              <div className="mt-2 flex gap-3 border-t border-slate-100 pt-2 dark:border-slate-800">
                <Link
                  to={`/tasks?group=${group.uuid}`}
                  className="inline-flex items-center gap-1 text-xs text-brand-600 hover:underline"
                >
                  <CheckSquare className="size-3.5" /> Group tasks
                </Link>
                <Link
                  to={`${connectBase}/messages?group=${group.uuid}`}
                  className="inline-flex items-center gap-1 text-xs text-brand-600 hover:underline"
                >
                  <Users className="size-3.5" /> Group chat
                </Link>
              </div>
            </Card>
          ))}
        </div>
      )}

      {/* Create dialog */}
      {showCreate && (
        <Modal title="New group" onClose={() => setShowCreate(false)}>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              createMutation.mutate()
            }}
            className="space-y-4"
          >
            <ErrorNote message={error} />
            <div>
              <Label>Group name</Label>
              <Input
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                placeholder="My Family, Office Team…"
                required
                autoFocus
              />
            </div>
            <div>
              <Label>Type</Label>
              <Select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                {GROUP_TYPES.map((t) => (
                  <option key={t} value={t}>{t}</option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Description</Label>
              <Textarea rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => setShowCreate(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={createMutation.isPending}>
                Create group
              </Button>
            </div>
          </form>
        </Modal>
      )}

      {/* Detail dialog */}
      {detail && (
        <Modal title={detail.name} onClose={() => setDetail(null)} wide>
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <p className="text-xs capitalize text-slate-400">
                {detail.type} group · your role: {detail.my_role}
                {detail.only_admins_post && (
                  <span className="ml-1 font-medium text-amber-600">· announcements only</span>
                )}
              </p>
              <Link
                to={`/tasks?group=${detail.uuid}`}
                className="inline-flex items-center gap-1 text-xs text-brand-600 hover:underline"
                onClick={() => setDetail(null)}
              >
                <CheckSquare className="size-3.5" /> Group tasks
              </Link>
            </div>

            {/* An announcement group: everybody reads it, the admins write.
                Enforced on the server too — a closed group that is only
                closed in the interface is not closed. */}
            {canManage && (
              <label className="flex items-start gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60">
                <input
                  type="checkbox"
                  checked={!!detail.only_admins_post}
                  onChange={(e) => {
                    groupsApi.update(detail.uuid, { only_admins_post: e.target.checked })
                      .then(() => refreshDetail(detail.uuid))
                      .catch(() => { /* the checkbox springs back on refresh */ })
                  }}
                  className="mt-0.5 size-4 accent-brand-600"
                />
                <span>
                  Only admins can post
                  <span className="block text-xs text-slate-400">
                    Everybody still reads the group and gets its notifications; only owners and admins
                    can write. Admins can also delete anyone&rsquo;s message here.
                  </span>
                </span>
              </label>
            )}

            <div>
              <h3 className="mb-2 text-sm font-semibold">Members</h3>
              <div className="space-y-1.5">
                {detail.members?.map((member) => (
                  <div
                    key={member.uuid}
                    className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700"
                  >
                    <div className="flex min-w-0 items-center gap-3">
                      <Avatar name={member.name} photoPath={member.photo_path} avatar={member.avatar} size={34} />
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium">
                          {member.name}
                          {member.uuid === me?.uuid && <span className="text-slate-400"> (you)</span>}
                        </p>
                        <p className="truncate text-xs text-slate-400">{member.app_id}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      {canManage && member.role !== 'owner' ? (
                        <Select
                          className="w-28 py-1 text-xs"
                          value={member.role}
                          onChange={(e) =>
                            groupsApi.updateMember(detail.uuid, member.uuid, e.target.value)
                              .then(() => refreshDetail(detail.uuid))
                          }
                        >
                          {['admin', 'manager', 'member', 'viewer'].map((r) => (
                            <option key={r} value={r}>{r}</option>
                          ))}
                        </Select>
                      ) : (
                        <Badge value={member.role} />
                      )}
                      {(canManage || member.uuid === me?.uuid) && member.role !== 'owner' && (
                        <button
                          className="rounded p-1 text-slate-400 hover:text-red-600"
                          title={member.uuid === me?.uuid ? 'Leave group' : 'Remove member'}
                          onClick={() => {
                            const isSelf = member.uuid === me?.uuid
                            if (confirm(isSelf ? 'Leave this group?' : `Remove ${member.name}?`)) {
                              groupsApi.removeMember(detail.uuid, member.uuid).then(() => {
                                if (isSelf) {
                                  setDetail(null)
                                  invalidate()
                                } else {
                                  refreshDetail(detail.uuid)
                                }
                              })
                            }
                          }}
                        >
                          <Trash2 className="size-3.5" />
                        </button>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            </div>

            {canManage && (
              <form
                onSubmit={(e) => {
                  e.preventDefault()
                  setError(null)
                  addMemberMutation.mutate()
                }}
                className="space-y-2"
              >
                <h3 className="text-sm font-semibold">Add member</h3>
                <ErrorNote message={error} />
                <div className="flex flex-wrap gap-2 sm:flex-nowrap">
                  {/* min-w-0 so the name field can shrink rather than being
                      pushed out by the role dropdown on a narrow dialog. */}
                  <div className="min-w-0 flex-1 basis-full sm:basis-auto">
                    <UserSuggest
                      placeholder="name, username, email or App ID"
                      value={memberAppId}
                      onChange={setMemberAppId}
                      required
                    />
                  </div>
                  <Select className="w-32 shrink-0" value={memberRole} onChange={(e) => setMemberRole(e.target.value)}>
                    {['admin', 'manager', 'member', 'viewer'].map((r) => (
                      <option key={r} value={r}>{r}</option>
                    ))}
                  </Select>
                  <Button type="submit" disabled={addMemberMutation.isPending}>
                    <UserPlus className="size-4" />
                  </Button>
                </div>
                <p className="text-[11px] text-slate-400">
                  Start typing to search — you do not have to be connected first.
                </p>
              </form>
            )}

            {detail.is_owner && (
              <div className="border-t border-slate-200 pt-3 dark:border-slate-800">
                <Button
                  variant="danger"
                  size="sm"
                  onClick={() => {
                    if (confirm(`Delete group "${detail.name}"? Group tasks and events revert to personal items.`)) {
                      groupsApi.remove(detail.uuid).then(() => {
                        setDetail(null)
                        invalidate()
                      })
                    }
                  }}
                >
                  <Trash2 className="size-3.5" /> Delete group
                </Button>
              </div>
            )}
          </div>
        </Modal>
      )}
    </div>
  )
}
