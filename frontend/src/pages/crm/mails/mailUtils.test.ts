import { describe, expect, it } from 'vitest'
import type { MailFull } from '../../../api/mails'
import { mailDate, replyRecipients, replySubject, sizeLabel, textToHtml } from './mailUtils'

const mail = (over: Partial<MailFull> = {}): MailFull => ({
  uuid: 'u', folder: 'inbox', thread_key: 't', from_name: 'Priyanshu', from_email: 'priya@client.test',
  to: [{ email: 'me@grapout.test', name: 'Me' }, { email: 'vishal@grapout.test', name: 'Vishal' }],
  cc: [{ email: 'ops@client.test', name: null }], bcc: [], reply_to: null, message_id: '<m@x>',
  subject: 'Rates', snippet: '', body_html: null, body_text: 'hi', has_attachments: false, is_read: true,
  is_starred: false, date: '2026-09-22T10:00:00Z', scheduled_for: null, send_after: null, status: null,
  error: null, account_uuid: 'a', labels: [], attachments: [], ...over,
})

describe('replying', () => {
  it('answers the sender, and only the sender', () => {
    expect(replyRecipients(mail(), ['me@grapout.test'], false)).toEqual({ to: ['Priyanshu <priya@client.test>'], cc: [] })
  })

  it('answers the Reply-To when the sender asked for answers elsewhere', () => {
    expect(replyRecipients(mail({ reply_to: 'sales@client.test' }), ['me@grapout.test'], false).to).toEqual(['sales@client.test'])
  })

  it('answers everybody on reply all, except the person replying', () => {
    const { to, cc } = replyRecipients(mail(), ['me@grapout.test'], true)
    expect(to).toEqual(['Priyanshu <priya@client.test>'])
    expect(cc).toEqual(['Vishal <vishal@grapout.test>', 'ops@client.test'])
  })

  it('writes to the original recipients when the mail being answered was my own', () => {
    const mine = mail({ from_email: 'me@grapout.test', to: [{ email: 'priya@client.test', name: 'Priyanshu' }], cc: [] })
    expect(replyRecipients(mine, ['me@grapout.test'], false).to).toEqual(['Priyanshu <priya@client.test>'])
  })

  it('never stacks Re: on Re:', () => {
    expect(replySubject('Re: RE: Fwd: Rates', 'Re')).toBe('Re: Rates')
    expect(replySubject('Rates', 'Fwd')).toBe('Fwd: Rates')
  })
})

describe('the small things', () => {
  it('dates today by the time and older mail by the day', () => {
    const now = new Date('2026-09-22T15:00:00')
    expect(mailDate('2026-09-22T09:05:00', now)).toMatch(/9|09/)
    expect(mailDate('2026-03-04T09:05:00', now)).toMatch(/Mar|3/)
    expect(mailDate(null, now)).toBe('')
  })

  it('sizes files in the units people read', () => {
    expect(sizeLabel(512)).toBe('512 B')
    expect(sizeLabel(1536)).toBe('1.5 KB')
    expect(sizeLabel(5 * 1024 * 1024)).toBe('5.0 MB')
  })

  it('turns a plain draft into paragraphs, escaped', () => {
    expect(textToHtml('Hello <team>\n\nThanks,\nMe')).toBe('<p>Hello &lt;team&gt;</p><p>Thanks,<br>Me</p>')
  })
})
