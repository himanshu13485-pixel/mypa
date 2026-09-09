/**
 * Which GST lines a document may carry.
 *
 * A sale within one state carries CGST and SGST; a sale across a state line
 * carries IGST. It is one or the other, never both and never a choice — and
 * the state each side is in is the first two digits of its GST number, so
 * the issuing company's state code and the client's GSTIN settle it between
 * them.
 *
 * Any other line — a company's own, or the Other tax line beside ours — is
 * untouched by this. Only the three that answer the same question are.
 *
 * Where either side is unknown, nothing is ruled out: a client with no GSTIN
 * is unregistered, and a company whose state code was never filled in has
 * not said where it is. Greying out a line there would take away whichever
 * one the accountant actually needed.
 *
 * This is the form's copy, so that the boxes can grey as they are filled in.
 * The server holds the same rule (App\Support\Gst) and it is the one that
 * decides what a document is saved with — a rate typed into a box that
 * should have been grey is dropped there, not charged.
 */
export function unavailableTaxes(
  companyStateCode: string | null | undefined,
  clientGstin: string | null | undefined,
): string[] {
  const mine = stateCode(companyStateCode)
  const theirs = stateCode(clientGstin)

  if (mine === null || theirs === null) return []

  return mine === theirs ? ['igst'] : ['cgst', 'sgst']
}

/**
 * The two-digit state code at the front of a GST number — or of a state code
 * given on its own, which is the same two digits.
 *
 * "6" typed for Haryana is the same state as "06", and a GSTIN pasted with
 * spaces or in lower case is the same GSTIN. Anything that is not a state
 * reads as "not said" rather than as a state of its own.
 */
export function stateCode(value: string | null | undefined): string | null {
  const digits = String(value ?? '').replace(/\D/g, '')
  if (digits === '') return null

  const code = digits.slice(0, 2).padStart(2, '0')

  return code === '00' || Number(code) > 38 ? null : code
}
