import { describe, expect, it } from 'vitest'
import { foldersIn, type DroppedFile } from './dropUpload'

const at = (path: string[], name: string): DroppedFile =>
  ({ file: new File(['x'], name), path })

describe('foldersIn', () => {
  it('lists every folder in a drop, parents before their children', () => {
    const folders = foldersIn([
      at(['Invoices', '2026', 'Q1'], 'a.pdf'),
      at(['Invoices', '2026'], 'b.pdf'),
      at(['Photos'], 'c.jpg'),
    ])

    // Shallowest first: a folder can only be made once its parent exists.
    expect(folders.map((f) => f.join('/'))).toEqual([
      'Invoices',
      'Photos',
      'Invoices/2026',
      'Invoices/2026/Q1',
    ])
  })

  it('names each folder once however many files sit in it', () => {
    const folders = foldersIn([
      at(['Reports'], 'one.pdf'),
      at(['Reports'], 'two.pdf'),
      at(['Reports'], 'three.pdf'),
    ])

    expect(folders).toHaveLength(1)
  })

  it('has nothing to make for files dropped on their own', () => {
    expect(foldersIn([at([], 'loose.pdf')])).toEqual([])
  })
})
