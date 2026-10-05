import { describe, expect, it } from 'vitest'
import { plain, spans } from './chatFormat'

/** The spans as "text" and "text[bold]", which reads like the message does. */
const shape = (text: string) =>
  spans(text).map((s) => (s.marks.length ? `${s.text}[${s.marks.join('+')}]` : s.text))

describe('the marks people already type into a chat', () => {
  it('makes a starred word bold', () => {
    expect(shape('this is *urgent* now')).toEqual(['this is ', 'urgent[bold]', ' now'])
  })

  it('knows the other three as well', () => {
    expect(shape('_quietly_')).toEqual(['quietly[italic]'])
    expect(shape('~not any more~')).toEqual(['not any more[strike]'])
    expect(shape('`php artisan`')).toEqual(['php artisan[code]'])
  })

  it('reads three backticks as a block, never as an empty pair', () => {
    expect(shape('```npm run build```')).toEqual(['npm run build[code]'])
  })

  it('carries marks through one another', () => {
    expect(shape('*_both at once_*')).toEqual(['both at once[bold+italic]'])
  })

  it('leaves arithmetic alone', () => {
    // A mark has to hug its text; this is two numbers and a sum.
    expect(shape('2 * 3 * 4')).toEqual(['2 * 3 * 4'])
  })

  it('leaves a variable name alone', () => {
    expect(shape('call get_user_id for it')).toEqual(['call get_user_id for it'])
  })

  it('leaves half a pair exactly as it was typed', () => {
    expect(shape('5 * 4 = twenty')).toEqual(['5 * 4 = twenty'])
    expect(shape('*never closed')).toEqual(['*never closed'])
  })

  it('shows code exactly as written, marks and all', () => {
    expect(shape('`a * b` and *this*')).toEqual(['a * b[code]', ' and ', 'this[bold]'])
  })

  it('handles several in one message', () => {
    expect(shape('*one* and _two_ and ~three~')).toEqual([
      'one[bold]', ' and ', 'two[italic]', ' and ', 'three[strike]',
    ])
  })

  it('says nothing about an empty message', () => {
    expect(spans('')).toEqual([])
  })

  it('does not mistake a line of stars for formatting', () => {
    expect(shape('***')).toEqual(['***'])
  })

  it('strips the marks for a chat list line', () => {
    expect(plain('this is *urgent* and _late_')).toBe('this is urgent and late')
    expect(plain('2 * 3 is still 6')).toBe('2 * 3 is still 6')
  })
})
