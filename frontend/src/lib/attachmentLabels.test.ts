import { describe, expect, it } from 'vitest'
import { fileSize, shortName } from './attachmentLabels'

describe('fileSize', () => {
  it('says bytes, kilobytes and megabytes as a person would', () => {
    expect(fileSize(512)).toBe('512 B')
    expect(fileSize(2048)).toBe('2 KB')
    expect(fileSize(5 * 1024 * 1024)).toBe('5.0 MB')
  })

  it('says nothing when there is nothing to say', () => {
    expect(fileSize(0)).toBe('')
    expect(fileSize(null)).toBe('')
    expect(fileSize(undefined)).toBe('')
  })
})

describe('shortName', () => {
  it('leaves a name that already fits alone', () => {
    expect(shortName('report.pdf')).toBe('report.pdf')
  })

  /*
   * The real case: four Android screenshots in one thread, whose names differ
   * only in the seconds and a trailing hash.
   */
  it('keeps both ends of a long name', () => {
    const real = 'Screenshot_2026-09-02-13-33-10-96_8d795430627e417c0bd26b64d9211e23.jpg'
    const short = shortName(real)

    expect(short.length).toBeLessThanOrEqual(28)
    expect(short).toContain('…')
    expect(short.startsWith('Screenshot')).toBe(true)
    expect(short.endsWith('.jpg')).toBe(true)
  })

  it('tells two otherwise identical names apart', () => {
    const a = shortName('Screenshot_2026-09-02-13-33-10-96_aaaaaaaaaaaaaaaa1111.jpg')
    const b = shortName('Screenshot_2026-09-02-13-33-10-96_aaaaaaaaaaaaaaaa2222.jpg')

    expect(a).not.toBe(b)
  })
})
