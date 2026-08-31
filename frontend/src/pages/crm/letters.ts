import type { CrmEmployeeFull } from '../../api/crm'

/**
 * One-click HR letters, generated from the data already on the employee
 * profile — name, parents, address, joining/resignation dates, salary and
 * designation history. Each opens as a print-ready page (Ctrl+P → PDF),
 * the same flow as invoice printing.
 */

export type LetterType = 'offer' | 'appointment' | 'promotion' | 'resignation' | 'fnf'

export const LETTER_LABELS: Record<LetterType, string> = {
  offer: 'Offer letter',
  appointment: 'Appointment letter',
  promotion: 'Promotion letter',
  resignation: 'Resignation acceptance',
  fnf: 'Full & final settlement',
}

const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN')

const longDate = (iso?: string | null) =>
  iso ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' }) : '—'

/** Records arrive newest-first; the starting salary is the oldest. */
const startingSalary = (e: CrmEmployeeFull) => e.salary_records[e.salary_records.length - 1]
const latestSalary = (e: CrmEmployeeFull) => e.salary_records[0]
const latestPromotion = (e: CrmEmployeeFull) => e.salary_records.find((r) => r.designation)

export function letterAvailability(e: CrmEmployeeFull): Record<LetterType, { enabled: boolean; why?: string }> {
  const hasSalary = e.salary_records.length > 0
  return {
    offer: { enabled: hasSalary, why: hasSalary ? undefined : 'Add a starting salary first' },
    appointment: { enabled: hasSalary && !!e.joined_at, why: !hasSalary ? 'Add a starting salary first' : e.joined_at ? undefined : 'Set the joining date first' },
    promotion: { enabled: !!latestPromotion(e), why: latestPromotion(e) ? undefined : 'No revision with a designation change yet' },
    resignation: { enabled: !!e.resigned_at, why: e.resigned_at ? undefined : 'Set the resignation date first' },
    fnf: {
      enabled: !!e.resigned_at && e.status === 'inactive',
      why: !e.resigned_at ? 'Set the resignation date first' : e.status !== 'inactive' ? 'Deactivate the employee first (they must have left)' : undefined,
    },
  }
}

function paragraphs(type: LetterType, e: CrmEmployeeFull, org: string, fnfAmount?: number, promoRecordId?: number): { title: string; body: string[]; letterDate?: string | null } {
  const name = [e.title, e.name].filter(Boolean).join(' ')
  const parentage = e.father_name ? ` (S/D/o ${e.father_name}${e.mother_name ? ' and ' + e.mother_name : ''})` : ''
  const start = startingSalary(e)
  const latest = latestSalary(e)
  // A specific promotion from the history reprints as it stood; without an
  // id the letter is for the latest promotion.
  const promo = (promoRecordId !== undefined
    ? e.salary_records.find((r) => r.id === promoRecordId)
    : undefined) ?? latestPromotion(e)

  switch (type) {
    case 'offer':
      return {
        title: 'Offer Letter',
        body: [
          `Dear ${name}${parentage},`,
          `With reference to your application and the subsequent discussions, we are pleased to offer you the position of <b>${e.designation ?? 'Executive'}</b>${e.department ? ` in our ${e.department} department` : ''} at ${org}.`,
          `Your monthly remuneration will be <b>${inr(start?.amount ?? 0)}</b> (${start?.currency ?? 'INR'} per month), payable as per the company's payroll cycle.${e.joined_at ? ` Your date of joining will be <b>${longDate(e.joined_at)}</b>.` : ''}`,
          `This offer is subject to the verification of your documents and testimonials. Kindly sign and return a copy of this letter as a token of your acceptance.`,
          `We welcome you and look forward to a long and successful association.`,
        ],
      }
    case 'appointment':
      return {
        title: 'Appointment Letter',
        body: [
          `Dear ${name}${parentage},`,
          `We are pleased to confirm your appointment as <b>${e.designation ?? 'Executive'}</b>${e.department ? ` in the ${e.department} department` : ''} of ${org}, effective <b>${longDate(e.joined_at)}</b>.`,
          `Your monthly remuneration is <b>${inr(start?.amount ?? 0)}</b> per month. You will be governed by the rules and regulations of the company as amended from time to time.${e.manager?.name ? ` You will report to <b>${e.manager.name}</b>.` : ''}`,
          `Your employment particulars — including present address on record (${e.present_address ?? '—'}) — form part of this appointment. Any change must be intimated to the HR department in writing.`,
          `Please sign and return the duplicate copy of this letter as acceptance of the above terms.`,
        ],
      }
    case 'promotion':
      return {
        title: 'Promotion Letter',
        // A reprint from history carries the date the promotion was made,
        // not today's — the letter reads as it stood.
        letterDate: promo?.created_at ?? null,
        body: [
          `Dear ${name},`,
          `In recognition of your performance and contribution to ${org}, we are pleased to promote you to the position of <b>${promo?.designation}</b>, effective <b>${longDate(promo?.effective_from)}</b>.`,
          `Your revised remuneration will be <b>${inr(promo?.amount ?? latest?.amount ?? 0)}</b> per month. All other terms and conditions of your employment remain unchanged.`,
          `We congratulate you on this achievement and wish you continued success in your new role.`,
        ],
      }
    case 'resignation':
      return {
        title: 'Resignation Acceptance Letter',
        body: [
          `Dear ${name},`,
          `This is to acknowledge and accept your resignation from the position of <b>${e.designation ?? 'Executive'}</b> at ${org}. Your last working day is <b>${longDate(e.resigned_at)}</b>.`,
          `We request you to complete the handover of your responsibilities and return any company property in your possession on or before your last working day.`,
          `Your full and final settlement will be processed as per company policy after your relieving date.`,
          `We thank you for your services and contribution during your tenure${e.joined_at ? ` since ${longDate(e.joined_at)}` : ''}, and wish you success in your future endeavours.`,
        ],
      }
    case 'fnf':
      return {
        title: 'Full & Final Settlement Letter',
        body: [
          `Dear ${name},`,
          `This is with reference to your resignation and relieving from the position of <b>${e.designation ?? 'Executive'}</b> at ${org}, with your last working day being <b>${longDate(e.resigned_at)}</b>.`,
          `Your full and final settlement has been computed at <b>${inr(fnfAmount ?? 0)}</b>, covering salary dues, leave encashment and other admissible components, less applicable deductions. The amount will be credited to your bank account on record${e.bank_account_no ? ` (A/c ending ${e.bank_account_no.slice(-4)})` : ''}.`,
          `On acceptance of this settlement, you confirm that you have no further claims of any nature against ${org} or its management.`,
          `We thank you for your association and wish you the very best.`,
        ],
      }
  }
}

export function openLetter(type: LetterType, e: CrmEmployeeFull, org: string, fnfAmount?: number, promoRecordId?: number): void {
  const { title, body, letterDate } = paragraphs(type, e, org, fnfAmount, promoRecordId)
  const today = (letterDate ? new Date(letterDate) : new Date())
    .toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' })
  const ref = `${org.replace(/[^A-Za-z]/g, '').slice(0, 4).toUpperCase()}/HR/${(letterDate ? new Date(letterDate) : new Date()).getFullYear()}/${e.employee_code ?? '—'}`

  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${title} — ${e.name ?? ''}</title>
<style>
  body { font-family: Georgia, 'Times New Roman', serif; color: #1e293b; max-width: 760px; margin: 0 auto; padding: 48px 40px; line-height: 1.7; }
  .head { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 3px solid #059669; padding-bottom: 12px; }
  .org { font-size: 26px; font-weight: bold; letter-spacing: 0.5px; }
  .meta { text-align: right; font-size: 13px; color: #475569; }
  h1 { font-size: 18px; text-align: center; text-decoration: underline; margin: 36px 0 24px; }
  p { margin: 0 0 16px; text-align: justify; }
  .sign { margin-top: 56px; display: flex; justify-content: space-between; font-size: 14px; }
  .sign div { width: 45%; }
  .line { border-top: 1px solid #94a3b8; margin-top: 56px; padding-top: 6px; }
  @media print { body { padding: 24px 8px; } }
</style></head><body>
<div class="head">
  <div class="org">${org}</div>
  <div class="meta">Ref: ${ref}<br>Date: ${today}</div>
</div>
<h1>${title}</h1>
${body.map((p) => `<p>${p}</p>`).join('')}
<div class="sign">
  <div>Employee's acceptance<div class="line">${e.name ?? ''}</div></div>
  <div style="text-align:right">For <b>${org}</b><div class="line">Authorised Signatory</div></div>
</div>
<script>window.print()</script>
</body></html>`

  const w = window.open('', '_blank')
  if (!w) return
  w.document.write(html)
  w.document.close()
}
