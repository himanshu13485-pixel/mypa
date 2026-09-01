import { api } from './client'

/*
 * The CRM addon's API surface. Kept apart from endpoints.ts on purpose: the
 * addon is a separate product and its types should never leak into (or lean
 * on) the personal Netvork surface.
 */

/*
 * Which organization hat this browser wears. Users with several memberships
 * (the Super Admin who entered a company workspace) switch via
 * setCrmOrg(); every CRM request then carries the choice as a header.
 */
const CRM_ORG_KEY = 'netvork-crm-org'

export function setCrmOrg(uuid: string | null): void {
  if (uuid) localStorage.setItem(CRM_ORG_KEY, uuid)
  else localStorage.removeItem(CRM_ORG_KEY)
}

export function getCrmOrg(): string | null {
  return localStorage.getItem(CRM_ORG_KEY)
}

api.interceptors.request.use((config) => {
  const org = localStorage.getItem(CRM_ORG_KEY)
  if (org && config.url?.startsWith('/crm')) {
    config.headers['X-Crm-Org'] = org
  }
  return config
})

export interface CrmMe {
  enabled: boolean
  is_super_admin: boolean
  has_team?: boolean
  member: {
    uuid: string
    name?: string | null
    crm_role: 'admin' | 'subadmin' | 'employee'
    is_oversight?: boolean
    employee_code: string | null
    department: string | null
    designation: string | null
    is_salesperson: boolean
    rights: Record<string, string[]>
    /** The delicate acts this member holds, by name. */
    capabilities?: string[]
    /** True when people report to them — a Team Head. */
    leads_a_team?: boolean
    /** Identity art: uploaded photo, picked illustration, gender default. */
    photo_path?: string | null
    avatar?: string | null
    gender?: string | null
    /** Today is their day — the shell turns festive. */
    birthday_today?: boolean
    /** Admin, or the Subadmin named for the accounting export. */
    can_export?: boolean
    /** Who this member may hand work to; absent for a manager (anyone). */
    team_member_uuids?: string[]
  } | null
  organization: { uuid: string; name: string; code: string } | null
}

/**
 * A Dedicated Company Workspace field: an extra form field this company
 * asked for and the Super Admin approved. It exists in this workspace only.
 */
export interface CrmCustomField {
  uuid: string
  entity: 'client' | 'work_order' | 'invoice' | 'tax'
  key: string
  label: string
  type: 'text' | 'textarea' | 'number' | 'alphanumeric' | 'checkbox' | 'date' | 'select'
  options: string[] | null
  is_required: boolean
  /** True when the row re-words one of the built-in Work Order columns. */
  is_builtin?: boolean
  is_hidden?: boolean
  tax_kind?: 'discount' | 'tax' | 'deduction' | null
  tax_basis?: 'subtotal' | 'taxable' | null
  default_rate?: string | null
  help: string | null
  status: 'pending' | 'approved' | 'rejected'
  reason: string | null
  requested_by: string | null
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  organization?: { uuid: string; name: string } | null
  created_at: string | null
}

/** The forms a DCW field can be attached to. */
export const CRM_DCW_ENTITY_LABELS: Record<string, string> = {
  client: 'Client form',
  work_order: 'Work Order (invoice & proforma lines)',
  invoice: 'Document (proforma & invoice header)',
  tax: 'Tax line (money lines on the document)',
}

export const CRM_TAX_KIND_LABELS: Record<string, string> = {
  discount: 'Discount — comes off the subtotal',
  tax: 'Tax — adds to the total',
  deduction: 'Deduction — comes off the total',
}

/** One column of a company's Work Order, ours or theirs. */
export interface CrmWorkOrderColumn {
  key: string
  source: 'builtin' | 'custom'
  label: string
  type: string
  options: string[] | null
  is_required: boolean
  hidden: boolean
  help: string | null
  customised: boolean
}

/** Commission paid to a client out of one sale — an expense, never a line. */
export interface CrmCommission {
  uuid: string
  expense_date: string
  payee: string
  amount: number
  payment_mode: string | null
  note: string | null
  invoice: { uuid: string; number: string; total: string } | null
  client: string | null
  salesperson: string | null
  recorded_by: string | null
  created_at: string | null
}

/** One internal remark on a document — office-only, never printed. */
export interface CrmInvoiceNote {
  uuid: string
  body: string
  by: string
  at: string | null
  is_mine: boolean
  can_delete: boolean
}

/** A document told to happen again. */
export interface CrmRecurringInvoice {
  uuid: string
  source: { uuid: string; number: string; kind: 'proforma' | 'invoice'; total: string; currency: string } | null
  client: { uuid: string; company_name: string } | null
  salesperson: string | null
  frequency: string
  frequency_label: string
  starts_on: string
  next_run_on: string
  ends_on: string | null
  max_occurrences: number | null
  occurrences: number
  counts_source: boolean
  show_on_document: boolean
  auto_email: boolean
  auto_payment_link: boolean
  status: 'active' | 'paused' | 'completed' | 'cancelled'
  last_invoice: { uuid: string; number: string; invoice_date: string | null; payment_status: string } | null
  last_run_at: string | null
  last_error: string | null
  created_by: string | null
  created_at: string | null
}

/**
 * The service span a validity pair promises, in whole months — a part
 * month counts (26 Aug → 15 Nov is still 3). Mirrors the backend rule the
 * incentive spread runs on, so what the paper says is what the spread does.
 */
export function validityMonths(from?: string | null, to?: string | null): number | null {
  if (!from || !to || to <= from) return null
  const [fy, fm, fd] = from.split('-').map(Number)
  const [ty, tm, td] = to.split('-').map(Number)
  const months = (ty - fy) * 12 + (tm - fm) + (td > fd ? 1 : 0)
  return Math.max(1, months)
}

export const CRM_RECURRING_FREQUENCY_LABELS: Record<string, string> = {
  once: 'One time',
  weekly: 'Every week',
  monthly: 'Every month',
  quarterly: 'Every 3 months',
  half_yearly: 'Every 6 months',
  yearly: 'Every year',
}

/** One company's Cashfree account, as the UI is allowed to see it. */
export interface CrmGatewaySettings {
  provider: string
  mode: 'sandbox' | 'production'
  app_id: string | null
  has_secret: boolean
  is_active: boolean
  api_version?: string
  webhook_url: string
}

/** A "pay this online" link raised against a document. */
export interface CrmPaymentLink {
  uuid: string
  link_id: string
  link_url: string | null
  amount: string
  amount_paid: string
  currency: string
  status: 'active' | 'paid' | 'partially_paid' | 'expired' | 'cancelled' | 'failed'
  is_open: boolean
  expires_at: string | null
  paid_at: string | null
  created_by: string | null
  created_at: string | null
}

/** How a company settles payments, and when it chases them. */
export interface CrmPaymentSettings {
  settlement_mode: 'auto' | 'manual'
  reminders: { enabled: boolean; offsets: number[]; stop_after: number }
}

/** An invoice still owed money, as the outstanding ledger sees it. */
export interface CrmOutstandingRow {
  uuid: string
  number: string
  client: { uuid: string; company_name: string; contact_person: string | null; email: string | null; mobile: string | null } | null
  issuing_company: string | null
  salesperson: string | null
  invoice_date: string
  due_date: string | null
  currency: string
  total: number
  received: number
  balance: number
  days_overdue: number
  bucket: string
  payment_status: string
  reminders: number
  last_reminder: { at: string; by: string | null; channel: string; status: string } | null
  next_follow_up: string | null
}

export interface CrmOutstandingSummary {
  count: number
  outstanding: number
  overdue: number
  never_chased: number
  due_for_follow_up: number
  by_bucket: { key: string; label: string; count: number; amount: number }[]
}

/** One chase against one invoice. */
export interface CrmPaymentReminder {
  uuid: string
  channel: 'email' | 'note'
  to_email: string | null
  subject: string | null
  body: string | null
  status: 'sent' | 'failed' | 'logged'
  error: string | null
  balance: string
  next_follow_up: string | null
  by: string | null
  at: string | null
}

export interface CrmReminderDraft {
  to_email: string | null
  subject: string
  body: string
  balance: number
  days_overdue: number
}

/** One money line of a company's own tax setup. */
export interface CrmTaxLine {
  key: string
  source: 'builtin' | 'custom'
  label: string
  kind: 'discount' | 'tax' | 'deduction'
  basis: 'subtotal' | 'taxable'
  default_rate: number | null
  customised: boolean
}

/** A money line as it stood on one document. */
export interface CrmInvoiceTaxLine {
  key: string
  label: string
  kind: 'discount' | 'tax' | 'deduction'
  basis: 'subtotal' | 'taxable'
  rate: string | null
  amount: string
}

/** What each built-in column will and will not allow. */
export interface CrmWorkOrderBuiltin {
  label: string
  type: string
  can: ('rename' | 'hide' | 'type')[]
  types: string[]
}

export const CRM_FIELD_TYPE_LABELS: Record<string, string> = {
  text: 'Text',
  textarea: 'Long text',
  number: 'Number',
  alphanumeric: 'Alphanumeric',
  checkbox: 'Checkbox (yes/no)',
  date: 'Date',
  select: 'Dropdown',
}

export interface CrmMasters {
  departments: string[]
  designations: string[]
  payment_modes: string[]
  client_categories: string[]
  client_custom_fields: CrmCustomField[]
  /** This company's own Work Order method, for invoice and proforma lines. */
  work_order_custom_fields: CrmCustomField[]
  work_order_method: CrmWorkOrderColumn[]
  /** The document's own fields and money lines, likewise per company. */
  invoice_custom_fields: CrmCustomField[]
  invoice_method: CrmWorkOrderColumn[]
  tax_setup: CrmTaxLine[]
  expense_categories: string[]
  leave_categories: string[]
  approval_types: string[]
  lead_sources: string[]
  lead_subjects: string[]
  lead_statuses: string[]
  modules: string[]
  abilities: string[]
  /** What the rights screen offers beyond the module matrix. */
  capabilities: { key: string; group: string; label: string }[]
  issuing_companies: {
    logo_path?: string | null
    currency?: string
    pays_salary?: boolean
    pan?: string | null
    phone?: string | null
    email?: string | null
    id: number
    name: string
    gstin: string | null
    address: string | null
    state_code: string | null
    invoice_prefix: string
    proforma_prefix: string
    is_active: boolean
  }[]
  bank_accounts: { id: number; label: string; bank_name: string | null; account_no: string | null; ifsc: string | null; is_active: boolean; issuing_company_id?: number | null; issuing_company_name?: string | null }[]
  members: { uuid: string; name: string | null; employee_code: string | null; is_salesperson: boolean; crm_role?: string }[]
}

export interface CrmEmployee {
  uuid: string
  name: string | null
  email: string | null
  crm_role: 'admin' | 'subadmin' | 'employee'
  status: 'active' | 'inactive'
  employee_code: string | null
  department: string | null
  designation: string | null
  is_salesperson: boolean
  joined_at: string | null
  probation_days: number | null
  /** The Admin's late waiver: lateness never counted, marked Present. */
  late_waived?: boolean
  /** The clock is not this person's measure — no punch, never absent. */
  punch_waived?: boolean
  probation_ends_on: string | null
  on_probation: boolean
  resigned_at: string | null
  dob: string | null
  manager: { uuid: string; name: string | null } | null
  /** Team Workspace leaders this person is handled by (when loaded). */
  team_leaders?: { uuid: string; name: string | null }[] | null
}

/** One Netvork account the employee search turned up. */
export interface CrmAccountMatch {
  name: string
  email: string
  username: string | null
  /** Already on this company's payroll — offered, but not choosable. */
  already_member: boolean
}

export interface CrmEmployeeFull extends CrmEmployee {
  /** The delicate acts granted to this employee by name. */
  capabilities?: string[]
  title: string | null
  batch: string | null
  gender: string | null
  father_name: string | null
  father_phone: string | null
  mother_name: string | null
  mother_phone: string | null
  present_address: string | null
  present_phone: string | null
  office_phone: string | null
  permanent_address: string | null
  permanent_phone: string | null
  personal_email: string | null
  pf_no: string | null
  esi_no: string | null
  pan_no: string | null
  aadhaar_no: string | null
  bank_name: string | null
  bank_account_no: string | null
  bank_ifsc: string | null
  bank_account_name: string | null
  rights: Record<string, string[]>
  note: string | null
  salary_records: { id: number; amount: string; currency: string; effective_from: string; designation: string | null; note: string | null; created_at?: string | null }[]
  documents: { uuid: string; name: string; mime: string | null; size: number; uploaded_at: string }[]
  /** True when the viewer may not read this person's private details. */
  personal_hidden?: boolean
  /** Team Workspace: who this person handles (Admin/Subadmin decide). */
  team?: { uuid: string; name: string | null }[]
  /** …and whose hands this person is in. */
  team_leaders?: { uuid: string; name: string | null }[]
}

export interface CrmClient {
  uuid: string
  company_name: string
  title: string | null
  contact_person: string | null
  designation: string | null
  address: string | null
  city: string | null
  state: string | null
  pincode: string | null
  country: string | null
  telephone: string | null
  mobile: string | null
  email: string | null
  alternate_email: string | null
  website: string | null
  gst_no: string | null
  pan_no: string | null
  category: string | null
  /** Came back after a closed lead. */
  is_repeat?: boolean
  repeat_count?: number
  status: 'active' | 'inactive'
  assigned_member: { uuid: string; name: string | null } | null
  shared_with: { uuid: string; name: string | null }[]
  created_at: string | null
  custom_fields?: Record<string, string | number | boolean>
  notes?: string | null
  invoices?: CrmInvoiceRow[]
  transfers?: CrmClientTransfer[]
}

/** A line in the client's ownership trail. */
export interface CrmClientTransfer {
  action: 'client.transferred' | 'client.shared'
  by: string | null
  at: string | null
  from: string | null
  to: string | null
  invoices_kept: number | null
  note: string | null
}

/** "This client already exists" — someone asking to be let in on it. */
export interface CrmClientAccessRequest {
  uuid: string
  client: { uuid: string; company_name: string } | null
  owner: string | null
  requested_by: string | null
  note: string | null
  status: 'pending' | 'approved' | 'rejected'
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  created_at: string | null
}

export interface CrmInvoiceRow {
  uuid: string
  kind: 'proforma' | 'invoice'
  number: string
  status: string
  invoice_date: string
  due_date?: string | null
  client: { uuid: string; company_name: string; contact_person: string | null; email?: string | null } | null
  issuing_company?: { id: number; name: string } | null
  salesperson?: { uuid: string; name: string | null; email?: string | null } | null
  currency: string
  subtotal?: string
  total: string
  total_fx?: string | null
  fx_currency?: string | null
  payment_status: string
  dispatch_status: string
  converted?: boolean
  /** "Recurring · 2 of 12", stamped when the copy was raised. */
  recurring_note?: string | null
  /** True for any document a schedule raised, noted on paper or not. */
  is_recurring?: boolean
  /** Set on a proforma row once it has become a tax invoice. */
  converted_to_doc?: { uuid: string; number: string } | null
}

/** One line of the Invoice Log / Proforma Log. */
export interface CrmInvoiceLogEntry {
  id: number
  action: string
  by: string | null
  at: string
  number: string | null
  client: string | null
  total: number | null
  amount: number | null
  invoice: string | null
  from_proforma: string | null
  fields: string[] | null
  note: string | null
  document: { uuid: string; number: string; status: string; payment_status: string } | null
}

export interface CrmInvoiceLogSummary {
  total: number
  by_action: { action: string; count: number }[]
  daily: { day: string; count: number }[]
  actions: string[]
}

export interface CrmInvoiceItem {
  id?: number
  membership: string | null
  plan_name: string | null
  description: string | null
  custom_fields?: Record<string, string | number | boolean>
  validity_from: string | null
  validity_to: string | null
  qty: string | number
  unit_price: string | number
  amount?: string
  amount_fx?: string | number | null
}

export interface CrmInvoiceFull extends CrmInvoiceRow {
  client_full: { address: string | null; city: string | null; state: string | null; pincode: string | null; country: string | null; gst_no: string | null; email: string | null; mobile: string | null } | null
  issuing_company_full: { address: string | null; gstin: string | null; pan: string | null; state_code: string | null; phone: string | null; email: string | null } | null
  client_category: string | null
  pricing_tier: string
  terms_of_payment: string | null
  subscription_type: string | null
  discount: string
  cgst: string
  sgst: string
  igst: string
  other_tax: string
  tds: string
  /** Percentages, when the document was priced that way. */
  discount_rate: string | null
  cgst_rate: string | null
  sgst_rate: string | null
  igst_rate: string | null
  other_tax_rate: string | null
  tds_rate: string | null
  /** Subtotal less discounts — what the taxes are worked on. */
  taxable: number
  /** Every money line as it stood on this document. */
  tax_lines: CrmInvoiceTaxLine[]
  custom_fields?: Record<string, string | number | boolean>
  fx_rate: string | null
  subtotal_fx: string | null
  notes: string | null
  items: CrmInvoiceItem[]
  payments: {
    id: number
    /** The unique payment id (PAY-000123) a bank statement reconciles by. */
    payment_no?: string | null
    /** The gross the client paid — this is what settles the invoice. */
    amount: string
    /** What the gateway or bank kept out of it. */
    charge_amount: string
    charge_note: string | null
    /** What actually reached the bank: amount − charge. */
    net_amount: number
    amount_fx: string | null
    bank_account: string | null
    payment_mode: string | null
    reference_no: string | null
    drawee_bank: string | null
    instrument_date: string | null
    received_at: string
    note: string | null
  }[]
  amount_received: number
  collection_charges: number
  /** The client's cut booked against this sale (commission, named neutrally). */
  sale_costs_total?: number
  converted_from: { uuid: string; number: string } | null
  converted_to: { uuid: string; number: string } | null
}

export interface CrmLeadLogEntry {
  id: number
  action: string
  by: string | null
  at: string
  lead_no: number | null
  lead_uuid?: string | null
  note: string | null
  status: string | null
  next_follow_up: string | null
  fields: Record<string, unknown> | null
  client: string | null
  company_name: string | null
}

export interface CrmLead {
  uuid: string
  lead_no: number
  company_name: string
  contact_person: string | null
  phone: string | null
  mobile: string | null
  email: string | null
  amount: string
  lead_status: string
  is_urgent?: boolean
  duplicate_settled?: boolean
  follow_up_at: string | null
  follow_up_due: boolean
  subject: string | null
  lead_type: 'new' | 'existing'
  source: string | null
  assigned_member: { uuid: string; name: string | null } | null
  created_by: string | null
  shared_with: { uuid: string; name: string | null }[]
  /** How many times this lead came back after being closed. */
  reopen_count?: number
  closed_at?: string | null
  /** A LATER lead sharing a contact with an earlier one — the original stays clean. */
  is_duplicate?: boolean
  /** The lead number of the original it collides with. */
  duplicate_of?: number | null
  /** A pending Lead Duplication request is sitting on this lead. */
  has_pending_request?: boolean
  client: { uuid: string; company_name: string } | null
  created_at: string | null
  requirement?: string | null
  logs?: CrmLeadLogEntry[]
}

/** A lead whose follow-up moment has arrived — what the popup nags about. */
export interface CrmDueLead {
  is_urgent?: boolean
  uuid: string
  lead_no: number
  company_name: string
  contact_person: string | null
  mobile: string | null
  follow_up_at: string
  overdue_minutes: number
  assigned_to: string | null
}

/** A freshly arrived lead nobody has attended yet — the new-lead popup. */
export interface CrmNewLead {
  is_urgent?: boolean
  uuid: string
  lead_no: number
  company_name: string
  contact_person: string | null
  mobile: string | null
  created_by: string | null
  arrived_at: string | null
  waiting_minutes: number
}

/** Lead Duplication awaiting the Admin's share / transfer / reject. */
export interface CrmLeadAccessRequest {
  uuid: string
  lead: { uuid: string; lead_no: number; company_name: string; mobile: string | null; email: string | null } | null
  owner: string | null
  requested_by: string | null
  status: 'pending' | 'shared' | 'transferred' | 'rejected'
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  created_at: string | null
}

export interface CrmTargetRow {
  member_uuid: string
  name: string | null
  employee_code: string | null
  target: number
  achieved: number
  achieved_new: number
  achieved_existing: number
  due: number
  percent: number | null
  clients: number
  invoices: number
  per_client: number | null
  note: string | null
}

export interface CrmTargetsResponse {
  data: CrmTargetRow[]
  totals: {
    target: number
    achieved: number
    achieved_new: number
    achieved_existing: number
    due: number
    clients: number
    invoices: number
    per_client: number | null
  }
  year: number
  month: number
  end_year: number
  end_month: number
  /** How many months the reading covers — 1 is the plain monthly screen. */
  months: number
  label: string
  /** Targets are typed one month at a time, so a span is read-only. */
  editable: boolean
}

export type CrmGrowthPeriod = 'month' | 'quarter' | 'half' | 'year'

export interface CrmGrowthBucket {
  key: string
  label: string
  achieved: number
  target: number
  clients: number
  invoices: number
  /** The same bucket one year earlier, for the year-on-year reading. */
  last_year: number
  yoy: number | null
  previous: number | null
  growth: number | null
}

export interface CrmGrowthResponse {
  period: CrmGrowthPeriod
  points: number
  scope: 'mine' | 'team'
  salesperson: string | null
  salespeople: { uuid: string; name: string | null; is_me: boolean }[] | null
  buckets: CrmGrowthBucket[]
  totals: {
    achieved: number
    last_year: number
    yoy: number | null
    clients: number
    best: string | null
  }
}

export interface CrmContestRow {
  audience?: string
  uuid: string
  title: string
  starts_at: string
  ends_at: string
  status: 'draft' | 'published' | 'closed'
  phase: 'draft' | 'upcoming' | 'live' | 'ended'
  questions: number
  my_answers: number
  my_points: number | null
}

export interface CrmContestQuestion {
  id: number
  type: 'option' | 'text'
  question: string
  options: string[] | null
  points: number
  correct_option: number | null
  correct_text: string | null
  my_answer: {
    answer_option: number | null
    answer_text: string | null
    is_correct: boolean | null
    points_awarded: number | null
  } | null
}

export interface CrmContestFull {
  audience_department?: string | null
  audience_member_uuid?: string | null
  uuid: string
  title: string
  description: string | null
  starts_at: string
  ends_at: string
  status: string
  phase: 'draft' | 'upcoming' | 'live' | 'ended'
  manages: boolean
  questions: CrmContestQuestion[]
}

export interface CrmContestResults {
  title: string
  phase: string
  max_points: number
  board: {
    member_uuid: string | null
    name: string | null
    answered: number
    correct: number
    pending: number
    points: number
    last_answer_at: string | null
    rank: number
  }[]
  pending: { id: number; name: string | null; question: string | null; answer_text: string | null; points: number | null }[]
}

export interface CrmKpiAssignment {
  parameter_id: number
  name: string | null
  unit: 'count' | 'percent' | 'currency' | 'boolean'
  weightage: number
  daily_target: string | number
}

export interface CrmDwrRow {
  uuid: string
  work_date: string
  submitted_at: string | null
  member: { uuid: string; name: string | null } | null
  score: number | null
  band: string | null
  note: string | null
  entries?: {
    parameter_id: number | null
    name: string
    unit: string
    weightage: number
    target: string
    value: string
    achievement: number
  }[]
}

export interface CrmDwrStats {
  daily: { date: string; avg_score: number; count: number }[]
  bands: { band: string; count: number }[]
  members: { name: string | null; avg_score: number; reports: number }[]
}

export interface CrmPunchRow {
  /** Null on a day nobody punched — the calendar shows it all the same. */
  id: number | null
  work_date: string
  member: {
    uuid: string
    name: string | null
    /** The account the punch was made from, not just the person. */
    login: string | null
    employee_code: string | null
  } | null
  punch_in: string | null
  punch_out: string | null
  hours: number | null
  in_ip: string | null
  out_ip: string | null
  /** What made the punch: 'app', 'mobile' or 'desktop'. */
  in_device?: string | null
  out_device?: string | null
  /** Metres from the registered office, when the company registered one. */
  in_distance_m?: number | null
  status: string
  status_source: 'auto' | 'punch' | 'manual' | 'holiday' | 'week_off' | 'leave'
  holiday_name: string | null
  leave_category: string | null
  note: string | null
  /** What the day is worth towards pay: 1, 0.5 or 0. */
  day_value: number
}

export interface CrmPunchSummaryRow {
  member_uuid: string
  name: string | null
  login: string | null
  employee_code: string | null
  days: number
  working_days: number
  present: number
  late: number
  half_day: number
  leave: number
  holiday: number
  absent: number
  payable_days: number
  lop_days: number
  has_attendance: boolean
  avg_in: string | null
}

export interface CrmPunchSummary {
  statuses: { status: string; count: number }[]
  members: CrmPunchSummaryRow[]
  range: { from: string; to: string }
  policy: {
    start: string
    grace_minutes: number
    half_day_after_minutes: number
    half_day_hours: number
  }
}

export interface CrmStatutoryRates {
  /** Each side named separately — the law can move one without the other. */
  pf_employer_rate: number
  pf_employee_rate: number
  pf_wage_cap: number
  esi_employer_rate: number
  esi_employee_rate: number
  edli_rate: number
  welfare_employee_rate: number
  welfare_employee_cap: number
  welfare_employer_multiple: number
}

export interface CrmDaySchedule { start: string; end: string }

export interface CrmHrPolicy extends CrmStatutoryRates {
  /** Per-day office hours, keyed by day-of-week ('0' Sunday … '6'). */
  day_schedule?: Record<string, CrmDaySchedule | null>
  /** Every N lates in a month cost half a day's pay; 0 = off. */
  lates_per_half_day?: number
  /** Where the office is; null/empty means the company asks for no location. */
  office_lat?: number | string | null
  office_lng?: number | string | null
  office_radius_m?: number | null
  punch_needs_location?: boolean
  /** Standard run for spread incentive plans; each plan may override. */
  incentive_spread_months: number
  /** No incentive until the client has paid in full; releases itself on payment. */
  incentive_needs_full_payment: boolean
  /** Which facilities a new employee's structure starts inside. */
  pf_default: boolean
  edli_default: boolean
  esi_default: boolean
  welfare_default: boolean
  work_start: string
  work_end: string
  grace_minutes: number
  half_day_after_minutes: number
  half_day_hours: number
  full_day_hours: number
  week_off_days: number[]
  probation_days: number
  monthly_leave_credit: number
  encash_unused_leave: boolean
  financial_year_start_month: number
}

export interface CrmHoliday {
  uuid: string
  holiday_date: string
  day: string
  name: string
  is_optional: boolean
  past: boolean
}

export interface CrmLeaveAccount {
  member_uuid: string
  name: string | null
  employee_code: string | null
  joined_at: string | null
  financial_year: number
  label: string
  earned: number
  taken: number
  encashed: number
  balance: number
  on_probation: boolean
  probation_ends_on: string | null
  accrual_starts_on: string | null
  monthly_credit: number
}

export interface CrmPaymentEntry {
  uuid: string
  received_on: string
  issuing_company: string | null
  issuing_company_id: number | null
  bank_account: string | null
  bank_account_id: number | null
  payment_mode: string | null
  amount: string
  currency: string
  details: string | null
  reference_no: string | null
  /** pending = matched, waiting for an Admin to check it. */
  status: 'unclaimed' | 'pending' | 'claimed'
  settlement_mode: 'auto' | 'manual' | null
  claimed_invoice: { uuid: string; number: string; kind: 'proforma' | 'invoice' } | null
  claimed_member: string | null
  claimed_at: string | null
  settled_at: string | null
  /** The proforma the money came in against, once it became an invoice. */
  from_proforma: string | null
  note: string | null
}

export interface CrmPaymentSummary {
  unclaimed_count: number
  unclaimed_amount: number
  pending_count: number
  pending_amount: number
  settlement_mode: 'auto' | 'manual'
  claimed_amount: number
  total_amount: number
  by_mode: { mode: string; amount: number; count: number }[]
  by_month: { month: string; amount: number }[]
}

export type CrmComplaintStatus = 'unattended' | 'in_progress' | 'closed_satisfied' | 'closed_dissatisfied'
export type CrmComplaintError = 'common' | 'executive' | 'client' | 'backend'

export interface CrmComplaintReply {
  uuid: string
  /** 'client' is what the client is told; 'internal' never leaves the office. */
  audience: 'client' | 'internal'
  body: string
  author: string | null
  author_uuid: string | null
  created_at: string | null
}

export interface CrmComplaint {
  uuid: string
  cms_no: string
  complained_on: string
  client_uuid: string | null
  company_name: string
  contact_person: string | null
  mobile: string | null
  phone: string | null
  email: string | null
  alt_contact_person: string | null
  alt_mobile: string | null
  alt_phone: string | null
  alt_email: string | null
  invoice_uuid: string | null
  invoice_no: string | null
  source: string | null
  subject: string | null
  complaint_type: string | null
  mode: string | null
  details: string | null
  status: CrmComplaintStatus
  status_label: string
  priority: 'low' | 'normal' | 'high' | 'urgent'
  due_at: string | null
  overdue: boolean
  in_progress_at: string | null
  first_response_at: string | null
  closed_at: string | null
  closed_by: string | null
  resolution: string | null
  final_error_type: CrmComplaintError | null
  final_error_label: string | null
  final_error_member: string | null
  final_error_member_uuid: string | null
  final_error_note: string | null
  raised_by: string | null
  raised_by_uuid: string | null
  allocated_by: string | null
  allocated_to: string | null
  allocated_to_uuid: string | null
  key_responsible: string | null
  key_responsible_uuid: string | null
  replies_count: number
  created_at: string | null
  /** Only on the single-complaint payload. */
  replies?: CrmComplaintReply[]
  documents?: { uuid: string; name: string; size: number }[]
}

export interface CrmComplaintSummary {
  count: number
  unattended: number
  in_progress: number
  closed_satisfied: number
  closed_dissatisfied: number
  overdue: number
  avg_first_response_hours: number | null
  avg_resolution_hours: number | null
  by_error_type: { key: CrmComplaintError; label: string; count: number }[]
  /** Whose desk the closed complaints' mistakes trace back to. */
  by_error_member: { name: string; count: number }[]
  by_subject: { subject: string; count: number }[]
}

export interface CrmComplaintOptions {
  sources: string[]
  subjects: string[]
  types: string[]
  modes: string[]
  statuses: Record<CrmComplaintStatus, string>
  error_types: Record<CrmComplaintError, string>
  priorities: Record<string, string>
  resolve_hours: number
  members: { uuid: string; name: string | null; raised: number; allocated: number; is_me: boolean }[]
  can_allocate: boolean
}

export interface CrmComplaintSettings {
  complaint_sources: string[]
  complaint_subjects: string[]
  complaint_types: string[]
  complaint_modes: string[]
  resolve_hours: number
}

export interface CrmVendor {
  uuid: string
  company_name: string
  contact_person: string | null
  designation: string | null
  address: string | null
  city: string | null
  state: string | null
  pincode: string | null
  country: string | null
  telephone: string | null
  mobile: string | null
  email: string | null
  website: string | null
  gst_no: string | null
  pan_no: string | null
  category: string | null
  payment_terms_days: number | null
  bank_name: string | null
  bank_account_no: string | null
  bank_ifsc: string | null
  bank_branch: string | null
  status: 'active' | 'inactive'
  notes: string | null
  /** Read from the bills themselves, never stored on the vendor. */
  bills: number
  billed: number
  paid: number
  outstanding: number
  overdue_bills: number
  created_at: string | null
}

export interface CrmVendorBill {
  uuid: string
  expense_date: string
  due_date: string | null
  description: string | null
  category: string | null
  total_amount: string
  amount_paid: string
  balance: number
  payment_status: CrmExpenseStatus
  overdue: boolean
}

export interface CrmVendorSummary {
  vendors: number
  active: number
  billed: number
  outstanding: number
  overdue_bills: number
}

export interface CrmExpensePayment {
  uuid: string
  paid_on: string
  amount: string
  payment_mode: string | null
  reference_no: string | null
  note: string | null
  created_by: string | null
}

export type CrmExpenseStatus = 'unpaid' | 'part' | 'paid'

export interface CrmExpense {
  uuid: string
  expense_date: string
  due_date: string | null
  issuing_company: string | null
  issuing_company_id: number | null
  vendor_uuid: string | null
  vendor_name: string
  vendor_gstin: string | null
  category: string | null
  description: string | null
  base_amount: string
  cgst_amount: string
  sgst_amount: string
  igst_amount: string
  /** The rate the bill quoted, when it quoted one. */
  cgst_rate: string | null
  sgst_rate: string | null
  igst_rate: string | null
  other_tax_label: string | null
  other_tax_rate: string | null
  other_tax_amount: string
  total_amount: string
  amount_paid: string
  balance: number
  payment_status: CrmExpenseStatus
  overdue: boolean
  payments: CrmExpensePayment[]
  bill_available: boolean
  gst_claimed: boolean
  payment_mode: string | null
  note: string | null
  documents_count: number
  documents: { uuid: string; name: string; size: number }[]
  created_by: string | null
  created_at: string | null
}

export interface CrmExpenseSummary {
  count: number
  total: number
  paid: number
  outstanding: number
  unpaid_bills: number
  overdue: number
  overdue_bills: number
  gst_total: number
  gst_unclaimed: number
  by_category: { category: string; amount: number; count: number }[]
  by_month: { month: string; amount: number }[]
}

export interface CrmPayLine {
  key: string
  label: string
  amount: number
}

export interface CrmSalarySlip {
  uuid: string
  member: { uuid: string; name: string | null; employee_code: string | null } | null
  year: number
  month: number
  monthly_salary: string
  month_days: number | null
  payable_days: string | null
  lop_days: string | null
  /** The slip's whole story, line by line. */
  earnings: CrmPayLine[]
  deduction_lines: CrmPayLine[]
  incentive_amount: string
  incentive_breakdown: CrmIncentiveResult | null
  incentive_month: string | null
  net_without_incentive: string | null
  payable: string
  additions: string
  deductions: string
  deduction_note: string | null
  net_salary: string
  bank_name: string | null
  account_holder: string | null
  account_no: string | null
  ifsc: string | null
  status: 'pending' | 'paid'
  paid_on: string | null
  payment_mode: string | null
  attendance: { days: number; present: number; late: number; half_day: number; holiday: number } | null
}

export interface CrmSalaryResponse {
  data: CrmSalarySlip[]
  totals: {
    payable: number
    additions: number
    deductions: number
    net: number
    paid: number
    pending: number
    incentive: number
    net_without_incentive: number
  }
  year: number
  month: number
  manages: boolean
}

export interface CrmSaleBreakdown {
  total: number
  commission: number
  charges: number
  /** Netted off when the plan pays on base less TDS. */
  tds: number
  effective: number
  invoices: number
}

export interface CrmIncentiveResult {
  incentive_month: string
  plan: string
  plan_note: string | null
  config?: Record<string, unknown>
  self: CrmSaleBreakdown | null
  team: CrmSaleBreakdown | null
  self_incentive: number
  team_incentive: number
  total: number
  /** Spread plans only: the installments this month collects. */
  spread_months?: number
  installments?: {
    invoice_no?: string
    client?: string | null
    sale_month: string
    effective_sale: number
    pool: number
    installment: number
    team_installment: number | null
    number: number
    of: number
    team?: boolean
    seller?: string | null
  }[]
  /** Withheld months paying out this month — the arrear release. */
  arrears?: {
    invoice_no?: string
    client?: string | null
    sale_month: string
    months: number
    /** Negative when a returned sale claws its paid incentive back. */
    amount: number
  }[]
  arrear_total?: number
  recovery_total?: number
}

export type CrmScheduleStatus = 'paid' | 'on_slip' | 'due' | 'upcoming' | 'held' | 'arrear' | 'cancelled' | 'awaiting_payment'

export interface CrmIncentiveLedgerRow {
  invoice_id: number
  invoice_uuid: string
  invoice_no: string
  client: string | null
  /** A teammate's sale paying the leader's team % — labelled by seller. */
  team?: boolean
  seller?: string | null
  /** Remark: when the team access behind this run was withdrawn. The run
   *  itself is the leader's right and finishes its scheduled term. */
  withdrawn_month?: string | null
  sale_month: string
  total: number
  costs: number
  tds: number
  effective: number
  payment_status: string
  /** The run waits for the client's full payment (HR Policy gate). */
  awaiting_payment: boolean
  /** The row's own vintage: the % and run length its sale date carried. */
  percent: number
  months: number
  pool: number
  installment: number
  paid_so_far: number
  schedule: {
    number: number
    earned_month: string
    payroll_month: string
    amount: number
    status: CrmScheduleStatus
    pays_at: string | null
  }[]
  hold: { uuid: string; kind: 'hold' | 'cancel'; from_month: string; note: string | null; by: string | null } | null
}

export interface CrmIncentiveLedger {
  member: { uuid: string; name: string | null }
  plan: string
  release_offset_months: number
  manages: boolean
  next_month: {
    payroll_month: string
    earned_month: string
    total: number
    arrear_total: number
  } | null
  rows: CrmIncentiveLedgerRow[]
  recent: { earned_month: string; total: number }[]
  /** The between-months view, when a span was asked for. */
  months: {
    earned_month: string
    payroll_month: string
    total: number
    arrear_total: number
    recovery_total: number
    installments: number
    status: 'paid' | 'on_slip' | 'due' | 'upcoming'
  }[]
}

export interface CrmSalaryStructureRow {
  uuid: string
  effective_from: string
  ctc_monthly: string
  gross_monthly: number
  basic: string
  hra: string
  components: Record<string, number>
  has_pf: boolean
  has_edli: boolean
  has_esi: boolean
  has_welfare: boolean
  pt_amount: string
  tds_monthly: string
  note: string | null
  created_by: string | null
}

export interface CrmIncentivePlanRow {
  uuid: string
  effective_from: string
  kind: 'none' | 'flat_percent' | 'slab' | 'percent_minus_base' | 'spread'
  kind_label: string
  config: {
    percent?: number
    base_amount?: number
    team_percent?: number
    team_mode?: 'separate' | 'combined'
    slab_mode?: 'whole' | 'marginal'
    slabs?: { upto: number | null; percent: number }[]
    spread_months?: number
  }
  release_offset_months: number
  note: string | null
  created_by: string | null
  /** The day the change was made (effective_from is when it applies). */
  created_at?: string | null
}

export interface CrmLoanRow {
  uuid: string
  kind: 'loan' | 'advance'
  amount: string
  monthly_installment: string
  taken_on: string
  balance: number
  status: 'open' | 'closed'
  note: string | null
  repayments: { amount: string; repaid_on: string; via_payroll: boolean; note: string | null }[]
}

export interface CrmCompensation {
  member: { uuid: string; name: string | null }
  component_labels: Record<string, string>
  statutory: CrmStatutoryRates
  incentive_defaults: { spread_months: number }
  payment_gate: { policy: boolean; override: boolean | null; effective: boolean }
  scheme_defaults: { has_pf: boolean; has_edli: boolean; has_esi: boolean; has_welfare: boolean }
  plan_kinds: Record<string, string>
  structures: CrmSalaryStructureRow[]
  plans: CrmIncentivePlanRow[]
  loans: CrmLoanRow[]
  legacy_salary: string | null
}

export interface CrmLeave {
  uuid: string
  member: { uuid: string; name: string | null } | null
  category: string
  duration: 'full' | 'half'
  date_from: string
  date_to: string
  days: string
  /** Split at approval: what the account covered, and what it did not. */
  paid_days: string
  unpaid_days: string
  reason: string | null
  status: 'pending' | 'approved' | 'rejected' | 'cancelled'
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  created_at: string | null
}

export interface CrmLeaveSummary {
  pending: number
  approved_days: number
  by_category: { category: string; days: number; count: number }[]
  by_status: { status: string; count: number }[]
  /** The reader's own paid-leave account for the current financial year. */
  account: CrmLeaveAccount
}

export interface CrmTask {
  uuid: string
  title: string
  description: string | null
  assignee: { uuid: string; name: string | null } | null
  assigned_by: string | null
  due_at: string | null
  overdue: boolean
  priority: 'low' | 'normal' | 'high' | 'urgent'
  status: 'open' | 'in_progress' | 'submitted' | 'done' | 'reopened'
  progress_note: string | null
  submitted_at: string | null
  reviewed_by: string | null
  review_note: string | null
  created_at: string | null
}

export interface CrmTaskSummary {
  by_status: { status: string; count: number }[]
  overdue: number
  awaiting_review: number
}

export interface CrmApproval {
  uuid: string
  type: string
  /** invoice = about a document or client; general = the office's own money. */
  scope?: 'invoice' | 'general'
  client?: { uuid: string; company_name: string } | null
  approval_date: string
  issuing_company: string | null
  invoice: { uuid: string; number: string } | null
  amount: string
  details: string | null
  requested_by: string | null
  status: 'pending' | 'approved' | 'rejected'
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  created_at: string | null
}

export interface CrmApprovalSummary {
  pending: number
  pending_amount: number
  by_type: { type: string; count: number; amount: number }[]
  by_status: { status: string; count: number }[]
}

/** What a member may point an approval request at. */
export interface CrmApprovalOptions {
  invoices: { uuid: string; number: string; kind: string; total: string; client: string | null }[]
  clients: { uuid: string; company_name: string }[]
  types: string[]
}

export interface CrmApprovalInbox {
  leaves: number | null
  tasks: number | null
  invoice_updates: number | null
  client_access: number | null
}

export interface CrmInvoiceUpdate {
  uuid: string
  invoice: { uuid: string; number: string } | null
  changes: Record<string, string | null>
  reason: string | null
  requested_by: string | null
  status: 'pending' | 'approved' | 'rejected'
  decided_by: string | null
  decision_note: string | null
  created_at: string | null
}

export interface CrmNewsletter {
  uuid: string
  subject: string
  body: string
  audience: 'active_clients' | 'all_clients' | 'leads' | 'custom'
  custom_recipients: string[] | null
  status: 'draft' | 'sent'
  sent_at: string | null
  sent_count: number
  failed_count: number
  created_by: string | null
  created_at: string | null
}

export interface CrmCmsPost {
  uuid: string
  title: string
  body: string
  kind: 'announcement' | 'policy' | 'holiday' | 'news'
  is_pinned: boolean
  status: 'draft' | 'published'
  publish_on: string | null
  expires_on: string | null
  created_by: string | null
  created_at: string | null
}

export interface CrmReportOverview {
  months: number
  scope?: 'mine' | 'team'
  salespeople?: { uuid: string; name: string | null; is_me: boolean }[] | null
  monthly: { month: string; invoiced: number; received: number; expenses: number; payroll: number }[]
  totals: { invoiced: number; received: number; expenses: number; payroll: number; commission?: number }
  invoice_status: { status: string; count: number; amount: number }[]
  lead_funnel: { status: string; count: number }[]
  top_clients: { name: string; amount: number; invoices: number }[]
  top_salespeople: { name: string; amount: number; commission?: number; net?: number }[]
}

export interface CrmUserLogEntry {
  id: number
  action: string
  by: string | null
  at: string
  changes: Record<string, unknown> | null
}

export interface CrmDashboard {
  employees: { total: number; active: number }
  clients: { total: number; active: number }
  invoices: {
    month_count: number
    month_total: number | string
    proforma_open: number
    outstanding: number | string
    received_this_month: number | string
  }
  recent_invoices: { uuid: string; kind: string; number: string; client: string | null; invoice_date: string; total: string; currency: string; payment_status: string }[]
  birthdays: { name: string | null; photo_path?: string | null; avatar?: string | null; gender?: string | null; date: string; in_days: number }[]
  scope?: 'mine' | 'team'
  salespeople?: { uuid: string; name: string | null; is_me: boolean }[] | null
  charts?: {
    leads_by_status: Record<string, number>
    invoices_by_payment: { status: string; count: number; amount: number }[]
  }
  today: string
}

export interface CrmOrganizationRow {
  uuid: string
  name: string
  code: string
  status: string
  members: number
  active_members: number
  admins: { name: string | null; email: string | null }[]
  created_at: string | null
}

interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
}

export interface CrmBadges {
  /** What colleagues did in each section since this member last looked. */
  sections?: Record<string, number>
  leaves: number | null
  tasks: number | null
  approvals: number | null
  invoice_updates: number | null
  client_access: number | null
  total: number
  /** Work sitting on MY desk, keyed per menu entry — the (n) numbers. */
  attend?: Record<string, number>
}

export const crm = {
  me: () => api.get<{ data: CrmMe }>('/crm/me').then((r) => r.data.data),
  badges: () => api.get<{ data: CrmBadges }>('/crm/badges').then((r) => r.data.data),
  markSectionSeen: (section: string) =>
    api.post<{ message: string }>(`/crm/sections/${section}/seen`).then((r) => r.data),
  masters: () => api.get<{ data: CrmMasters }>('/crm/masters').then((r) => r.data.data),
  dashboard: (scope?: 'mine' | 'team', salesperson?: string) =>
    api.get<{ data: CrmDashboard }>('/crm/dashboard', { params: { scope, salesperson: salesperson || undefined } })
      .then((r) => r.data.data),

  employees: {
    list: (params: { search?: string; crm_role?: string; status?: string; reports_to?: string; page?: number }) =>
      api.get<Paginated<CrmEmployee>>('/crm/employees', { params }).then((r) => r.data),
    /** One's own record — documents, letters basis — no employees right needed. */
    myProfile: () =>
      api.get<{ data: CrmEmployeeFull & { letters_allowed: boolean } }>('/crm/my/profile').then((r) => r.data.data),
    downloadMyDocument: (documentUuid: string) =>
      api.get(`/crm/my/documents/${documentUuid}`, { responseType: 'blob' }).then((r) => r.data as Blob),
    get: (uuid: string) => api.get<{ data: CrmEmployeeFull }>(`/crm/employees/${uuid}`).then((r) => r.data.data),
    /**
     * Search Netvork accounts to register one as an employee.
     *
     * A shortlist, not an answer: "priyanshu" matches every Priyanshu, and
     * which one is meant is a question only the person asking can settle.
     */
    lookupAccount: (q: string) =>
      api.get<{ data: CrmAccountMatch[]; truncated: boolean }>(
        '/crm/employees-lookup', { params: { q } },
      ).then((r) => r.data),
    create: (payload: Record<string, unknown>) => api.post(`/crm/employees`, payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) => api.put(`/crm/employees/${uuid}`, payload).then((r) => r.data),
    deactivate: (uuid: string) => api.delete(`/crm/employees/${uuid}`).then((r) => r.data),
    addSalary: (uuid: string, payload: Record<string, unknown>) => api.post(`/crm/employees/${uuid}/salary`, payload).then((r) => r.data),
    deleteSalary: (uuid: string, id: number) => api.delete(`/crm/employees/${uuid}/salary/${id}`).then((r) => r.data),
    uploadDocument: (uuid: string, name: string, file: File) => {
      const form = new FormData()
      form.append('name', name)
      form.append('file', file)
      return api.post(`/crm/employees/${uuid}/documents`, form).then((r) => r.data)
    },
    documentUrl: (uuid: string, documentUuid: string) => `/crm/employees/${uuid}/documents/${documentUuid}`,
    downloadDocument: (uuid: string, documentUuid: string) =>
      api.get(`/crm/employees/${uuid}/documents/${documentUuid}`, { responseType: 'blob' }).then((r) => r.data as Blob),
    deleteDocument: (uuid: string, documentUuid: string) => api.delete(`/crm/employees/${uuid}/documents/${documentUuid}`).then((r) => r.data),
  },

  clients: {
    list: (params: { search?: string; status?: string; page?: number }) =>
      api.get<Paginated<CrmClient>>('/crm/clients', { params }).then((r) => r.data),
    options: (search?: string) =>
      api.get<{ data: Pick<CrmClient, 'uuid' | 'company_name' | 'contact_person' | 'city' | 'gst_no' | 'category' | 'address' | 'state' | 'email'>[] }>('/crm/clients/options', { params: { search } }).then((r) => r.data.data),
    get: (uuid: string) => api.get<{ data: CrmClient }>(`/crm/clients/${uuid}`).then((r) => r.data.data),
    create: (payload: Record<string, unknown>) => api.post('/crm/clients', payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) => api.put(`/crm/clients/${uuid}`, payload).then((r) => r.data),
    remove: (uuid: string) => api.delete(`/crm/clients/${uuid}`).then((r) => r.data),
    transfer: (uuid: string, payload: { to_member_uuid: string; note?: string }) =>
      api.post<{ message: string; data: CrmClient }>(`/crm/clients/${uuid}/transfer`, payload).then((r) => r.data),
    accessRequests: (params: { status?: string; page?: number } = {}) =>
      api.get<Paginated<CrmClientAccessRequest>>('/crm/client-requests', { params }).then((r) => r.data),
    decideAccessRequest: (uuid: string, payload: { status: 'approved' | 'rejected'; note?: string }) =>
      api.post(`/crm/client-requests/${uuid}/decide`, payload).then((r) => r.data),
  },

  leads: {
    list: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmLead> & { totals: { count: number; amount: string } }>('/crm/leads', { params }).then((r) => r.data),
    get: (uuid: string) => api.get<{ data: CrmLead }>(`/crm/leads/${uuid}`).then((r) => r.data.data),
    create: (payload: Record<string, unknown>) =>
      api.post<{ message: string; data: CrmLead }>('/crm/leads', payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) => api.put(`/crm/leads/${uuid}`, payload).then((r) => r.data),
    remove: (uuid: string) => api.delete(`/crm/leads/${uuid}`).then((r) => r.data),
    followUp: (uuid: string, payload: Record<string, unknown>) => api.post(`/crm/leads/${uuid}/followup`, payload).then((r) => r.data),
    /** Urgent rides above every scheduled lead. */
    setUrgent: (uuid: string, urgent: boolean) =>
      api.post<{ message: string }>(`/crm/leads/${uuid}/urgent`, { urgent }).then((r) => r.data),
    /** The Admin's gavel: a settled duplicate opens normally again. */
    settleDuplicate: (uuid: string) =>
      api.post<{ message: string }>(`/crm/leads/${uuid}/settle-duplicate`).then((r) => r.data),
    due: () =>
      api.get<{ data: CrmDueLead[]; alert_minutes: number }>('/crm/leads-due').then((r) => r.data),
    fresh: () =>
      api.get<{ data: CrmNewLead[]; alert_minutes: number }>('/crm/leads-new').then((r) => r.data),
    options: () =>
      api.get<{ data: { lead_sources: string[]; lead_subjects: string[] } }>('/crm/masters/lead-options')
        .then((r) => r.data.data),
    saveOptions: (payload: { lead_sources: string[]; lead_subjects: string[] }) =>
      api.put<{ message: string }>('/crm/masters/lead-options', payload).then((r) => r.data),
    alertSettings: () =>
      api.get<{ data: { alert_minutes: number; new_alert_minutes: number } }>('/crm/masters/lead-settings').then((r) => r.data.data),
    saveAlertSettings: (alertMinutes: number, newAlertMinutes: number) =>
      api.put<{ message: string }>('/crm/masters/lead-settings', { alert_minutes: alertMinutes, new_alert_minutes: newAlertMinutes })
        .then((r) => r.data),
    bulkTransfer: (payload: { lead_uuids: string[]; to_member_uuid: string; note?: string }) =>
      api.post<{ message: string; moved: number }>('/crm/leads/bulk-transfer', payload).then((r) => r.data),
    bulkShare: (payload: { lead_uuids: string[]; member_uuids: string[] }) =>
      api.post<{ message: string; shared: number }>('/crm/leads/bulk-share', payload).then((r) => r.data),
    reopen: (uuid: string, payload: { note: string; follow_up_at?: string | null }) =>
      api.post<{ message: string; data: CrmLead }>(`/crm/leads/${uuid}/reopen`, payload).then((r) => r.data),
    accessRequests: (params: { status?: string; page?: number } = {}) =>
      api.get<Paginated<CrmLeadAccessRequest>>('/crm/lead-requests', { params }).then((r) => r.data),
    decideAccessRequest: (uuid: string, payload: { action: 'share' | 'transfer' | 'reject'; note?: string }) =>
      api.post<{ message: string }>(`/crm/lead-requests/${uuid}/decide`, payload).then((r) => r.data),
    transfer: (uuid: string, payload: { to_member_uuid: string; note?: string }) =>
      api.post<{ message: string; data: CrmLead }>(`/crm/leads/${uuid}/transfer`, payload).then((r) => r.data),
    share: (uuid: string, memberUuids: string[]) =>
      api.post<{ message: string; data: CrmLead }>(`/crm/leads/${uuid}/share`, { member_uuids: memberUuids }).then((r) => r.data),
    convert: (uuid: string) =>
      api.post<{ message: string; data: { client_uuid: string } }>(`/crm/leads/${uuid}/convert`).then((r) => r.data),
    log: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmLeadLogEntry>>('/crm/lead-log', { params }).then((r) => r.data),
  },

  targets: {
    list: (year?: number, month?: number, endYear?: number, endMonth?: number) =>
      api.get<CrmTargetsResponse>('/crm/targets', {
        params: { year, month, end_year: endYear, end_month: endMonth },
      }).then((r) => r.data),
    growth: (params: {
      period: CrmGrowthPeriod
      points?: number
      scope?: 'mine' | 'team'
      salesperson?: string
    }) =>
      api.get<{ data: CrmGrowthResponse }>('/crm/targets/growth', { params })
        .then((r) => r.data.data),
    save: (year: number, month: number, targets: { member_uuid: string; target_amount: number; note?: string | null }[]) =>
      api.post('/crm/targets', { year, month, targets }).then((r) => r.data),
    copyPrevious: (year: number, month: number) =>
      api.post('/crm/targets/copy-previous', { year, month }).then((r) => r.data),
  },

  contests: {
    replicate: (uuid: string) =>
      api.post<{ message: string; data: { uuid: string } }>(`/crm/contests/${uuid}/replicate`).then((r) => r.data),
    list: (page?: number) => api.get<Paginated<CrmContestRow>>('/crm/contests', { params: { page } }).then((r) => r.data),
    get: (uuid: string) => api.get<{ data: CrmContestFull }>(`/crm/contests/${uuid}`).then((r) => r.data.data),
    create: (payload: Record<string, unknown>) =>
      api.post<{ message: string; data: { uuid: string } }>('/crm/contests', payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) => api.put(`/crm/contests/${uuid}`, payload).then((r) => r.data),
    remove: (uuid: string) => api.delete(`/crm/contests/${uuid}`).then((r) => r.data),
    answer: (uuid: string, payload: Record<string, unknown>) => api.post(`/crm/contests/${uuid}/answer`, payload).then((r) => r.data),
    results: (uuid: string) => api.get<{ data: CrmContestResults }>(`/crm/contests/${uuid}/results`).then((r) => r.data.data),
    grade: (uuid: string, answerId: number, isCorrect: boolean) =>
      api.post(`/crm/contests/${uuid}/answers/${answerId}/grade`, { is_correct: isCorrect }).then((r) => r.data),
  },

  dwr: {
    list: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmDwrRow>>('/crm/dwr', { params }).then((r) => r.data),
    get: (uuid: string) => api.get<{ data: CrmDwrRow }>(`/crm/dwr/${uuid}`).then((r) => r.data.data),
    submit: (payload: Record<string, unknown>) =>
      api.post<{ message: string; data: CrmDwrRow }>('/crm/dwr', payload).then((r) => r.data),
    stats: (params: Record<string, string | undefined>) =>
      api.get<{ data: CrmDwrStats }>('/crm/dwr/stats', { params }).then((r) => r.data.data),
    myKpis: () => api.get<{ data: CrmKpiAssignment[] }>('/crm/dwr/my-kpis').then((r) => r.data.data),
    /** Todays figures from the ledgers, offered as editable starting values. */
    prefill: () =>
      api.get<{ data: { parameter_id: number; value: number; basis: string }[] }>('/crm/dwr/prefill')
        .then((r) => r.data.data),
    parameters: () =>
      api.get<{ data: { id: number; name: string; unit: string; is_active: boolean }[] }>('/crm/dwr-parameters').then((r) => r.data.data),
    updateParameter: (id: number, payload: { name?: string; unit?: string; is_active?: boolean }) =>
      api.put<{ data: { id: number; name: string; unit: string; is_active: boolean } }>(`/crm/dwr-parameters/${id}`, payload).then((r) => r.data),
    addParameter: (name: string, unit: string) =>
      api.post<{ data: { id: number; name: string; unit: string; is_active: boolean } }>('/crm/dwr-parameters', { name, unit }).then((r) => r.data),
    assignments: (memberUuid: string) =>
      api.get<{ data: CrmKpiAssignment[] }>(`/crm/dwr-assignments/${memberUuid}`).then((r) => r.data.data),
    saveAssignments: (memberUuid: string, kpis: { parameter_id: number; weightage: number; daily_target: number }[]) =>
      api.put(`/crm/dwr-assignments/${memberUuid}`, { kpis }).then((r) => r.data),
  },

  punch: {
    today: () =>
      api.get<{ data: { today: string; config: { start: string; grace_minutes: number; half_day_hours: number; location: { required: boolean; radius_m: number } | null }; punch: CrmPunchRow | null; punch_waived?: boolean } }>('/crm/punch/today').then((r) => r.data.data),
    /** The place rides along when the company asked for it. */
    punchIn: (where?: { latitude: number; longitude: number }) =>
      api.post<{ message: string; data: CrmPunchRow }>('/crm/punch/in', where ?? {}).then((r) => r.data),
    punchOut: () => api.post<{ message: string; data: CrmPunchRow }>('/crm/punch/out').then((r) => r.data),
    list: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmPunchRow> & { summary: CrmPunchSummary }>('/crm/punch', { params }).then((r) => r.data),
    /**
     * Rule on a day. A day nobody punched has no id, so it is named by the
     * person and the date instead and the row is created on the spot.
     */
    override: (row: CrmPunchRow, status: string, note?: string) =>
      api.put(`/crm/punch/${row.id ?? 0}`, {
        status,
        note,
        member_uuid: row.member?.uuid,
        work_date: row.work_date,
      }).then((r) => r.data),
  },

  hr: {
    policy: () =>
      api.get<{ data: {
        policy: CrmHrPolicy
        defaults: CrmHrPolicy
        financial_year: number
        financial_year_label: string
        can_edit: boolean
        can_manage_holidays: boolean
      } }>('/crm/hr-policy').then((r) => r.data.data),
    savePolicy: (policy: CrmHrPolicy) =>
      api.put<{ message: string }>('/crm/hr-policy', policy).then((r) => r.data),
    holidays: (financialYear?: number) =>
      api.get<{ data: {
        financial_year: number
        label: string
        holidays: CrmHoliday[]
        years: { year: number; count: number }[]
      } }>('/crm/hr-policy/holidays', { params: { financial_year: financialYear } })
        .then((r) => r.data.data),
    saveHolidays: (financialYear: number, holidays: { holiday_date: string; name: string; is_optional?: boolean }[], replace = false) =>
      api.put<{ message: string; saved: number; skipped: string[] }>('/crm/hr-policy/holidays', {
        financial_year: financialYear, holidays, replace,
      }).then((r) => r.data),
    deleteHoliday: (uuid: string) =>
      api.delete<{ message: string }>(`/crm/hr-policy/holidays/${uuid}`).then((r) => r.data),
    leaveAccounts: (financialYear?: number) =>
      api.get<{ data: {
        financial_year: number
        label: string
        members: CrmLeaveAccount[]
        total_balance: number
        can_run_year_end: boolean
      } }>('/crm/hr-policy/leave-accounts', { params: { financial_year: financialYear } })
        .then((r) => r.data.data),
    ledger: (memberUuid: string, financialYear?: number) =>
      api.get<{ data: CrmLeaveAccount & {
        entries: { kind: string; days: number; effective_on: string; amount: number | null; note: string | null }[]
      } }>(`/crm/hr-policy/leave-accounts/${memberUuid}`, { params: { financial_year: financialYear } })
        .then((r) => r.data.data),
    runAccrual: (monthsBack: number) =>
      api.post<{ message: string }>('/crm/hr-policy/accrual', { months_back: monthsBack }).then((r) => r.data),
    runYearEnd: (financialYear: number) =>
      api.post<{ message: string; data: { name: string | null; days: number; amount: number }[] }>(
        '/crm/hr-policy/year-end', { financial_year: financialYear },
      ).then((r) => r.data),
  },

  commissions: {
    list: (params: Record<string, string | number | undefined> = {}) =>
      api.get<Paginated<CrmCommission> & { summary: { count: number; total: number; this_month: number } }>(
        '/crm/commissions', { params },
      ).then((r) => r.data),
    create: (payload: Record<string, unknown>) =>
      api.post<{ message: string; data: CrmCommission }>('/crm/commissions', payload).then((r) => r.data),
    remove: (uuid: string) => api.delete<{ message: string }>(`/crm/commissions/${uuid}`).then((r) => r.data),
  },

  recurring: {
    list: (params: { status?: string; client?: string; page?: number } = {}) =>
      api.get<Paginated<CrmRecurringInvoice> & {
        summary: { active: number; paused: number; due_this_week: number }
      }>('/crm/recurring', { params }).then((r) => r.data),
    decide: (uuid: string, action: 'pause' | 'resume' | 'cancel') =>
      api.post<{ message: string; data: CrmRecurringInvoice }>(`/crm/recurring/${uuid}/decide`, { action })
        .then((r) => r.data),
    run: (uuid: string) =>
      api.post<{ message: string; data: { uuid: string; number: string } }>(`/crm/recurring/${uuid}/run`)
        .then((r) => r.data),
  },

  payments: {
    list: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmPaymentEntry> & { summary: CrmPaymentSummary }>('/crm/payments', { params }).then((r) => r.data),
    settings: () => api.get<{ data: CrmPaymentSettings }>('/crm/masters/payment-settings').then((r) => r.data.data),
    gateway: () => api.get<{ data: CrmGatewaySettings }>('/crm/masters/payment-gateway').then((r) => r.data.data),
    saveGateway: (payload: { mode: string; app_id: string; secret?: string; is_active: boolean }) =>
      api.put<{ message: string }>('/crm/masters/payment-gateway', payload).then((r) => r.data),
    links: (invoiceUuid: string) =>
      api.get<{ data: CrmPaymentLink[]; gateway: { configured: boolean; balance: number } }>(
        `/crm/invoices/${invoiceUuid}/payment-links`,
      ).then((r) => r.data),
    createLink: (invoiceUuid: string, payload: Record<string, unknown> = {}) =>
      api.post<{ message: string; data: CrmPaymentLink }>(`/crm/invoices/${invoiceUuid}/payment-links`, payload)
        .then((r) => r.data),
    saveSettings: (payload: CrmPaymentSettings) =>
      api.put<{ message: string; data: CrmPaymentSettings }>('/crm/masters/payment-settings', payload)
        .then((r) => r.data),
    /**
     * A bank credit is what was LEFT after a gateway's cut, so settling can
     * name that cut — the invoice then squares on the client's gross.
     */
    settle: (uuid: string, charge?: { charge_amount: number; charge_note?: string | null }) =>
      api.post<{ message: string }>(`/crm/payments/${uuid}/settle`, charge ?? {}).then((r) => r.data),
    reclaim: (uuid: string, payload: { invoice_uuid: string; reason?: string }) =>
      api.post<{ message: string }>(`/crm/payments/${uuid}/reclaim`, payload).then((r) => r.data),
    outstanding: (params: Record<string, string | number | undefined> = {}) =>
      api.get<{ data: CrmOutstandingRow[]; summary: CrmOutstandingSummary }>('/crm/payments/outstanding', { params })
        .then((r) => r.data),
    reminders: (invoiceUuid: string) =>
      api.get<{ data: CrmPaymentReminder[]; draft: CrmReminderDraft }>(`/crm/invoices/${invoiceUuid}/reminders`)
        .then((r) => r.data),
    remind: (invoiceUuid: string, payload: Record<string, unknown>) =>
      api.post<{ message: string; data: CrmPaymentReminder }>(`/crm/invoices/${invoiceUuid}/reminders`, payload)
        .then((r) => r.data),

    create: (payload: Record<string, unknown>) => api.post('/crm/payments', payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) => api.put(`/crm/payments/${uuid}`, payload).then((r) => r.data),
    remove: (uuid: string) => api.delete(`/crm/payments/${uuid}`).then((r) => r.data),
    claim: (uuid: string, payload: { invoice_uuid: string; member_uuid?: string | null; mode?: 'auto' | 'manual' }) =>
      api.post<{ message: string }>(`/crm/payments/${uuid}/claim`, payload).then((r) => r.data),
    unclaim: (uuid: string) => api.post<{ message: string }>(`/crm/payments/${uuid}/unclaim`).then((r) => r.data),
  },

  expenses: {
    list: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmExpense> & { summary: CrmExpenseSummary }>('/crm/expenses', { params }).then((r) => r.data),
    create: (payload: Record<string, unknown>) => api.post('/crm/expenses', payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) => api.put(`/crm/expenses/${uuid}`, payload).then((r) => r.data),
    remove: (uuid: string) => api.delete(`/crm/expenses/${uuid}`).then((r) => r.data),
    uploadBill: (uuid: string, file: File) => {
      const form = new FormData()
      form.append('file', file)
      return api.post(`/crm/expenses/${uuid}/bills`, form).then((r) => r.data)
    },
    downloadBill: (uuid: string, documentUuid: string) =>
      api.get(`/crm/expenses/${uuid}/bills/${documentUuid}`, { responseType: 'blob' }).then((r) => r.data as Blob),
    deleteBill: (uuid: string, documentUuid: string) => api.delete(`/crm/expenses/${uuid}/bills/${documentUuid}`).then((r) => r.data),
    /** Money out. Omit the amount to settle the whole balance in one click. */
    pay: (uuid: string, payload: Record<string, unknown>) =>
      api.post<{ message: string; data: CrmExpense }>(`/crm/expenses/${uuid}/payments`, payload).then((r) => r.data),
    unpay: (uuid: string, paymentUuid: string) =>
      api.delete<{ message: string; data: CrmExpense }>(`/crm/expenses/${uuid}/payments/${paymentUuid}`).then((r) => r.data),
  },

  complaints: {
    list: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmComplaint> & { summary: CrmComplaintSummary }>('/crm/complaints', { params })
        .then((r) => r.data),
    options: () =>
      api.get<{ data: CrmComplaintOptions }>('/crm/complaints/options').then((r) => r.data.data),
    /** The popup's feed: open complaints that are mine to answer. */
    due: () =>
      api.get<{ data: CrmDueComplaint[]; alert_minutes: number }>('/crm/complaints-due').then((r) => r.data),
    show: (uuid: string) =>
      api.get<{ data: CrmComplaint }>(`/crm/complaints/${uuid}`).then((r) => r.data.data),
    create: (payload: Record<string, unknown>) =>
      api.post<{ message: string; data: CrmComplaint }>('/crm/complaints', payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) =>
      api.put<{ message: string; data: CrmComplaint }>(`/crm/complaints/${uuid}`, payload).then((r) => r.data),
    remove: (uuid: string) => api.delete(`/crm/complaints/${uuid}`).then((r) => r.data),
    allocate: (uuid: string, payload: Record<string, unknown>) =>
      api.post<{ message: string; data: CrmComplaint }>(`/crm/complaints/${uuid}/allocate`, payload).then((r) => r.data),
    status: (uuid: string, payload: Record<string, unknown>) =>
      api.post<{ message: string; data: CrmComplaint }>(`/crm/complaints/${uuid}/status`, payload).then((r) => r.data),
    /** The company's own words, kept by the Admin or a Subadmin. */
    settings: () =>
      api.get<{ data: CrmComplaintSettings }>('/crm/masters/complaint-options').then((r) => r.data.data),
    saveSettings: (payload: CrmComplaintSettings) =>
      api.put<{ message: string; data: CrmComplaintSettings }>('/crm/masters/complaint-options', payload)
        .then((r) => r.data),
    reply: (uuid: string, audience: 'client' | 'internal', body: string) =>
      api.post<{ message: string; data: CrmComplaint }>(`/crm/complaints/${uuid}/replies`, { audience, body })
        .then((r) => r.data),
    deleteReply: (uuid: string, replyUuid: string) =>
      api.delete<{ message: string; data: CrmComplaint }>(`/crm/complaints/${uuid}/replies/${replyUuid}`)
        .then((r) => r.data),
    uploadFile: (uuid: string, file: File) => {
      const form = new FormData()
      form.append('file', file)
      return api.post(`/crm/complaints/${uuid}/files`, form).then((r) => r.data)
    },
    downloadFile: (uuid: string, documentUuid: string) =>
      api.get(`/crm/complaints/${uuid}/files/${documentUuid}`, { responseType: 'blob' }).then((r) => r.data as Blob),
    deleteFile: (uuid: string, documentUuid: string) =>
      api.delete(`/crm/complaints/${uuid}/files/${documentUuid}`).then((r) => r.data),
  },

  vendors: {
    list: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmVendor> & { summary: CrmVendorSummary; categories: string[] }>('/crm/vendors', { params })
        .then((r) => r.data),
    options: () =>
      api.get<{ data: Pick<CrmVendor, 'uuid' | 'company_name' | 'gst_no' | 'payment_terms_days'>[]; categories: string[] }>('/crm/vendors/options')
        .then((r) => r.data),
    show: (uuid: string) =>
      api.get<{ data: CrmVendor & { recent_bills: CrmVendorBill[] } }>(`/crm/vendors/${uuid}`).then((r) => r.data.data),
    create: (payload: Record<string, unknown>) =>
      api.post<{ message: string; data: CrmVendor }>('/crm/vendors', payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) =>
      api.put<{ message: string; data: CrmVendor }>(`/crm/vendors/${uuid}`, payload).then((r) => r.data),
    remove: (uuid: string) =>
      api.delete<{ message: string; retired: boolean }>(`/crm/vendors/${uuid}`).then((r) => r.data),
  },

  compensation: {
    show: (memberUuid: string) =>
      api.get<{ data: CrmCompensation }>(`/crm/employees/${memberUuid}/compensation`).then((r) => r.data.data),
    addStructure: (memberUuid: string, payload: Record<string, unknown>) =>
      api.post<{ message: string }>(`/crm/employees/${memberUuid}/compensation/structures`, payload).then((r) => r.data),
    removeStructure: (memberUuid: string, uuid: string) =>
      api.delete(`/crm/employees/${memberUuid}/compensation/structures/${uuid}`).then((r) => r.data),
    addPlan: (memberUuid: string, payload: Record<string, unknown>) =>
      api.post<{ message: string }>(`/crm/employees/${memberUuid}/compensation/plans`, payload).then((r) => r.data),
    removePlan: (memberUuid: string, uuid: string) =>
      api.delete(`/crm/employees/${memberUuid}/compensation/plans/${uuid}`).then((r) => r.data),
    setPaymentGate: (memberUuid: string, mode: 'policy' | 'require' | 'release') =>
      api.post<{ message: string }>(`/crm/employees/${memberUuid}/compensation/payment-gate`, { mode })
        .then((r) => r.data),
    preview: (memberUuid: string, month: string) =>
      api.get<{ data: CrmIncentiveResult }>(`/crm/employees/${memberUuid}/compensation/incentive-preview`, { params: { month } })
        .then((r) => r.data.data),
    addLoan: (memberUuid: string, payload: Record<string, unknown>) =>
      api.post<{ message: string }>(`/crm/employees/${memberUuid}/compensation/loans`, payload).then((r) => r.data),
    repayLoan: (memberUuid: string, uuid: string, payload: Record<string, unknown>) =>
      api.post<{ message: string }>(`/crm/employees/${memberUuid}/compensation/loans/${uuid}/repay`, payload).then((r) => r.data),
    closeLoan: (memberUuid: string, uuid: string) =>
      api.post<{ message: string }>(`/crm/employees/${memberUuid}/compensation/loans/${uuid}/close`).then((r) => r.data),
  },

  incentives: {
    ledger: (memberUuid?: string, monthFrom?: string, monthTo?: string) =>
      api.get<{ data: CrmIncentiveLedger }>('/crm/incentives', {
        params: { member: memberUuid, month_from: monthFrom, month_to: monthTo },
      }).then((r) => r.data.data),
    hold: (payload: { member_uuid: string; invoice_uuid: string; scope: 'once' | 'remaining' | 'cancel'; month: string; recover?: boolean; note?: string | null }) =>
      api.post<{ message: string }>('/crm/incentives/hold', payload).then((r) => r.data),
    release: (uuid: string, month?: string) =>
      api.post<{ message: string }>(`/crm/incentives/holds/${uuid}/release`, { month }).then((r) => r.data),
    /** The emergency brake: one ruling over every run at once. */
    holdAll: (payload: { member_uuid: string; scope: 'remaining' | 'cancel'; month: string; recover?: boolean; note?: string | null }) =>
      api.post<{ message: string }>('/crm/incentives/hold-all', payload).then((r) => r.data),
  },

  salary: {
    list: (params: Record<string, string | number | undefined>) =>
      api.get<CrmSalaryResponse>('/crm/salary', { params }).then((r) => r.data),
    generate: (year: number, month: number, refreshPending = false) =>
      api.post<{ message: string }>('/crm/salary/generate', { year, month, refresh_pending: refreshPending })
        .then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) =>
      api.put<{ message: string; data: CrmSalarySlip }>(`/crm/salary/${uuid}`, payload).then((r) => r.data),
    /** The payslip as a PDF, earnings to net. */
    pdf: (uuid: string) =>
      api.get(`/crm/salary/${uuid}/pdf`, { responseType: 'blob' }).then((r) => r.data as Blob),
    /** Recompute one pending slip from the calendar as it stands now. */
    recalculate: (uuid: string) =>
      api.post<{ message: string; data: CrmSalarySlip }>(`/crm/salary/${uuid}/recalculate`).then((r) => r.data),
    /** The payout run: mark every selected pending slip paid in one act. */
    markPaid: (uuids: string[], paidOn?: string) =>
      api.post<{ message: string }>('/crm/salary/mark-paid', { uuids, paid_on: paidOn }).then((r) => r.data),
    remove: (uuid: string) => api.delete(`/crm/salary/${uuid}`).then((r) => r.data),
  },

  leaves: {
    list: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmLeave> & { summary: CrmLeaveSummary }>('/crm/leaves', { params }).then((r) => r.data),
    create: (payload: Record<string, unknown>) => api.post<{ message: string }>('/crm/leaves', payload).then((r) => r.data),
    decide: (uuid: string, status: 'approved' | 'rejected', note?: string) =>
      api.post(`/crm/leaves/${uuid}/decide`, { status, note: note || null }).then((r) => r.data),
    cancel: (uuid: string) => api.delete(`/crm/leaves/${uuid}`).then((r) => r.data),
  },

  tasks: {
    list: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmTask> & { summary: CrmTaskSummary }>('/crm/tasks', { params }).then((r) => r.data),
    create: (payload: Record<string, unknown>) => api.post('/crm/tasks', payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) => api.put(`/crm/tasks/${uuid}`, payload).then((r) => r.data),
    progress: (uuid: string, status: 'in_progress' | 'submitted', note?: string) =>
      api.post<{ message: string }>(`/crm/tasks/${uuid}/progress`, { status, note: note || null }).then((r) => r.data),
    review: (uuid: string, verdict: 'approve' | 'reject', note?: string) =>
      api.post<{ message: string }>(`/crm/tasks/${uuid}/review`, { verdict, note: note || null }).then((r) => r.data),
    remove: (uuid: string) => api.delete(`/crm/tasks/${uuid}`).then((r) => r.data),
  },

  approvals: {
    options: (search?: string) =>
      api.get<{ data: CrmApprovalOptions }>('/crm/approvals/options', { params: { search } })
        .then((r) => r.data.data),
    types: () =>
      api.get<{ data: { approval_types: string[] } }>('/crm/masters/approval-types').then((r) => r.data.data),
    saveTypes: (types: string[]) =>
      api.put<{ message: string }>('/crm/masters/approval-types', { approval_types: types }).then((r) => r.data),
    list: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmApproval> & { summary: CrmApprovalSummary; inbox: CrmApprovalInbox }>('/crm/approvals', { params }).then((r) => r.data),
    create: (payload: Record<string, unknown>) => api.post('/crm/approvals', payload).then((r) => r.data),
    decide: (uuid: string, status: 'approved' | 'rejected', note?: string) =>
      api.post(`/crm/approvals/${uuid}/decide`, { status, note: note || null }).then((r) => r.data),
    invoiceUpdates: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmInvoiceUpdate>>('/crm/invoice-updates', { params }).then((r) => r.data),
    requestInvoiceUpdate: (invoiceUuid: string, changes: Record<string, string | null>, reason?: string) =>
      api.post<{ message: string }>(`/crm/invoices/${invoiceUuid}/update-request`, { changes, reason: reason || null }).then((r) => r.data),
    decideInvoiceUpdate: (uuid: string, status: 'approved' | 'rejected', note?: string) =>
      api.post<{ message: string }>(`/crm/invoice-updates/${uuid}/decide`, { status, note: note || null }).then((r) => r.data),
  },

  newsletters: {
    list: (page?: number) =>
      api.get<Paginated<CrmNewsletter> & { audiences: Record<string, number> }>('/crm/newsletters', { params: { page } }).then((r) => r.data),
    create: (payload: Record<string, unknown>) => api.post('/crm/newsletters', payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) => api.put(`/crm/newsletters/${uuid}`, payload).then((r) => r.data),
    remove: (uuid: string) => api.delete(`/crm/newsletters/${uuid}`).then((r) => r.data),
    send: (uuid: string) => api.post<{ message: string }>(`/crm/newsletters/${uuid}/send`).then((r) => r.data),
  },

  cms: {
    list: (page?: number, kind?: string) =>
      api.get<Paginated<CrmCmsPost> & { manages: boolean }>('/crm/cms', { params: { page, kind } }).then((r) => r.data),
    create: (payload: Record<string, unknown>) => api.post('/crm/cms', payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) => api.put(`/crm/cms/${uuid}`, payload).then((r) => r.data),
    remove: (uuid: string) => api.delete(`/crm/cms/${uuid}`).then((r) => r.data),
  },

  workspaceFields: {
    list: () =>
      api.get<{
        data: CrmCustomField[]
        entities: string[]
        entity_labels: Record<string, string>
        types: string[]
        work_order_method: CrmWorkOrderColumn[]
        invoice_method: CrmWorkOrderColumn[]
        tax_setup: CrmTaxLine[]
        builtins: Record<string, Record<string, CrmWorkOrderBuiltin>>
        tax_kinds: string[]
        tax_bases: string[]
      }>('/crm/workspace-fields').then((r) => r.data),
    request: (payload: Record<string, unknown>) =>
      api.post<{ message: string }>('/crm/workspace-fields', payload).then((r) => r.data),
    remove: (uuid: string) => api.delete('/crm/workspace-fields/' + uuid).then((r) => r.data),
  },

  fieldRequests: {
    list: (params: { status?: string; organization?: string; entity?: string }) =>
      api.get<Paginated<CrmCustomField> & {
        pending_count: number
        organizations: { uuid: string; name: string }[]
      }>('/admin/crm/field-requests', { params }).then((r) => r.data),
    decide: (uuid: string, status: 'approved' | 'rejected', note?: string) =>
      api.post<{ message: string }>(`/admin/crm/field-requests/${uuid}/decide`, { status, note: note || null }).then((r) => r.data),
  },

  reports: {
    overview: (months?: number, scope?: 'mine' | 'team', salesperson?: string, range?: { from: string; to: string }) =>
      api.get<{ data: CrmReportOverview }>('/crm/reports/overview', {
        params: {
          months, scope, salesperson: salesperson || undefined,
          date_from: range?.from || undefined, date_to: range?.to || undefined,
        },
      }).then((r) => r.data.data),
    userLog: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmUserLogEntry> & { daily: { date: string; count: number }[] }>('/crm/user-log', { params }).then((r) => r.data),
  },

  invoices: {
    list: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmInvoiceRow> & {
        totals: {
          count: number
          total: string
          /** What of it is still owed. */
          due: number
          scope: 'mine' | 'team'
          /** Present in the combined view: whose money is whose. */
          by_salesperson?: { uuid: string | null; name: string; is_me: boolean; count: number; total: number; due: number }[]
          /** The consolidated figures for exactly what the filters selected. */
          consolidated?: { basic: number; cgst: number; sgst: number; igst: number; gst_total: number
            other_tax: number; tds: number; total: number; received: number; charges: number; due: number }
        }
      }>('/crm/invoices', { params }).then((r) => r.data),
    get: (uuid: string) => api.get<{ data: CrmInvoiceFull }>(`/crm/invoices/${uuid}`).then((r) => r.data.data),
    create: (payload: Record<string, unknown>) =>
      api.post<{ message: string; data: CrmInvoiceRow }>('/crm/invoices', payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) => api.put(`/crm/invoices/${uuid}`, payload).then((r) => r.data),
    cancel: (uuid: string) => api.post(`/crm/invoices/${uuid}/cancel`).then((r) => r.data),
    convert: (uuid: string) =>
      api.post<{ message: string; data: { uuid: string; number: string } }>(`/crm/invoices/${uuid}/convert`).then((r) => r.data),
    log: (params: Record<string, string | number | undefined>) =>
      api.get<Paginated<CrmInvoiceLogEntry> & { summary: CrmInvoiceLogSummary; kind: string }>(
        '/crm/invoice-log', { params },
      ).then((r) => r.data),
    notes: (uuid: string) =>
      api.get<{ data: CrmInvoiceNote[] }>(`/crm/invoices/${uuid}/notes`).then((r) => r.data.data),
    addNote: (uuid: string, body: string) =>
      api.post<{ message: string; data: CrmInvoiceNote }>(`/crm/invoices/${uuid}/notes`, { body }).then((r) => r.data),
    deleteNote: (uuid: string, noteUuid: string) =>
      api.delete<{ message: string }>(`/crm/invoices/${uuid}/notes/${noteUuid}`).then((r) => r.data),
    makeRecurring: (uuid: string, payload: Record<string, unknown>) =>
      api.post<{ message: string; data: CrmRecurringInvoice }>(`/crm/invoices/${uuid}/recurring`, payload)
        .then((r) => r.data),
    /** The document as a file — print dialogs are not available everywhere. */
    pdf: (uuid: string) =>
      api.get(`/crm/invoices/${uuid}/pdf`, { responseType: 'blob' }).then((r) => r.data as Blob),
    addPayment: (uuid: string, payload: Record<string, unknown>) => api.post(`/crm/invoices/${uuid}/payments`, payload).then((r) => r.data),
    deletePayment: (uuid: string, id: number) => api.delete(`/crm/invoices/${uuid}/payments/${id}`).then((r) => r.data),
    /** Name what collecting cost, after the fact — settlement reports lag. */
    setPaymentCharge: (uuid: string, id: number, payload: Record<string, unknown>) =>
      api.put<{ message: string }>(`/crm/invoices/${uuid}/payments/${id}/charge`, payload).then((r) => r.data),
    /** Send the document to the client, PDF attached — sender chosen in Communication setup. */
    email: (uuid: string, payload: { to?: string; cc?: string[]; from?: 'default' | 'invoice' | 'dues'; message?: string }) =>
      api.post<{ message: string }>(`/crm/invoices/${uuid}/email`, payload).then((r) => r.data),
  },

  /** Accounting exports — Admin + the Subadmin named with exports.excel. */
  exports: {
    invoicesCsv: (params: Record<string, string | undefined>) =>
      api.get('/crm/exports/invoices', { params, responseType: 'blob' }).then((r) => r.data as Blob),
    paymentsCsv: (params: Record<string, string | undefined>) =>
      api.get('/crm/exports/payments', { params, responseType: 'blob' }).then((r) => r.data as Blob),
  },

  /** The Office Assets register. */
  assets: {
    list: (params: Record<string, string | undefined>) =>
      api.get<{ data: CrmAsset[]; summary: { total: number; in_stock: number; allocated: number; damaged: number }
        categories: string[]; manages: boolean; can_delete: boolean }>('/crm/assets', { params }).then((r) => r.data),
    mine: () => api.get<{ data: CrmAsset[] }>('/crm/assets/mine').then((r) => r.data.data),
    forMember: (memberUuid: string) =>
      api.get<{ data: CrmAsset[] }>(`/crm/assets/member/${memberUuid}`).then((r) => r.data.data),
    create: (payload: Record<string, unknown>) =>
      api.post<{ message: string }>('/crm/assets', payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) =>
      api.put<{ message: string }>(`/crm/assets/${uuid}`, payload).then((r) => r.data),
    allocate: (uuid: string, memberUuid: string, note?: string) =>
      api.post<{ message: string }>(`/crm/assets/${uuid}/allocate`, { member_uuid: memberUuid, note }).then((r) => r.data),
    returnAsset: (uuid: string, damaged: boolean, note?: string) =>
      api.post<{ message: string }>(`/crm/assets/${uuid}/return`, { damaged, note }).then((r) => r.data),
    repaired: (uuid: string) => api.post<{ message: string }>(`/crm/assets/${uuid}/repaired`).then((r) => r.data),
    remove: (uuid: string) => api.delete<{ message: string }>(`/crm/assets/${uuid}`).then((r) => r.data),
    history: (uuid: string) =>
      api.get<{ data: { action: string; member: string | null; note: string | null; by: string | null; at: string | null }[] }>(
        `/crm/assets/${uuid}/history`,
      ).then((r) => r.data.data),
  },

  /** The monthly P&L — the Admin's page alone. */
  pl: {
    statement: (monthFrom: string, monthTo: string) =>
      api.get<{ data: CrmPlStatement }>('/crm/pl', { params: { month_from: monthFrom, month_to: monthTo } })
        .then((r) => r.data.data),
    config: () =>
      api.get<{ data: { config: CrmPlConfig; companies: { id: number; name: string; currency: string }[]; categories: string[] } }>(
        '/crm/pl/config',
      ).then((r) => r.data.data),
    saveConfig: (payload: CrmPlConfig) =>
      api.put<{ message: string }>('/crm/pl/config', payload).then((r) => r.data),
    addLine: (payload: { month: string; side: 'income' | 'expense'; label: string; amount: number }) =>
      api.post<{ message: string }>('/crm/pl/lines', payload).then((r) => r.data),
    deleteLine: (id: number) => api.delete<{ message: string }>(`/crm/pl/lines/${id}`).then((r) => r.data),
  },

  /** Churn, the industry way — active, new, repeat, churned, not-renewed. */
  churn: (months = 12, member?: string) =>
    api.get<{ data: CrmChurnReport }>('/crm/churn', { params: { months, member } }).then((r) => r.data.data),

  masterData: {
    /** Try a company's mailbox: sign in, send, read, and check its DNS. */
    testMailbox: (payload: {
      check: 'smtp' | 'imap' | 'dns' | 'send'
      company_id: number
      sender?: Record<string, unknown>
      to?: string
    }) =>
      api.post<{ data: {
        ok: boolean; message: string; score?: number; domain?: string
        checks?: { key: string; pass: boolean; detail: string }[]
      } }>('/crm/masters/communication/test', payload).then((r) => r.data.data),
    /** The Office Assets category list — the company's own words. */
    assetCategories: () =>
      api.get<{ data: { categories: string[] } }>('/crm/masters/asset-categories').then((r) => r.data.data.categories),
    saveAssetCategories: (categories: string[]) =>
      api.put<{ message: string; data: { categories: string[] } }>('/crm/masters/asset-categories', { categories })
        .then((r) => r.data),
    saveCompany: (payload: Record<string, unknown>, id?: number) =>
      id ? api.put(`/crm/masters/issuing-companies/${id}`, payload).then((r) => r.data)
        : api.post('/crm/masters/issuing-companies', payload).then((r) => r.data),
    saveBank: (payload: Record<string, unknown>, id?: number) =>
      id ? api.put(`/crm/masters/bank-accounts/${id}`, payload).then((r) => r.data)
        : api.post('/crm/masters/bank-accounts', payload).then((r) => r.data),
    uploadCompanyLogo: (id: number, file: File) => {
      const form = new FormData()
      form.append('file', file)
      return api.post<{ message: string }>(`/crm/masters/issuing-companies/${id}/logo`, form).then((r) => r.data)
    },
    fxRate: (currency: string) =>
      api.get<{ data: { currency: string; market_rate: number | null; margin_inr: number; effective_rate: number | null } }>(
        '/crm/masters/fx-rate', { params: { currency } },
      ).then((r) => r.data.data),
    saveFxMargin: (marginInr: number) =>
      api.put<{ message: string }>('/crm/masters/fx-settings', { margin_inr: marginInr }).then((r) => r.data),
    birthdaySettings: () =>
      api.get<{ data: { enabled: boolean; song_url: string | null } }>('/crm/masters/birthday-settings').then((r) => r.data.data),
    saveBirthdaySettings: (payload: { enabled: boolean; song_url: string | null }) =>
      api.put<{ message: string }>('/crm/masters/birthday-settings', payload).then((r) => r.data),
    communication: () =>
      api.get<{ data: CrmCommunicationSettings }>('/crm/masters/communication').then((r) => r.data.data),
    saveCommunication: (payload: CrmCommunicationSettings) =>
      api.put<{ message: string }>('/crm/masters/communication', payload).then((r) => r.data),
    uploadCelebrationSong: (file: File) => {
      const form = new FormData()
      form.append('file', file)
      return api.post<{ message: string; data: { url: string } }>('/crm/masters/celebration-song', form).then((r) => r.data)
    },
    festivalSettings: () =>
      api.get<{ data: { holidays: { name: string; date: string; config: { enabled: boolean; color: string; song_url: string | null } }[] } }>(
        '/crm/masters/festival-settings',
      ).then((r) => r.data.data),
    saveFestivalSettings: (festivals: Record<string, { enabled: boolean; color: string; song_url: string | null }>) =>
      api.put<{ message: string }>('/crm/masters/festival-settings', { festivals }).then((r) => r.data),
  },

  organizations: {
    list: () => api.get<{ data: CrmOrganizationRow[] }>('/admin/crm/organizations').then((r) => r.data.data),
    /** Switch the addon off for good — suspended first, code typed back. */
    remove: (uuid: string, confirm: string) =>
      api.delete<{ message: string }>(`/admin/crm/organizations/${uuid}`, { data: { confirm } }).then((r) => r.data),
    create: (payload: Record<string, unknown>) => api.post('/admin/crm/organizations', payload).then((r) => r.data),
    update: (uuid: string, payload: Record<string, unknown>) => api.put(`/admin/crm/organizations/${uuid}`, payload).then((r) => r.data),
    enter: (uuid: string) =>
      api.post<{ message: string; data: { organization_uuid: string } }>(`/admin/crm/organizations/${uuid}/enter`).then((r) => r.data),
    members: (uuid: string) =>
      api.get<{ data: { organization: { name: string; code: string }; members: {
        name: string | null; email: string | null; employee_code: string | null; crm_role: string
        department: string | null; designation: string | null; reports_to: string | null
        status: string; joined_at: string | null
      }[] } }>(`/admin/crm/organizations/${uuid}/members`).then((r) => r.data.data),
  },
}

export interface CrmDueComplaint {
  uuid: string
  cms_no: string
  company_name: string | null
  subject: string | null
  priority: string
  status: string
  due_at: string | null
  overdue: boolean
  allocated_to: string | null
}

export interface CrmAsset {
  uuid: string
  category: string
  name: string
  model_no: string | null
  color: string | null
  serial_no: string | null
  details: string | null
  status: 'in_stock' | 'allocated' | 'damaged'
  holder: { uuid: string; name: string | null } | null
  allocated_at: string | null
  purchased_on: string | null
  note: string | null
}

export interface CrmPlConfig {
  income_company_ids: number[] | null
  expense_categories: string[] | null
  include_salaries: boolean
  include_proformas?: boolean
}

export interface CrmPlLine { id?: number; label: string; amount: number; source: string }
export interface CrmPlMonth {
  month: string
  income: CrmPlLine[]
  expenses: CrmPlLine[]
  income_total: number
  expense_total: number
  profit: number
}
export interface CrmPlStatement {
  months: CrmPlMonth[]
  config: CrmPlConfig
  totals: { income: number; expense: number; profit: number }
}

export interface CrmChurnReport {
  months: {
    month: string
    active: number
    new_customers: number
    repeat_customers: number
    churned: number
    churned_names: string[]
    churn_rate: number
    retention_rate: number
  }[]
  not_renewed: { client: string; covered_to: string; last_invoice: string; lifetime_revenue: number }[]
  summary: { active: number; avg_churn_rate: number; avg_retention_rate: number }
}

export interface CrmCompanySender {
  label?: string | null
  /** The one mailbox the company's own mail goes out from. */
  is_report_sender?: boolean | null
  from_name?: string | null
  from_address?: string | null
  mailer?: 'none' | 'smtp' | 'ses'
  smtp_host?: string | null
  smtp_port?: number | null
  smtp_encryption?: 'tls' | 'ssl' | 'none' | null
  smtp_username?: string | null
  smtp_password?: string | null
  ses_key?: string | null
  ses_secret?: string | null
  ses_region?: string | null
  imap_host?: string | null
  imap_port?: number | null
  imap_encryption?: 'ssl' | 'tls' | 'none' | null
  imap_username?: string | null
  imap_password?: string | null
  imap_allow_self_signed?: boolean | null
}

export interface CrmCommunicationSettings {
  from_name?: string | null
  from_address?: string | null
  invoice_from_address?: string | null
  dues_from_address?: string | null
  email_enabled?: boolean
  whatsapp_enabled?: boolean
  telegram_enabled?: boolean
  netvork_enabled?: boolean
  /** One sender + mailbox per issuing company, keyed by the company id. */
  company_senders?: Record<string, CrmCompanySender>
}

/**
 * May this member perform one of the delicate acts — deleting a client,
 * moving leads, settling money? The job carries them all; anyone else holds
 * what the Admin granted by name. The server checks the same list.
 */
export function crmAllows(me: CrmMe | undefined, capability: string): boolean {
  return (me?.member?.capabilities ?? []).includes(capability)
}

export function crmCan(me: CrmMe | undefined, module: string, ability = 'view'): boolean {
  if (!me?.member) return false
  if (me.member.crm_role === 'admin') return true
  const abilities = me.member.rights?.[module] ?? []
  if (ability === 'view' && abilities.length > 0) return true
  return abilities.includes(ability)
}

export const CRM_MODULE_LABELS: Record<string, string> = {
  dashboard: 'Dashboard',
  employees: 'Employees',
  clients: 'Clients',
  leads: 'Leads & lead log',
  targets: 'Targets',
  contests: 'Contests',
  dwr: 'DWR (team view)',
  punch: 'Punch report (team view)',
  expenses: 'Expenses',
  salary: 'Salary (manage)',
  tasks: 'Tasks (manage)',
  leaves: 'Leave approvals',
  approvals: 'Approvals',
  newsletters: 'Newsletters',
  cms: 'Notice board',
  complaints: 'Complaints (CMS)',
  proforma: 'Proforma invoices',
  invoices: 'Invoices',
  payments: 'Payments',
  masters: 'Billing masters',
  reports: 'Reports',
  settings: 'Settings',
}

export const CRM_CLIENT_CATEGORY_LABELS: Record<string, string> = {
  new: 'New',
  existing: 'Existing',
  global_new: 'Global - New',
  global_existing: 'Global - Existing',
  sez_new: 'SEZ - New',
  sez_existing: 'SEZ - Existing',
}

export const CRM_LEAD_STATUS_LABELS: Record<string, string> = {
  unattended: 'Unattended',
  follow_up: 'Follow up',
  not_interested: 'Not interested',
  closed: 'Closed',
  transferred: 'Transferred',
}

export const CRM_DWR_BAND_LABELS: Record<string, string> = {
  outstanding: 'Outstanding',
  good: 'Good',
  needs_improvement: 'Needs improvement',
  pip: 'PIP',
}

export const CRM_PUNCH_STATUS_LABELS: Record<string, string> = {
  present: 'Present',
  late: 'Late',
  half_day: 'Half day',
  // Approved leave is its own answer now — it is no longer lumped in with
  // holidays, and it is never shown as absence.
  leave: 'Leave',
  week_off: 'Week off',
  sunday: 'Sunday',
  holiday: 'Holiday',
  absent: 'Absent',
}

export const CRM_PAYMENT_STATUS_LABELS: Record<string, string> = {
  due: 'Due',
  partial: 'Partial paid',
  paid: 'Fully paid',
  refunded: 'Refunded',
  credit_note: 'GST credit note',
  bad_debt: 'Bad debt',
}

export const CRM_DISPATCH_STATUS_LABELS: Record<string, string> = {
  pending: 'Due',
  partial: 'Partial dispatched',
  dispatched: 'Dispatched',
  in_process: 'In process',
}
