/**
 * Regenerates src/lib/countries.ts.
 *
 * Dialling codes change — countries split, territories are assigned their
 * own code — and when that happens the fix is to run this again rather than
 * to edit 245 lines by hand:
 *
 *   npm install --no-save libphonenumber-js
 *   node scripts/generate-countries.cjs
 *
 * libphonenumber-js is deliberately NOT a dependency of the app: the data is
 * baked into the generated file, so nothing extra ships to the browser.
 */
const fs = require('fs')
const path = require('path')
const { getCountries, getCountryCallingCode } = require('libphonenumber-js')

const names = new Intl.DisplayNames(['en'], { type: 'region' })
const rows = getCountries()
  .map((iso) => ({ iso, dial: getCountryCallingCode(iso), name: names.of(iso) || iso }))
  .sort((a, b) => a.name.localeCompare(b.name))

const out = path.join(__dirname, '..', 'src', 'lib', 'countries.ts')
const existing = fs.readFileSync(out, 'utf8')
const head = existing.slice(0, existing.indexOf('export const COUNTRIES: Country[] = [') + 'export const COUNTRIES: Country[] = ['.length)
const tail = existing.slice(existing.indexOf('\n]\n'))

const body = rows
  .map((r) => `  { iso: '${r.iso}', name: ${JSON.stringify(r.name)}, dial: '${r.dial}' },`)
  .join('\n')

fs.writeFileSync(out, `${head}\n${body}${tail}`, 'utf8')
console.log(`wrote ${rows.length} countries to ${out}`)
