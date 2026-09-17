import { useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Download, Paperclip, Trash2, Upload } from 'lucide-react'
import type { CrmSalaryDocument } from '../../api/crm'
import { crm } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { saveBlob } from '../../lib/download'
import { Button, Input, Label, Modal } from '../../components/ui'

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

/** 40 kB reads as 40 kB, not 40960. */
function size(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} kB`

  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

/**
 * The bank's own paperwork for a payroll month: the transfer advice, the
 * statement page, the confirmation that the money left.
 *
 * Kept here rather than in somebody's mail because this is where it is
 * looked for - beside the month it proves - and because a transfer advice
 * names every employee's account on one page. Private disk, behind the
 * salary right, never an employee's to read.
 *
 * Each file carries a note of its own. "Document(3).pdf" answers nothing a
 * year later; "HDFC advice, batch 2, the four paid late" answers everything.
 */
export default function BankDocumentsModal({ year, month, documents, onChanged, onClose }: {
  year: number
  month: number
  documents: CrmSalaryDocument[]
  onChanged: () => void
  onClose: () => void
}) {
  const { toast, toastError } = useToast()
  const fileRef = useRef<HTMLInputElement>(null)
  const [picked, setPicked] = useState<File | null>(null)
  const [note, setNote] = useState('')

  const upload = useMutation({
    mutationFn: () => crm.salary.addDocument({ year, month, file: picked!, note: note.trim() || undefined }),
    onSuccess: (res) => {
      setPicked(null)
      setNote('')
      if (fileRef.current) fileRef.current.value = ''
      onChanged()
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const remove = useMutation({
    mutationFn: (uuid: string) => crm.salary.deleteDocument(uuid),
    onSuccess: onChanged,
    onError: (err) => toastError(errorMessage(err)),
  })

  const describe = useMutation({
    mutationFn: ({ uuid, body }: { uuid: string; body: string }) => crm.salary.describeDocument(uuid, body),
    onSuccess: onChanged,
    onError: (err) => toastError(errorMessage(err)),
  })

  const download = async (d: CrmSalaryDocument) => {
    try {
      saveBlob(await crm.salary.downloadDocument(d.uuid), d.name)
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  return (
    <Modal title={`Bank documents — ${MONTHS[month - 1]} ${year}`} onClose={onClose} wide>
      <div className="space-y-4">
        {documents.length === 0 ? (
          <p className="text-sm text-slate-400">
            Nothing filed for this month yet. The transfer advice, a statement page, the bank’s
            confirmation — whatever proves this payroll went out.
          </p>
        ) : (
          <ul className="space-y-2">
            {documents.map((d) => (
              <li key={d.uuid} className="flex items-start gap-2 rounded-xl bg-slate-50 p-2.5 text-sm dark:bg-slate-800/60">
                <Paperclip className="mt-0.5 size-4 shrink-0 text-slate-400" />
                <div className="min-w-0 flex-1">
                  <p className="truncate font-medium text-slate-700 dark:text-slate-200">{d.name}</p>
                  <p className="text-[11px] text-slate-400">
                    {size(d.size)} · {d.by} · {d.at?.slice(0, 10)}
                  </p>
                  {/* Written on the file rather than kept apart from it, and
                      changeable - what a document is for is often clear only
                      after somebody asks. */}
                  <Input
                    className="mt-1 w-full"
                    defaultValue={d.note ?? ''}
                    maxLength={500}
                    placeholder="What is this? (bank, batch, what it covers)"
                    onBlur={(e) => {
                      if (e.target.value !== (d.note ?? '')) describe.mutate({ uuid: d.uuid, body: e.target.value })
                    }}
                  />
                </div>
                <button
                  onClick={() => download(d)}
                  aria-label={`Download ${d.name}`}
                  className="shrink-0 rounded p-1.5 text-slate-400 hover:text-emerald-600"
                >
                  <Download className="size-4" />
                </button>
                <button
                  onClick={() => { if (confirm(`Remove ${d.name}?`)) remove.mutate(d.uuid) }}
                  aria-label={`Remove ${d.name}`}
                  className="shrink-0 rounded p-1.5 text-slate-400 hover:text-red-500"
                >
                  <Trash2 className="size-4" />
                </button>
              </li>
            ))}
          </ul>
        )}

        <div className="space-y-2 rounded-xl border border-dashed border-slate-300 p-3 dark:border-slate-700">
          <Label>Attach a document</Label>
          <input
            ref={fileRef}
            type="file"
            onChange={(e) => setPicked(e.target.files?.[0] ?? null)}
            className="block w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 dark:file:bg-slate-800 dark:file:text-slate-200"
          />
          <Input
            value={note}
            maxLength={500}
            placeholder="What is it? (optional)"
            onChange={(e) => setNote(e.target.value)}
          />
          <p className="text-[11px] text-slate-400">
            Up to 10 MB. Kept on the private disk behind the salary right — employees never see these.
          </p>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={onClose}>Close</Button>
            <Button onClick={() => upload.mutate()} disabled={!picked || upload.isPending}>
              <Upload className="size-4" /> {upload.isPending ? 'Attaching…' : 'Attach'}
            </Button>
          </div>
        </div>
      </div>
    </Modal>
  )
}
