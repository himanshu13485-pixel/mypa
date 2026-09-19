/**
 * Reading a lead out of the message it arrived in.
 *
 * Leads turn up as text — pasted out of a mail, a WhatsApp message, an
 * enquiry form — and every one of them is retyped into five boxes by
 * somebody who has the answer on the screen already. That is where the
 * typos in the phone numbers come from.
 *
 * So the text is read instead. Anything labelled is taken from its label;
 * an address and a number are recognisable whether or not anybody labelled
 * them. Nothing is consumed: the message stays where it was pasted, because
 * the parts nobody has a box for — what they are looking for, which market,
 * what they said — are the parts the salesperson actually reads.
 */
export type ParsedLead = {
  company_name?: string
  contact_person?: string
  email?: string
  mobile?: string
  phone?: string
}

/** The labels people actually write, against the box each one fills. */
const LABELS: [RegExp, keyof ParsedLead][] = [
  [/^(company|company name|firm|organisation|organization|business)$/i, 'company_name'],
  [/^(name|contact|contact person|contact name|person|client|attention|attn)$/i, 'contact_person'],
  [/^(e-?mail|email id|mail|mail id)$/i, 'email'],
  [/^(mobile|mobile no|cell|whatsapp|contact no|contact number|phone|phone no|tel|telephone|number)$/i, 'mobile'],
]

const EMAIL = /[\w.+-]+@[\w-]+\.[\w.-]+/
/*
 * A phone number, as people write them: +91 98765 43210, 098765-43210,
 * (0172) 4567890. Seven digits is the shortest thing worth ringing, and
 * anything past fifteen is a GST number or an invoice reference that
 * happens to be long.
 */
const PHONE = /(?:\+\d{1,3}[\s-]?)?(?:\(?\d{2,5}\)?[\s-]?)?\d{3,5}[\s-]?\d{3,5}(?:[\s-]?\d{1,4})?/

const digits = (value: string) => value.replace(/\D/g, '')

/** Tidy, without editing: spacing and stray punctuation only. */
const clean = (value: string) => value.replace(/\s+/g, ' ').replace(/^[-:,.\s]+|[-,.\s]+$/g, '').trim()

/**
 * A phone as typed, minus the noise.
 *
 * The country code and any leading zero are kept — they are part of dialling
 * it — while the spaces, brackets and dashes somebody used to make it
 * readable are not worth storing.
 */
const tidyPhone = (value: string) => {
  const kept = value.replace(/[^\d+]/g, '')

  return kept.startsWith('+') ? '+' + kept.slice(1).replace(/\+/g, '') : kept
}

const plausiblePhone = (value: string) => {
  const count = digits(value).length

  return count >= 7 && count <= 15
}

export function parseLeadText(text: string): ParsedLead {
  const found: ParsedLead = {}
  const numbers: string[] = []

  /*
   * One number, however many times it is written.
   *
   * People sign off with the same number twice - once as "Contact" and
   * again as "WhatsApp" - and the last ten digits are what decides, since
   * one copy carries +91 and the other does not.
   */
  const addNumber = (value: string) => {
    if (!plausiblePhone(value)) return
    const tidied = tidyPhone(value)
    if (numbers.some((n) => digits(n).slice(-10) === digits(tidied).slice(-10))) return
    numbers.push(tidied)
  }

  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim()
    if (!line) continue

    /*
     * "Label : value", where the label is a word or three.
     *
     * Bounded on purpose: a sentence with a colon in the middle of it is a
     * sentence, not a field, and reading it as one is how "Message: call me
     * on Monday" ends up in the company name.
     */
    const pair = line.match(/^([A-Za-z][A-Za-z\s/_-]{0,24}?)\s*[:–-]\s*(.+)$/)
    if (pair) {
      const label = pair[1].trim().replace(/\s+/g, ' ')
      const value = clean(pair[2])
      const match = LABELS.find(([pattern]) => pattern.test(label))

      if (match && value) {
        /*
         * The value settles what the label could not.
         *
         * "Contact" means a person to half the people who write it and a
         * number to the other half, and the line itself says which: an
         * address has an @ in it, and a number is digits. Trusting the
         * label alone is how "Contact : 9872995771" became somebody's name.
         */
        const key = EMAIL.test(value) ? 'email'
          : /^[\d\s+()-]+$/.test(value) && plausiblePhone(value) ? 'mobile'
            : match[1]

        if (key === 'mobile') {
          addNumber(value)
          continue
        }
        if (key === 'email') {
          if (!found.email) found.email = (value.match(EMAIL) ?? [value])[0].toLowerCase()
          continue
        }
        // The first one wins: a signature repeating the name at the bottom
        // should not overwrite the one that was given properly.
        if (!found[key]) found[key] = value
        continue
      }
    }

    // Unlabelled, or labelled something nobody thought of: an address and a
    // number still announce themselves.
    if (!found.email) {
      const mail = line.match(EMAIL)
      if (mail) found.email = mail[0].toLowerCase()
    }

    const number = line.match(PHONE)
    if (number && !EMAIL.test(line)) addNumber(number[0])
  }

  /*
   * Two numbers, two boxes.
   *
   * The first is the one to ring, because it is the one they wrote first.
   * A second goes to the landline box rather than being dropped — it was
   * given for a reason.
   */
  if (numbers[0]) found.mobile = numbers[0]
  if (numbers[1]) found.phone = numbers[1]

  /*
   * A lead must belong to a company, and plenty of enquiries name only a
   * person. Rather than refuse to fill anything, the person's name stands
   * in — which is what the office was already typing by hand.
   */
  if (!found.company_name && found.contact_person) found.company_name = found.contact_person

  return found
}

/** What was found, in a sentence — so the button says what it did. */
export function describeParsed(parsed: ParsedLead): string {
  const said: string[] = []
  if (parsed.company_name) said.push('company')
  if (parsed.contact_person) said.push('contact')
  if (parsed.mobile) said.push('mobile')
  if (parsed.phone) said.push('phone')
  if (parsed.email) said.push('email')

  return said.length ? `Filled ${said.join(', ')} from the text.` : 'Nothing recognisable in the text yet.'
}
