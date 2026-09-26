import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Cloud, Download, FolderOpen, HardDrive, Laptop, RefreshCw, Server, Upload } from 'lucide-react'
import { clsx } from 'clsx'
import { mails, type MailBackupRow } from '../../../api/mails'
import { errorMessage } from '../../../api/client'
import { useToast } from '../../../components/Toast'
import { Button, Input, Label, Spinner, Textarea } from '../../../components/ui'
import { fullDate, sizeLabel } from './mailUtils'
import { chooseFolder, folderName, localFoldersSupported, markLocalSync, writeToFolder } from './localArchive'
import { useChosenMailbox } from './useChosenMailbox'

const card = 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800 sm:p-5'

const DRIVERS: { key: 's3' | 'webdav' | 'gdrive'; label: string; note: string }[] = [
  { key: 's3', label: 'S3 bucket', note: 'AWS S3, Wasabi, DigitalOcean Spaces, Cloudflare R2, or MinIO on your own hardware.' },
  { key: 'webdav', label: 'WebDAV', note: 'Nextcloud, ownCloud, or any WebDAV share. Use an app password, not the account password.' },
  { key: 'gdrive', label: 'Google Drive', note: 'Make a service account in Google Cloud, share a Drive folder with its address, and paste the key file below.' },
]

/**
 * Where this company's mail is kept, besides the database.
 *
 * Three copies, and the first is not optional: the server writes every
 * message out as a .eml file in a folder named after the mailbox. The other
 * two are ticks - somewhere in the cloud the company owns, and a folder on
 * this computer.
 */
export default function BackupTab() {
  const { data, isLoading } = useQuery({ queryKey: ['mails', 'backups'], queryFn: mails.backups })
  const { chosen, picker } = useChosenMailbox()
  if (isLoading || !data) return <Spinner />

  // One mailbox's archive at a time, like every other settings screen.
  const rows = chosen ? data.data.filter((row) => row.uuid === chosen.uuid) : data.data
  const runs = chosen ? data.runs.filter((run) => !run.mailbox || run.mailbox === chosen.email) : data.runs

  return (
    <div className="space-y-4">
      {picker}
      <div className={clsx(card, 'text-sm text-slate-600 dark:text-slate-300')}>
        <p className="font-semibold text-slate-800 dark:text-slate-100">How the archive works</p>
        <p className="mt-1">
          Every message is written out as an <b>.eml</b> file - the ordinary internet mail format that Outlook, Thunderbird and
          Apple Mail all open - inside a folder named after its mailbox, with an <b>index.jsonl</b> listing every file, its date,
          sender, subject and checksum. Runs are nightly and incremental, and you can start one at any time.
        </p>
        <p className="mt-1">
          The live copy your screens read stays in the database (MariaDB), so the archive is a copy you could use without Netvork -
          which is the only kind worth keeping.
        </p>
      </div>

      {data.data.length === 0 && <p className="text-sm text-slate-500">Add a mailbox first.</p>}
      {rows.map((row) => <MailboxBackup key={row.uuid} row={row} />)}

      {runs.length > 0 && (
        <div className={card}>
          <h3 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Recent runs</h3>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="py-1.5 pr-3">When</th>
                  <th className="py-1.5 pr-3">Mailbox</th>
                  <th className="py-1.5 pr-3">What</th>
                  <th className="py-1.5 pr-3">Messages</th>
                  <th className="py-1.5">Result</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {data.runs.map((run) => (
                  <tr key={run.uuid}>
                    <td className="py-1.5 pr-3 text-slate-500">{fullDate(run.started_at)}</td>
                    <td className="py-1.5 pr-3">{run.mailbox}</td>
                    <td className="py-1.5 pr-3 capitalize">{run.kind} · {run.destination}</td>
                    <td className="py-1.5 pr-3 tabular-nums">{run.messages}{run.bytes > 0 && <span className="text-slate-400"> · {sizeLabel(run.bytes)}</span>}</td>
                    <td className={clsx('py-1.5', run.status === 'failed' ? 'text-red-600' : run.status === 'done' ? 'text-emerald-600' : 'text-slate-500')}>
                      {run.status === 'failed' ? run.error ?? 'Failed' : run.status}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

function MailboxBackup({ row }: { row: MailBackupRow }) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [remote, setRemote] = useState(row.destinations.remote)
  const [secret, setSecret] = useState('')
  const [local, setLocal] = useState(row.destinations.local.enabled)
  const [localName, setLocalName] = useState<string | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const importFile = useRef<HTMLInputElement>(null)

  useEffect(() => { void folderName(row.uuid).then(setLocalName) }, [row.uuid])

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['mails', 'backups'] })

  const save = useMutation({
    mutationFn: () => mails.saveBackup(row.uuid, {
      remote: {
        ...remote,
        // Whichever kind of secret this destination uses, it is the same box.
        ...(secret ? (remote.driver === 'webdav' ? { password: secret } : remote.driver === 'gdrive' ? { service_account: secret } : { secret }) : {}),
      },
      local: { enabled: local, hint: localName },
    }),
    onSuccess: (res) => { toast(res.message, 'success'); setSecret(''); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const run = async (key: string, fn: () => Promise<unknown>) => {
    setBusy(key)
    try {
      await fn()
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setBusy(null)
    }
  }

  const downloadZip = async (): Promise<Blob> => {
    const blob = await mails.exportArchive(row.uuid)
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `mails-${row.email}-${new Date().toISOString().slice(0, 10)}.zip`
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 10_000)

    return blob
  }

  const syncLocal = () => run('local', async () => {
    const blob = await mails.exportArchive(row.uuid)
    const name = `mails-${row.email}-${new Date().toISOString().slice(0, 10)}.zip`
    const written = await writeToFolder(row.uuid, name, blob, true)
    if (written) {
      markLocalSync(row.uuid)
      toast(`Written to ${localName ?? 'your folder'}.`, 'success')
    } else {
      toastError('That folder is not available any more - choose it again.')
    }
  })

  const field = (label: string, value: string | null | undefined, onChange: (v: string) => void, placeholder?: string) => (
    <div>
      <Label>{label}</Label>
      <Input value={value ?? ''} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} />
    </div>
  )

  return (
    <div className={clsx(card, 'space-y-4')}>
      <div className="flex flex-wrap items-baseline gap-2">
        <h3 className="font-semibold text-slate-900 dark:text-white">{row.label || row.email}</h3>
        <span className="text-sm text-slate-500">{row.email}</span>
        {!row.can_manage && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-slate-800">shared with you</span>}
      </div>

      {/* 1. This server - always on. */}
      <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
        <p className="flex items-center gap-2 text-sm font-medium text-slate-800 dark:text-slate-100">
          <Server className="size-4 text-emerald-600" /> On this server
          <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">Always on</span>
        </p>
        <p className="mt-1 break-all font-mono text-xs text-slate-500">{row.archive.absolute}</p>
        <p className="mt-1 text-xs text-slate-500">
          {row.archive.files} file{row.archive.files === 1 ? '' : 's'} · {sizeLabel(row.archive.bytes) || '0 B'} ·
          {' '}{row.archive.stored} message{row.archive.stored === 1 ? '' : 's'} held in Mails ·
          {' '}{row.archive.last_backup_at ? `last run ${fullDate(row.archive.last_backup_at)}` : 'never run'}
        </p>
        {row.last_error && <p className="mt-1 text-xs text-red-600">{row.last_error}</p>}
        {row.can_manage && (
          <div className="mt-2 flex flex-wrap gap-2">
            <Button size="sm" disabled={busy !== null} onClick={() => run('backup', async () => {
              const res = await mails.runBackup(row.uuid)
              toast(`${res.data.messages} message(s) archived.`, 'success')
              refresh()
            })}>
              <RefreshCw className={clsx('size-3.5', busy === 'backup' && 'animate-spin')} /> Back up now
            </Button>
            <Button size="sm" variant="secondary" disabled={busy !== null} onClick={() => run('full', async () => {
              const res = await mails.runBackup(row.uuid, true)
              toast(`${res.data.messages} message(s) written again.`, 'success')
              refresh()
            })}>
              Rebuild from the start
            </Button>
            <Button size="sm" variant="secondary" disabled={busy !== null} onClick={() => run('zip', downloadZip)}>
              <Download className="size-3.5" /> Export as zip
            </Button>
            <Button size="sm" variant="secondary" disabled={busy !== null} onClick={() => importFile.current?.click()}>
              <Upload className="size-3.5" /> Import mail
            </Button>
            <input
              ref={importFile}
              type="file"
              accept=".eml,.mbox,.zip"
              hidden
              onChange={(e) => {
                const file = e.target.files?.[0]
                e.target.value = ''
                if (!file) return
                void run('import', async () => {
                  const res = await mails.importArchive(row.uuid, file, 'archive')
                  toast(res.message, 'success')
                  queryClient.invalidateQueries({ queryKey: ['mails'] })
                })
              }}
            />
          </div>
        )}
      </div>

      {/* 2. Somewhere else the company owns. */}
      <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
        <label className="flex items-center gap-2 text-sm font-medium text-slate-800 dark:text-slate-100">
          <input type="checkbox" checked={remote.enabled} disabled={!row.can_manage} onChange={(e) => setRemote({ ...remote, enabled: e.target.checked })} />
          <Cloud className="size-4 text-sky-600" /> A second copy, off this server
        </label>

        {remote.enabled && (
          <div className="mt-3 space-y-3">
            <div className="flex flex-wrap gap-1.5">
              {DRIVERS.map((d) => (
                <button
                  key={d.key}
                  type="button"
                  disabled={!row.can_manage}
                  onClick={() => setRemote({ ...remote, driver: d.key })}
                  className={clsx('rounded-full px-3 py-1 text-xs font-medium ring-1',
                    remote.driver === d.key ? 'bg-brand-600 text-white ring-brand-600' : 'text-slate-600 ring-slate-200 dark:text-slate-300 dark:ring-slate-700')}
                >
                  {d.label}
                </button>
              ))}
            </div>
            <p className="text-xs text-slate-500">{DRIVERS.find((d) => d.key === remote.driver)?.note}</p>

            <div className="grid gap-3 sm:grid-cols-2">
              {remote.driver === 's3' && (
                <>
                  {field('Bucket', remote.bucket, (v) => setRemote({ ...remote, bucket: v }), 'company-mail-backup')}
                  {field('Region', remote.region, (v) => setRemote({ ...remote, region: v }), 'ap-south-1')}
                  {field('Endpoint (blank for AWS)', remote.endpoint, (v) => setRemote({ ...remote, endpoint: v }), 'https://s3.wasabisys.com')}
                  {field('Access key', remote.key, (v) => setRemote({ ...remote, key: v }), 'AKIA…')}
                </>
              )}
              {remote.driver === 'webdav' && (
                <>
                  {field('WebDAV address', remote.url, (v) => setRemote({ ...remote, url: v }), 'https://cloud.example.com/remote.php/dav/files/user')}
                  {field('Username', remote.username, (v) => setRemote({ ...remote, username: v }))}
                </>
              )}
              {remote.driver === 'gdrive' && field('Drive folder id', remote.folder_id, (v) => setRemote({ ...remote, folder_id: v }), 'from the folder’s URL')}
              {field('Folder inside it (optional)', remote.path, (v) => setRemote({ ...remote, path: v }), 'netvork')}
            </div>

            {remote.driver === 'gdrive' ? (
              <div>
                <Label>Service account key {remote.has_secret && <span className="font-normal text-slate-400">(saved - leave blank to keep)</span>}</Label>
                <Textarea rows={4} value={secret} onChange={(e) => setSecret(e.target.value)} placeholder='Paste the whole JSON key file here' spellCheck={false} />
              </div>
            ) : (
              <div className="max-w-sm">
                <Label>
                  {remote.driver === 'webdav' ? 'Password' : 'Secret key'}
                  {remote.has_secret && <span className="font-normal text-slate-400"> (saved - leave blank to keep)</span>}
                </Label>
                <Input type="password" autoComplete="new-password" value={secret} onChange={(e) => setSecret(e.target.value)} />
              </div>
            )}

            <p className="text-xs text-slate-500">
              {remote.last_run_at ? `Last copied ${fullDate(remote.last_run_at)}.` : 'Nothing copied there yet.'}
              {remote.last_error && <span className="text-red-600"> {remote.last_error}</span>}
            </p>

            {row.can_manage && (
              <Button size="sm" variant="secondary" disabled={busy !== null} onClick={() => run('test', async () => {
                const res = await mails.testBackup(row.uuid)
                if (res.ok) toast(res.message, 'success')
                else toastError(res.message)
              })}>
                Test this destination
              </Button>
            )}
          </div>
        )}
      </div>

      {/* 3. This computer. */}
      <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
        <label className="flex items-center gap-2 text-sm font-medium text-slate-800 dark:text-slate-100">
          <input type="checkbox" checked={local} disabled={!localFoldersSupported()} onChange={(e) => setLocal(e.target.checked)} />
          <Laptop className="size-4 text-violet-600" /> A copy on this computer
        </label>

        {!localFoldersSupported() ? (
          <p className="mt-1 text-xs text-slate-500">
            This browser cannot write to a folder for you - Chrome and Edge can. Use <b>Export as zip</b> above and keep it wherever you like.
          </p>
        ) : local ? (
          <div className="mt-2 space-y-2">
            <p className="text-xs text-slate-500">
              The folder is remembered on this computer only, since a mailbox opened from another machine is another computer.
              Whenever you open Mails here, a dated zip of the archive is written into it.
            </p>
            <div className="flex flex-wrap items-center gap-2">
              <Button size="sm" variant="secondary" onClick={() => run('pick', async () => {
                const name = await chooseFolder(row.uuid)
                if (name) {
                  setLocalName(name)
                  toast(`Copies will be written to “${name}”.`, 'success')
                }
              })}>
                <FolderOpen className="size-3.5" /> {localName ? 'Change folder' : 'Choose folder'}
              </Button>
              {localName && (
                <>
                  <span className="flex items-center gap-1 text-xs text-slate-600 dark:text-slate-300"><HardDrive className="size-3.5" /> {localName}</span>
                  <Button size="sm" variant="secondary" disabled={busy !== null} onClick={syncLocal}>
                    {busy === 'local' ? 'Writing…' : 'Sync now'}
                  </Button>
                </>
              )}
            </div>
          </div>
        ) : null}
      </div>

      {row.can_manage && (
        <Button disabled={save.isPending} onClick={() => save.mutate()}>
          {save.isPending ? 'Saving…' : 'Save backup settings'}
        </Button>
      )}
    </div>
  )
}
