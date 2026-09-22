import type { MailAddress, MailFull } from '../../../api/mails'

/** "Priyanshu" if there is a name, the address if not. */
export function who(address: MailAddress | { email?: string | null; name?: string | null } | null | undefined): string {
  if (!address) return ''
  return (address.name && address.name.trim()) || address.email || ''
}

/** "Priyanshu <p@x.com>" - the form an address field accepts back. */
export function addressText(address: MailAddress): string {
  return address.name ? `${address.name} <${address.email}>` : address.email
}

/**
 * When a mail was sent, the way a mail list says it: the time for today,
 * the day and month for this year, the full date before that.
 */
export function mailDate(iso: string | null | undefined, now: Date = new Date()): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  if (d.toDateString() === now.toDateString()) {
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
  }
  if (d.getFullYear() === now.getFullYear()) {
    return d.toLocaleDateString([], { day: 'numeric', month: 'short' })
  }
  return d.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' })
}

/** The full date and time, for the reader's header. */
export function fullDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })
}

/**
 * "Re: Rates", never "Re: Re: Re: Rates".
 *
 * Every Re: and Fwd: already on the subject is worn off before the new one
 * goes on, which is how a thread stays readable after twenty replies.
 */
export function replySubject(subject: string | null | undefined, kind: 'Re' | 'Fwd'): string {
  const bare = (subject ?? '').replace(/^(\s*(re|fw|fwd|aw|sv)\s*(\[\d+\])?\s*:\s*)+/i, '').trim()
  return `${kind}: ${bare}`
}

/**
 * Who a reply goes to.
 *
 * Reply answers whoever sent it - or its Reply-To, when they asked for
 * answers elsewhere. Reply all adds everybody else it was addressed to,
 * minus the person replying, since nobody wants their own reply back.
 */
export function replyRecipients(message: MailFull, myAddresses: string[], all: boolean): { to: string[]; cc: string[] } {
  const mine = new Set(myAddresses.map((a) => a.toLowerCase()))
  const sentByMe = mine.has((message.from_email ?? '').toLowerCase())

  // Replying to something I sent means writing to the people I sent it to.
  const primary: MailAddress[] = sentByMe
    ? message.to
    : [{ email: message.reply_to || message.from_email || '', name: message.reply_to ? null : message.from_name }]

  const to = primary.filter((a) => a.email && !mine.has(a.email.toLowerCase()))
  if (!all) return { to: dedupe(to).map(addressText), cc: [] }

  const already = new Set(to.map((a) => a.email.toLowerCase()))
  const others = [...(sentByMe ? [] : message.to), ...message.cc]
    .filter((a) => a.email && !mine.has(a.email.toLowerCase()) && !already.has(a.email.toLowerCase()))

  return { to: dedupe(to).map(addressText), cc: dedupe(others).map(addressText) }
}

function dedupe(list: MailAddress[]): MailAddress[] {
  const seen = new Set<string>()
  return list.filter((a) => {
    const key = a.email.toLowerCase()
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}

const escape = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

/** The quoted original under a reply, the way every mail program shows it. */
export function quoteForReply(message: MailFull): string {
  const line = `On ${fullDate(message.date)}, ${escape(who({ name: message.from_name, email: message.from_email }))} wrote:`
  const body = message.body_html || `<div style="white-space:pre-wrap">${escape(message.body_text ?? '')}</div>`

  return `<br><br><div class="mail-quote"><p style="margin:0 0 6px;color:#64748b">${line}</p>`
    + `<blockquote style="margin:0;padding-left:12px;border-left:3px solid #cbd5e1;color:#475569">${body}</blockquote></div>`
}

/** The header block over a forwarded mail. */
export function forwardBlock(message: MailFull): string {
  const to = message.to.map(addressText).join(', ')
  const body = message.body_html || `<div style="white-space:pre-wrap">${escape(message.body_text ?? '')}</div>`

  return '<br><br><div class="mail-quote" style="color:#475569">---------- Forwarded message ---------<br>'
    + `From: ${escape(who({ name: message.from_name, email: message.from_email }))} &lt;${escape(message.from_email ?? '')}&gt;<br>`
    + `Date: ${escape(fullDate(message.date))}<br>Subject: ${escape(message.subject ?? '')}<br>To: ${escape(to)}<br><br>${body}</div>`
}

/** Plain text - an AI draft - as the HTML the editor holds, paragraph by paragraph. */
export function textToHtml(text: string): string {
  return text
    .split(/\n{2,}/)
    .map((p) => `<p>${escape(p).replace(/\n/g, '<br>')}</p>`)
    .join('')
}

/** 1536 -> "1.5 KB". */
export function sizeLabel(bytes: number | null | undefined): string {
  if (!bytes) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(bytes < 10 * 1024 ? 1 : 0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

/** The colour the mail screens wear, as a person chose it. */
export const ACCENTS: Record<string, { solid: string; soft: string; text: string; ring: string }> = {
  brand: { solid: 'bg-brand-600 hover:bg-brand-700', soft: 'bg-brand-50 dark:bg-brand-500/10', text: 'text-brand-600 dark:text-brand-300', ring: 'ring-brand-500' },
  emerald: { solid: 'bg-emerald-600 hover:bg-emerald-700', soft: 'bg-emerald-50 dark:bg-emerald-500/10', text: 'text-emerald-600 dark:text-emerald-300', ring: 'ring-emerald-500' },
  violet: { solid: 'bg-violet-600 hover:bg-violet-700', soft: 'bg-violet-50 dark:bg-violet-500/10', text: 'text-violet-600 dark:text-violet-300', ring: 'ring-violet-500' },
  rose: { solid: 'bg-rose-600 hover:bg-rose-700', soft: 'bg-rose-50 dark:bg-rose-500/10', text: 'text-rose-600 dark:text-rose-300', ring: 'ring-rose-500' },
  amber: { solid: 'bg-amber-600 hover:bg-amber-700', soft: 'bg-amber-50 dark:bg-amber-500/10', text: 'text-amber-700 dark:text-amber-300', ring: 'ring-amber-500' },
  slate: { solid: 'bg-slate-700 hover:bg-slate-800', soft: 'bg-slate-100 dark:bg-slate-800', text: 'text-slate-700 dark:text-slate-200', ring: 'ring-slate-500' },
}

/** What each folder is called on screen. */
export const FOLDER_TITLES: Record<string, string> = {
  inbox: 'Inbox',
  outbox: 'Outbox',
  drafts: 'Drafts',
  scheduled: 'Scheduled',
  sent: 'Sent',
  spam: 'Spam / Junk',
  trash: 'Trash',
  archive: 'Archive',
  starred: 'Starred',
}
