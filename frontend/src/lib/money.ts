/**
 * An amount, in the currency it is actually in.
 *
 * The invoice screens wrote "₹" in front of every figure, so a dollar
 * document read as rupees on every screen but the PDF — the one place that
 * already printed its own currency. This is the one formatter they share.
 *
 * Rupees keep Indian grouping (₹1,00,000.00), which is how an Indian office
 * reads a rupee figure. Every other currency groups in thousands
 * ($100,000.00), which is how the client abroad receiving it reads theirs.
 *
 * An unknown or missing code reads as rupees rather than throwing: a figure
 * with the wrong symbol is a thing somebody notices, and a screen that fails
 * to draw is a thing nobody can work around.
 */
export function money(
  value: number | string | null | undefined,
  currency?: string | null,
  { decimals = 2 }: { decimals?: number } = {},
): string {
  const amount = Number(value ?? 0)
  const safe = Number.isFinite(amount) ? amount : 0

  const code = (currency ?? '').toUpperCase()
  const known = /^[A-Z]{3}$/.test(code) ? code : 'INR'

  try {
    return new Intl.NumberFormat(known === 'INR' ? 'en-IN' : 'en-US', {
      style: 'currency',
      currency: known,
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    }).format(safe)
  } catch {
    // A code shaped like a currency that Intl does not know.
    return new Intl.NumberFormat('en-IN', {
      style: 'currency',
      currency: 'INR',
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    }).format(safe)
  }
}
