import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useConnectBase } from '../lib/connectBase'
import {
  Check, CheckSquare, Copy, CopyPlus, Link as LinkIcon, Pencil, Plus, RefreshCw, Trash2, UserPlus,
  Users, X,
} from 'lucide-react'
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
  /** The group's name while it is being edited; null when nobody is editing. */
  const [rename, setRename] = useState<string | null>(null)

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

  /*
   * Several people at once.
   *
   * The field is comma-separated, and adding a family of four was four
   * rounds of type-search-pick-submit. Each is still its own request — the
   * server takes one person at a time — but one that fails does not stop the
   * rest, because "Priya is already in this group" is no reason to leave
   * Rahul out. Whoever did not make it comes back named.
   */
  const addMemberMutation = useMutation({
    mutationFn: async () => {
      const handles = memberAppId.split(',').map((h) => h.trim()).filter(Boolean)
      if (!handles.length) return { added: 0, failed: [] as string[] }

      const results = await Promise.allSettled(
        handles.map((handle) => groupsApi.addMember(detail!.uuid, handle, memberRole)),
      )
      return {
        added: results.filter((r) => r.status === 'fulfilled').length,
        failed: handles.filter((_, i) => results[i].status === 'rejected'),
      }
    },
    onSuccess: ({ added, failed }) => {
      // Only what failed stays in the box, so the retry is one click.
      setMemberAppId(failed.join(', '))
      setError(failed.length
        ? `Added ${added}. Could not add: ${failed.join(', ')}.`
        : null)
      refreshDetail(detail!.uuid)
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const renameMutation = useMutation({
    mutationFn: (name: string) => groupsApi.update(detail!.uuid, { name }),
    onSuccess: () => {
      setRename(null)
      refreshDetail(detail!.uuid)
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const canManage = detail?.my_role === 'owner' || detail?.my_role === 'admin'

  /*
   * The group's link, and whoever it has put in the queue.
   *
   * Only fetched for people who could act on it: a member with no say does
   * not need to know the link exists, and asking would be two 403s per
   * dialog open.
   */
  const { data: invite } = useQuery({
    queryKey: ['group-invite', detail?.uuid],
    queryFn: () => groupsApi.invite(detail!.uuid),
    enabled: !!detail && canManage,
  })

  const { data: waiting } = useQuery({
    queryKey: ['group-join-requests', detail?.uuid],
    queryFn: () => groupsApi.joinRequests(detail!.uuid),
    enabled: !!detail && canManage && invite?.mode === 'request',
  })

  const refreshInvite = () => {
    queryClient.invalidateQueries({ queryKey: ['group-invite', detail?.uuid] })
    queryClient.invalidateQueries({ queryKey: ['group-join-requests', detail?.uuid] })
  }

  const inviteMutation = useMutation({
    mutationFn: (payload: { enabled?: boolean; mode?: 'open' | 'request' }) =>
      groupsApi.setInvite(detail!.uuid, payload),
    onSuccess: refreshInvite,
    onError: (err) => setError(errorMessage(err)),
  })

  const rotateMutation = useMutation({
    mutationFn: () => groupsApi.rotateInvite(detail!.uuid),
    onSuccess: refreshInvite,
    onError: (err) => setError(errorMessage(err)),
  })

  const decideMutation = useMutation({
    mutationFn: ({ uuid, action }: { uuid: string; action: 'approve' | 'decline' }) =>
      groupsApi.decideJoinRequest(detail!.uuid, uuid, action),
    onSuccess: () => {
      refreshInvite()
      refreshDetail(detail!.uuid)
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const [inviteCopied, setInviteCopied] = useState(false)

  /*
   * Copying a group.
   *
   * Null when the form is closed. Open, it holds the new name and who is
   * coming across — everyone by default, because "the same group again" is
   * the common case and unticking two people is less work than ticking nine.
   */
  const [copyForm, setCopyForm] = useState<{ name: string; keep: Set<string> } | null>(null)

  /*
   * Everybody in the group except you.
   *
   * You are in the copy whatever the ticks say — you own it — so offering
   * yourself as something to untick would be offering a choice that is not
   * there.
   */
  const others = (detail?.members ?? []).filter((m) => m.uuid !== me?.uuid)

  const replicateMutation = useMutation({
    mutationFn: () => groupsApi.replicate(detail!.uuid, {
      name: copyForm!.name.trim(),
      member_uuids: [...copyForm!.keep],
    }),
    onSuccess: (fresh) => {
      setCopyForm(null)
      setDetail(fresh)
      invalidate()
    },
    onError: (err) => setError(errorMessage(err)),
  })

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
            {/*
              * The name, editable in place.
              *
              * A group outlives the reason it was named — the project it was
              * for gets renamed, the family gets a nickname — and until now
              * the only way to change it was to delete the group and lose
              * every message in it.
              */}
            {canManage && (
              rename === null ? (
                <button
                  type="button"
                  onClick={() => { setError(null); setRename(detail.name) }}
                  className="-mx-1 flex items-center gap-1.5 rounded-lg px-1 text-left text-base font-semibold hover:bg-slate-100 dark:hover:bg-slate-800"
                >
                  {detail.name}
                  <Pencil className="size-3.5 shrink-0 text-slate-400" />
                </button>
              ) : (
                <form
                  className="flex gap-2"
                  onSubmit={(e) => {
                    e.preventDefault()
                    const name = rename.trim()
                    if (!name || name === detail.name) { setRename(null); return }
                    renameMutation.mutate(name)
                  }}
                >
                  <Input
                    autoFocus
                    value={rename}
                    onChange={(e) => setRename(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Escape') setRename(null) }}
                    maxLength={255}
                    className="flex-1"
                  />
                  <Button type="submit" size="sm" disabled={renameMutation.isPending}>Save</Button>
                  <Button type="button" size="sm" variant="secondary" onClick={() => setRename(null)}>Cancel</Button>
                </form>
              )
            )}

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

            {/*
              * A link, instead of typing forty names.
              *
              * Off by default and off means off — turning it off clears the
              * token rather than setting a flag beside one that still works.
              */}
            {canManage && invite && (
              <div className="space-y-2 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                <div className="flex items-center justify-between gap-2">
                  <h3 className="flex items-center gap-1.5 text-sm font-semibold">
                    <LinkIcon className="size-3.5" /> Invite link
                  </h3>
                  <Button
                    size="sm"
                    variant="secondary"
                    disabled={inviteMutation.isPending}
                    onClick={() => inviteMutation.mutate({ enabled: !invite.enabled })}
                  >
                    {invite.enabled ? 'Turn off' : 'Turn on'}
                  </Button>
                </div>

                {invite.enabled && invite.url ? (
                  <>
                    <div className="flex gap-2">
                      <Input readOnly value={invite.url} className="flex-1 font-mono text-xs" />
                      <Button
                        variant="secondary"
                        onClick={() => {
                          void navigator.clipboard?.writeText(invite.url!)
                          setInviteCopied(true)
                          setTimeout(() => setInviteCopied(false), 2000)
                        }}
                      >
                        {inviteCopied ? <Check className="size-4" /> : <Copy className="size-4" />}
                      </Button>
                    </div>

                    <Select
                      value={invite.mode}
                      onChange={(e) => inviteMutation.mutate({ mode: e.target.value as 'open' | 'request' })}
                    >
                      <option value="request">Anyone with the link can ask to join</option>
                      <option value="open">Anyone with the link joins straight away</option>
                    </Select>

                    <div className="flex items-center justify-between gap-2">
                      <p className="text-[11px] text-slate-400">
                        {invite.mode === 'open'
                          ? 'No approval — whoever opens this link is in. Turn it off or replace it when you are done sharing.'
                          : 'People who follow the link land in the list below for you to approve.'}
                      </p>
                      {/* The only honest way to take back a URL already
                          forwarded to people you cannot name. */}
                      <Button
                        size="sm"
                        variant="secondary"
                        className="shrink-0"
                        disabled={rotateMutation.isPending}
                        onClick={() => {
                          if (confirm('Replace this link? The old one will stop working for everybody who has it.')) {
                            rotateMutation.mutate()
                          }
                        }}
                      >
                        <RefreshCw className="size-3.5" /> New link
                      </Button>
                    </div>
                  </>
                ) : (
                  <p className="text-[11px] text-slate-400">
                    There is no link. Turn it on to let people join without being added one at a time.
                  </p>
                )}
              </div>
            )}

            {/* The queue, for whoever can answer it. */}
            {canManage && invite?.mode === 'request' && !!waiting?.length && (
              <div className="space-y-2">
                <h3 className="text-sm font-semibold">
                  Waiting to join ({waiting.length})
                </h3>
                {waiting.map((person) => (
                  <div key={person.uuid} className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-2 dark:border-slate-700">
                    <div className="flex min-w-0 items-center gap-2">
                      <Avatar name={person.name} photoPath={person.photo_path} avatar={person.avatar} size={32} />
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium">{person.name}</p>
                        {person.username && (
                          <p className="truncate text-xs text-slate-400">@{person.username}</p>
                        )}
                      </div>
                    </div>
                    <div className="flex shrink-0 gap-1">
                      <Button
                        size="sm"
                        disabled={decideMutation.isPending}
                        onClick={() => decideMutation.mutate({ uuid: person.uuid, action: 'approve' })}
                      >
                        <Check className="size-3.5" />
                      </Button>
                      <Button
                        size="sm"
                        variant="secondary"
                        disabled={decideMutation.isPending}
                        onClick={() => decideMutation.mutate({ uuid: person.uuid, action: 'decline' })}
                      >
                        <X className="size-3.5" />
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            )}

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
                      multi
                      placeholder="name, username, email or App ID — separate with commas"
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
                  Start typing to search — you do not have to be connected first. Pick several and they
                  all join with the role above.
                </p>
              </form>
            )}

            {/*
              * The same group again.
              *
              * A team that ran one project runs the next one too, and
              * rebuilding that membership by hand — search each person, pick
              * their role, eleven times — is the work that stops people
              * making the second group at all.
              */}
            <div className="border-t border-slate-200 pt-3 dark:border-slate-800">
              {copyForm === null ? (
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => {
                    setError(null)
                    setCopyForm({
                      name: `${detail.name} (copy)`,
                      // Everyone, minus you — you own the copy either way.
                      keep: new Set(others.map((m) => m.uuid)),
                    })
                  }}
                >
                  <CopyPlus className="size-3.5" /> Duplicate group
                </Button>
              ) : (
                <div className="space-y-2">
                  <Label>Name of the copy</Label>
                  <Input
                    autoFocus
                    value={copyForm.name}
                    onChange={(e) => setCopyForm({ ...copyForm, name: e.target.value })}
                    maxLength={255}
                  />

                  <p className="text-xs text-slate-400">
                    Who comes across ({copyForm.keep.size} of {others.length})
                  </p>
                  <div className="max-h-40 space-y-1 overflow-y-auto">
                    {others.map((m) => (
                      <label key={m.uuid} className="flex items-center gap-2 rounded-lg px-1 py-1 text-sm">
                        <input
                          type="checkbox"
                          className="size-4 accent-brand-600"
                          checked={copyForm.keep.has(m.uuid)}
                          onChange={() => {
                            const keep = new Set(copyForm.keep)
                            if (!keep.delete(m.uuid)) keep.add(m.uuid)
                            setCopyForm({ ...copyForm, keep })
                          }}
                        />
                        <span className="truncate">{m.name}</span>
                        <span className="ml-auto shrink-0 text-xs text-slate-400">{m.role}</span>
                      </label>
                    ))}
                  </div>

                  <p className="text-[11px] text-slate-400">
                    A copy is a fresh group with the same people in it — the messages, tasks and files
                    stay where they are.
                  </p>

                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      disabled={!copyForm.name.trim() || replicateMutation.isPending}
                      onClick={() => replicateMutation.mutate()}
                    >
                      {replicateMutation.isPending ? 'Copying…' : 'Create the copy'}
                    </Button>
                    <Button size="sm" variant="secondary" onClick={() => setCopyForm(null)}>Cancel</Button>
                  </div>
                </div>
              )}
            </div>

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
