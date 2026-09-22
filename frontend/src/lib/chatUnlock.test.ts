import { describe, expect, it } from 'vitest'
import { hiddenFolderPassword, searchMeansMe } from './chatUnlock'

describe('the #password# that opens the hidden folder', () => {
  it('takes the password from between the hashes', () => {
    expect(hiddenFolderPassword('#123456#')).toBe('123456')
    expect(hiddenFolderPassword('  #tulip22#  ')).toBe('tulip22')
  })

  it('leaves an ordinary search alone', () => {
    expect(hiddenFolderPassword('123456')).toBeNull()
    expect(hiddenFolderPassword('#123456')).toBeNull()
    expect(hiddenFolderPassword('invoice #200622')).toBeNull()
  })

  it('ignores anything shorter or longer than a password can be', () => {
    expect(hiddenFolderPassword('#1#')).toBeNull()
    expect(hiddenFolderPassword('#' + 'x'.repeat(33) + '#')).toBeNull()
  })
})

describe('a search that means "me"', () => {
  const me = { name: 'Himanshu Sachdeva', username: 'himanshu', app_id: 'NV-100002' }

  it('matches your own name, handle and ID', () => {
    expect(searchMeansMe('himan', me)).toBe(true)
    expect(searchMeansMe('sachdeva', me)).toBe(true)
    expect(searchMeansMe('NV-100002', me)).toBe(true)
  })

  it('matches the words people type for it', () => {
    expect(searchMeansMe('me', me)).toBe(true)
    expect(searchMeansMe('self', me)).toBe(true)
    expect(searchMeansMe('notes', me)).toBe(true)
  })

  it('does not match somebody else', () => {
    expect(searchMeansMe('vishal', me)).toBe(false)
    expect(searchMeansMe('x', me)).toBe(false)
  })
})
