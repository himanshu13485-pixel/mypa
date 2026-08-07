import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ChevronRight, Download, File as FileIcon, Folder, FolderPlus, Home,
  Check, Copy, Link2, Pencil, RotateCcw, Share2, Trash2, Upload, Users,
} from 'lucide-react'
import { badges as badgesApi, files as filesApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { useToast } from '../components/Toast'
import { PickUserModal } from '../components/UserSuggest'
import { useAuthStore } from '../stores/auth'
import { Button, Card, EmptyState, Modal, Spinner } from '../components/ui'

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)))
  return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`
}

async function authedDownload(uuid: string, name: string) {
  const token = useAuthStore.getState().token
  const res = await fetch(filesApi.downloadUrl(uuid), { headers: { Authorization: `Bearer ${token}` } })
  if (!res.ok) {
    alert('Download failed.')
    return
  }
  const blob = await res.blob()
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  a.click()
  URL.revokeObjectURL(url)
}

export default function FilesPage() {
  const { toast, toastError } = useToast()
  const queryClient = useQueryClient()

  // Attending this section clears its share notifications.
  useEffect(() => {
    badgesApi.readKinds(['file_shared']).then(() => {
      queryClient.invalidateQueries({ queryKey: ['notifications-count'] })
    }).catch(() => undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const [folder, setFolder] = useState<string | undefined>(undefined)
  const [view, setView] = useState<'mine' | 'shared' | 'byme' | 'trash'>('mine')
  const [shareTarget, setShareTarget] = useState<{ kind: 'file' | 'folder'; uuid: string; name: string } | null>(null)
  /* Public link — works for anyone, with no Netvork account. */
  const [linkFor, setLinkFor] = useState<{ uuid: string; name: string } | null>(null)
  const [link, setLink] = useState<string | null>(null)
  const [linkBusy, setLinkBusy] = useState(false)
  const [copied, setCopied] = useState(false)

  const makeLink = async (uuid: string, days: number | null) => {
    setLinkBusy(true)
    try {
      setLink((await filesApi.shareLink(uuid, days)).url)
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setLinkBusy(false)
    }
  }
  const inputRef = useRef<HTMLInputElement>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['files', folder],
    queryFn: () => filesApi.browse(folder),
    enabled: view === 'mine',
  })
  const { data: shared } = useQuery({
    queryKey: ['files-shared'],
    queryFn: filesApi.sharedWithMe,
    enabled: view === 'shared',
  })
  const { data: trash } = useQuery({
    queryKey: ['files-trash'],
    queryFn: filesApi.trash,
    enabled: view === 'trash',
  })
  const { data: byMe } = useQuery({
    queryKey: ['files-shared-by-me'],
    queryFn: filesApi.sharedByMe,
    enabled: view === 'byme',
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['files'] })
    queryClient.invalidateQueries({ queryKey: ['files-trash'] })
    queryClient.invalidateQueries({ queryKey: ['files-shared'] })
  }

  const uploadMutation = useMutation({
    mutationFn: (fileList: File[]) => filesApi.upload(fileList, folder),
    onSuccess: invalidate,
    onError: (err) => toastError(errorMessage(err)),
  })

  const usage = data?.usage

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-lg font-semibold">Files</h1>
        {/* One scrolling row on a phone, where these four otherwise take two
            rows and "Shared with me" wraps inside its own button. */}
        <div className="scroll-pane -mx-3 flex gap-2 overflow-x-auto px-3 sm:mx-0 sm:px-0 [&>button]:shrink-0 [&>button]:whitespace-nowrap">
          <Button
            variant={view === 'mine' ? 'primary' : 'secondary'}
            size="sm"
            onClick={() => setView('mine')}
          >
            <Folder className="size-3.5" /> My files
          </Button>
          <Button
            variant={view === 'shared' ? 'primary' : 'secondary'}
            size="sm"
            onClick={() => setView('shared')}
          >
            <Users className="size-3.5" /> Shared with me
          </Button>
          <Button
            variant={view === 'byme' ? 'primary' : 'secondary'}
            size="sm"
            onClick={() => setView('byme')}
          >
            <Share2 className="size-3.5" /> Shared by me
          </Button>
          <Button
            variant={view === 'trash' ? 'primary' : 'secondary'}
            size="sm"
            onClick={() => setView('trash')}
          >
            <Trash2 className="size-3.5" /> Trash
          </Button>
        </div>
      </div>

      {view === 'mine' && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            {/* Breadcrumb */}
            <div className="flex items-center gap-1 text-sm text-slate-500">
              <button className="flex items-center gap-1 hover:text-brand-600" onClick={() => setFolder(undefined)}>
                <Home className="size-3.5" /> Home
              </button>
              {data?.breadcrumb.map((crumb) => (
                <span key={crumb.uuid} className="flex items-center gap-1">
                  <ChevronRight className="size-3.5" />
                  <button className="hover:text-brand-600" onClick={() => setFolder(crumb.uuid)}>
                    {crumb.name}
                  </button>
                </span>
              ))}
            </div>
            <div className="flex gap-2">
              <Button
                variant="secondary"
                size="sm"
                onClick={() => {
                  const name = prompt('Folder name:')
                  if (name?.trim()) filesApi.createFolder(name.trim(), folder).then(invalidate)
                }}
              >
                <FolderPlus className="size-3.5" /> New folder
              </Button>
              <Button size="sm" onClick={() => inputRef.current?.click()} disabled={uploadMutation.isPending}>
                <Upload className="size-3.5" /> {uploadMutation.isPending ? 'Uploading…' : 'Upload'}
              </Button>
              <input
                ref={inputRef}
                type="file"
                multiple
                className="hidden"
                onChange={(e) => {
                  const list = Array.from(e.target.files ?? [])
                  if (list.length) uploadMutation.mutate(list)
                  e.target.value = ''
                }}
              />
            </div>
          </div>

          {/* Storage usage */}
          {usage && (
            <div className="flex items-center gap-3 text-xs text-slate-500">
              <div className="h-1.5 w-48 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                <div
                  className="h-full rounded-full bg-brand-500"
                  style={{ width: `${Math.min(100, usage.percent)}%` }}
                />
              </div>
              {formatBytes(usage.used_bytes)} of {formatBytes(usage.limit_bytes)} used
            </div>
          )}

          {isLoading ? (
            <Spinner />
          ) : !data || (data.folders.length === 0 && data.files.length === 0) ? (
            <Card>
              <EmptyState title="This folder is empty" hint="Upload files or create a folder." />
            </Card>
          ) : (
            <div className="space-y-1.5">
              {data.folders.map((f) => (
                <Card key={f.uuid} className="flex items-center gap-3 p-3">
                  <Folder className="size-5 shrink-0 text-amber-500" />
                  <button className="min-w-0 flex-1 text-left" onClick={() => setFolder(f.uuid)}>
                    <p className="truncate text-sm font-medium">{f.name}</p>
                    <p className="text-xs text-slate-400">{f.files_count} file(s)</p>
                  </button>
                  <button
                    className="rounded p-1.5 text-slate-400 hover:text-brand-600"
                    title="Share folder (all files inside)"
                    onClick={() => setShareTarget({ kind: 'folder', uuid: f.uuid, name: f.name })}
                  >
                    <Share2 className="size-4" />
                  </button>
                  <button
                    className="rounded p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
                    title="Rename"
                    onClick={() => {
                      const name = prompt('New name:', f.name)
                      if (name?.trim()) filesApi.renameFolder(f.uuid, name.trim()).then(invalidate)
                    }}
                  >
                    <Pencil className="size-4" />
                  </button>
                  <button
                    className="rounded p-1.5 text-slate-400 hover:text-red-600"
                    title="Delete folder"
                    onClick={() => {
                      if (confirm(`Delete folder "${f.name}"? Files inside move to trash.`)) {
                        filesApi.removeFolder(f.uuid).then(invalidate)
                      }
                    }}
                  >
                    <Trash2 className="size-4" />
                  </button>
                </Card>
              ))}
              {data.files.map((f) => (
                <Card key={f.uuid} className="flex items-center gap-3 p-3">
                  <FileIcon className="size-5 shrink-0 text-slate-400" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{f.name}</p>
                    {/* "application/vnd.openxmlformats-officedocument…" is three
                        wrapped lines on a phone, and it is the least useful
                        thing in the row. */}
                    <p className="truncate text-xs text-slate-400">{formatBytes(f.size)} · {f.mime_type ?? 'unknown'}</p>
                  </div>
                  <button
                    className="rounded p-1.5 text-slate-400 hover:text-brand-600"
                    title="Download"
                    onClick={() => authedDownload(f.uuid, f.name)}
                  >
                    <Download className="size-4" />
                  </button>
                  <button
                    className="rounded p-1.5 text-slate-400 hover:text-brand-600"
                    title="Share with a Netvork user"
                    onClick={() => setShareTarget({ kind: 'file', uuid: f.uuid, name: f.name })}
                  >
                    <Share2 className="size-4" />
                  </button>
                  <button
                    className="rounded p-1.5 text-slate-400 hover:text-brand-600"
                    title="Get a link anyone can open"
                    onClick={() => {
                      setLink(null)
                      setCopied(false)
                      setLinkFor({ uuid: f.uuid, name: f.name })
                      void makeLink(f.uuid, null)
                    }}
                  >
                    <Link2 className="size-4" />
                  </button>
                  <button
                    className="rounded p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
                    title="Rename"
                    onClick={() => {
                      const name = prompt('New name:', f.name)
                      if (name?.trim()) filesApi.rename(f.uuid, name.trim()).then(invalidate)
                    }}
                  >
                    <Pencil className="size-4" />
                  </button>
                  <button
                    className="rounded p-1.5 text-slate-400 hover:text-red-600"
                    title="Move to trash"
                    onClick={() => filesApi.remove(f.uuid).then(invalidate)}
                  >
                    <Trash2 className="size-4" />
                  </button>
                </Card>
              ))}
            </div>
          )}
        </>
      )}

      {view === 'shared' && (
        <div className="space-y-1.5">
          {(shared as unknown as { shared_folders?: { uuid: string; name: string; files_count: number; owner: { name: string } }[] })?.shared_folders?.map((sf) => (
            <Card key={sf.uuid} className="flex items-center gap-3 p-3">
              <Folder className="size-5 shrink-0 text-amber-500" />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{sf.name}</p>
                <p className="text-xs text-slate-400">{sf.files_count} file(s) · folder shared by {sf.owner.name}</p>
              </div>
              <Button
                size="sm"
                variant="secondary"
                onClick={async () => {
                  const res = await filesApi.sharedFolderFiles(sf.uuid)
                  if (!res.files.length) return alert('This folder is empty.')
                  const pick = prompt(
                    res.files.map((x, i) => `${i + 1}. ${x.name}`).join('\n') +
                      '\n\nEnter a number to download:',
                  )
                  const idx = Number(pick) - 1
                  if (res.files[idx]) authedDownload(res.files[idx].uuid, res.files[idx].name)
                }}
              >
                Open
              </Button>
            </Card>
          ))}
          {!shared?.data.length ? (
            <Card>
              <EmptyState title="Nothing shared with you yet" />
            </Card>
          ) : (
            shared.data.map((f) => (
              <Card key={f.uuid} className="flex items-center gap-3 p-3">
                <FileIcon className="size-5 shrink-0 text-slate-400" />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{f.name}</p>
                  <p className="text-xs text-slate-400">
                    {formatBytes(f.size)} · shared by {f.owner?.name}
                  </p>
                </div>
                <button
                  className="rounded p-1.5 text-slate-400 hover:text-brand-600"
                  title="Download"
                  onClick={() => authedDownload(f.uuid, f.name)}
                >
                  <Download className="size-4" />
                </button>
              </Card>
            ))
          )}
        </div>
      )}

      {view === 'byme' && (
        <div className="space-y-1.5">
          {!byMe?.length ? (
            <Card>
              <EmptyState
                title="You have not shared anything yet"
                hint="Use the share icon on a file or folder to give someone access — it will be listed here."
              />
            </Card>
          ) : (
            byMe.map((item) => (
              <Card key={`${item.kind}-${item.uuid}`} className="p-3">
                <div className="flex items-center gap-3">
                  {item.kind === 'folder' ? (
                    <Folder className="size-5 shrink-0 text-amber-500" />
                  ) : (
                    <FileIcon className="size-5 shrink-0 text-slate-400" />
                  )}
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{item.name}</p>
                    <p className="text-xs text-slate-400">
                      {item.kind === 'folder' ? `Folder · ${item.files_count} file(s) · ` : 'File · '}
                      shared with {item.shared_with.length} people
                    </p>
                  </div>
                </div>
                <div className="mt-2 flex flex-wrap gap-1.5 pl-8">
                  {item.shared_with.map((person) => (
                    <span
                      key={person.uuid}
                      className="flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs dark:bg-slate-800"
                    >
                      {person.name}
                      {person.username && <span className="text-slate-400">@{person.username}</span>}
                      <button
                        className="ml-1 font-semibold text-red-500 hover:text-red-700"
                        title={`Remove ${person.name}'s access`}
                        onClick={() => {
                          if (!confirm(`Take back access to “${item.name}” from ${person.name}?`)) return
                          const call =
                            item.kind === 'folder'
                              ? filesApi.unshareFolder(item.uuid, person.uuid)
                              : filesApi.unshare(item.uuid, person.uuid)
                          call
                            .then((res) => {
                              alert(res.message)
                              queryClient.invalidateQueries({ queryKey: ['files-shared-by-me'] })
                            })
                            .catch((err) => toastError(errorMessage(err)))
                        }}
                      >
                        ×
                      </button>
                    </span>
                  ))}
                </div>
              </Card>
            ))
          )}
        </div>
      )}

      {linkFor && (
        <Modal title={`Link to “${linkFor.name}”`} onClose={() => { setLinkFor(null); setLink(null) }}>
          <div className="space-y-4">
            <p className="text-sm text-slate-500">
              Anyone with this link can download the file — they do not need a Netvork account.
            </p>

            <div className="flex gap-2">
              <input
                readOnly
                value={link ?? (linkBusy ? 'Creating…' : '')}
                onFocus={(e) => e.currentTarget.select()}
                className="min-w-0 flex-1 rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-800"
              />
              <Button
                size="sm"
                disabled={!link}
                onClick={async () => {
                  if (!link) return
                  try {
                    await navigator.clipboard.writeText(link)
                  } catch {
                    // Clipboard is blocked on insecure origins; the field is
                    // selectable so the link is still gettable by hand.
                    toastError('Could not copy — select the link and copy it.')
                    return
                  }
                  setCopied(true)
                  setTimeout(() => setCopied(false), 2000)
                }}
              >
                {copied ? <><Check className="size-3.5" /> Copied</> : <><Copy className="size-3.5" /> Copy</>}
              </Button>
            </div>

            <div>
              <p className="mb-1 text-xs font-medium text-slate-500">Stops working after</p>
              <div className="flex flex-wrap gap-2">
                {[
                  { label: 'Never', days: null },
                  { label: '1 day', days: 1 },
                  { label: '7 days', days: 7 },
                  { label: '30 days', days: 30 },
                ].map((o) => (
                  <Button
                    key={o.label}
                    size="sm"
                    variant="secondary"
                    disabled={linkBusy}
                    onClick={() => { setCopied(false); void makeLink(linkFor.uuid, o.days) }}
                  >
                    {o.label}
                  </Button>
                ))}
              </div>
              <p className="mt-1 text-[11px] text-slate-400">
                Choosing one makes a fresh link — any link you already sent stops working.
              </p>
            </div>

            <div className="flex justify-between gap-2 border-t border-slate-200 pt-3 dark:border-slate-700">
              <Button
                size="sm"
                variant="danger"
                disabled={linkBusy}
                onClick={async () => {
                  try {
                    await filesApi.revokeShareLink(linkFor.uuid)
                    toast('Link revoked — it no longer opens anything.', 'success')
                    setLinkFor(null)
                    setLink(null)
                  } catch (err) {
                    toastError(errorMessage(err))
                  }
                }}
              >
                Revoke link
              </Button>
              <Button size="sm" variant="secondary" onClick={() => { setLinkFor(null); setLink(null) }}>
                Done
              </Button>
            </div>
          </div>
        </Modal>
      )}

      {shareTarget && (
        <PickUserModal
          title={`Share ${shareTarget.kind} “${shareTarget.name}” with`}
          actionLabel="Share"
          onClose={() => setShareTarget(null)}
          onSubmit={(identifier) => {
            const call =
              shareTarget.kind === 'folder'
                ? filesApi.shareFolder(shareTarget.uuid, identifier)
                : filesApi.share(shareTarget.uuid, identifier)
            call
              .then((res) => alert((res as { data?: { message?: string } }).data?.message ?? 'Shared.'))
              .catch((err) => toastError(errorMessage(err)))
          }}
        />
      )}

      {view === 'trash' && (
        <div className="space-y-1.5">
          {!trash?.data.length ? (
            <Card>
              <EmptyState title="Trash is empty" />
            </Card>
          ) : (
            trash.data.map((f) => (
              <Card key={f.uuid} className="flex items-center gap-3 p-3">
                <FileIcon className="size-5 shrink-0 text-slate-400" />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{f.name}</p>
                  <p className="text-xs text-slate-400">{formatBytes(f.size)}</p>
                </div>
                <Button size="sm" variant="secondary" onClick={() => filesApi.restore(f.uuid).then(invalidate)}>
                  <RotateCcw className="size-3.5" /> Restore
                </Button>
                <Button
                  size="sm"
                  variant="danger"
                  onClick={() => {
                    if (confirm(`Permanently delete "${f.name}"? This cannot be undone.`)) {
                      filesApi.forceDelete(f.uuid).then(invalidate)
                    }
                  }}
                >
                  Delete forever
                </Button>
              </Card>
            ))
          )}
        </div>
      )}
    </div>
  )
}
