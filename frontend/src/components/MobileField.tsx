import { COUNTRIES, DEFAULT_DIAL, flagOf } from '../lib/countries'
import { Input, Label, Select } from './ui'

/**
 * A phone number, the way phone numbers actually are: a country and then a
 * number. One free-text box asked people to remember whether this particular
 * form wanted the code, the plus, the leading zero — and half of them
 * guessed, which is how a records field ends up holding four formats.
 *
 * The code is picked, the digits are typed, and what leaves here is always
 * the same shape. Every dialling code in the world is on the list, each
 * behind its own flag, so nobody has to ask whether their country is
 * "supported".
 */
export function MobileField({
  countryCode,
  number,
  onCountryCode,
  onNumber,
  label = 'Mobile',
  hint,
  placeholder = '9876543210',
  disabled,
  required,
}: {
  /** With the plus, as it is stored: "+91". */
  countryCode: string
  /** The national part, digits only. */
  number: string
  onCountryCode: (code: string) => void
  onNumber: (national: string) => void
  label?: string
  hint?: string
  placeholder?: string
  disabled?: boolean
  required?: boolean
}) {
  return (
    <div>
      <Label>{label}</Label>
      <div className="flex gap-2">
        {/* Wide enough to read the country, because the field is given a row
            of its own. Squeezed beside another control it showed "Indi a"
            and left the digits box too narrow to see a number in. */}
        <Select
          aria-label="Country dialling code"
          className="w-36 shrink-0 sm:w-48"
          value={countryCode}
          disabled={disabled}
          onChange={(e) => onCountryCode(e.target.value)}
        >
          {COUNTRIES.map((c) => (
            <option key={c.iso} value={`+${c.dial}`}>
              {flagOf(c.iso)} +{c.dial} · {c.name}
            </option>
          ))}
        </Select>
        <Input
          type="tel"
          inputMode="numeric"
          autoComplete="tel-national"
          className="w-full"
          value={number}
          disabled={disabled}
          required={required}
          placeholder={placeholder}
          // Digits only: whatever is pasted in — spaces, dashes, a country
          // code the person typed again out of habit — is reduced to the
          // national number the code in front already answers for.
          onChange={(e) => onNumber(e.target.value.replace(/\D/g, '').slice(0, 14))}
        />
      </div>
      {hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
    </div>
  )
}

export { DEFAULT_DIAL }
