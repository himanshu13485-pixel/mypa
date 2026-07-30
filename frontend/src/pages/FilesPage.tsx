import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ChevronRight, Download, File as FileIcon, Folder, FolderPlus, Home,
  Pencil, RotateCcw, Share2, Trash2, Upload, Users,
} from 'lucide-react'
import { badges as badgesApi, files as filesApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { Button, Card, EmptyState, Spinner } from '../components/ui'

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
  const queryClient = useQueryClient()

  // Attending this section clears its share notifications.
  useEffect(() => {
    badgesApi.readKinds(['file_shared']).then(() => {
      queryClient.invalidateQueries({ queryKey: ['notifications-count'] })
    }).catch(() => undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const [folder, setFolder] = useState<string | undefined>(undefined)
  const [view, setView] = useState<'mine' | 'shared' | 'trash'>('mine')
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

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['files'] })
    queryClient.invalidateQueries({ queryKey: ['files-trash'] })
    queryClient.invalidateQueries({ queryKey: ['files-shared'] })
  }

  const uploadMutation = useMutation({
    mutationFn: (fileList: File[]) => filesApi.upload(fileList, folder),
    onSuccess: invalidate,
    onError: (err) => alert(errorMessage(err)),
  })

  const usage = data?.usage

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-lg font-semibold">Files</h1>
        <div className="flex gap-2">
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
                    onClick={() => {
                      const target = prompt(`Share folder "${f.name}" with (username or email):`)
                      if (target?.trim()) {
                        filesApi.shareFolder(f.uuid, target.trim())
                          .then((res) => alert((res as { data?: { message?: string } }).data?.message ?? 'Folder shared.'))
                          .catch((err) => alert(errorMessage(err)))
                      }
                    }}
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
                    <p className="text-xs text-slate-400">{formatBytes(f.size)} · {f.mime_type ?? 'unknown'}</p>
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
                    title="Share"
                    onClick={() => {
                      const appId = prompt('Share with (username or email):')
                      if (appId?.trim()) {
                        filesApi.share(f.uuid, appId.trim())
                          .then(() => alert('Shared.'))
                          .catch((err) => alert(errorMessage(err)))
                      }
                    }}
                  >
                    <Share2 className="size-4" />
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
