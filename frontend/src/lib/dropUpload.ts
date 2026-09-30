/**
 * What was dropped, flattened into files that know where they belong.
 *
 * A dropped file is easy - dataTransfer.files has it. A dropped *folder* is
 * not there at all: the browser only admits to a directory through
 * webkitGetAsEntry, and only while the drop event is still being handled,
 * which is why the entries are taken before anything is awaited.
 *
 * Non-standard and named for a vendor, and the only way any browser does
 * this. Chrome, Edge, Safari and Firefox all implement it.
 */
export type DroppedFile = {
  file: File
  /** Folders above it, outermost first. Empty for a file dropped on its own. */
  path: string[]
}

type Entry = {
  isFile: boolean
  isDirectory: boolean
  name: string
  file: (cb: (f: File) => void, err: (e: unknown) => void) => void
  createReader: () => { readEntries: (cb: (entries: Entry[]) => void, err: (e: unknown) => void) => void }
}

/** One directory's children. Read in batches, because that is the API's habit. */
function children(entry: Entry): Promise<Entry[]> {
  const reader = entry.createReader()
  const all: Entry[] = []

  return new Promise((resolve) => {
    const readBatch = () => reader.readEntries((batch) => {
      // An empty batch means the end, not an empty folder.
      if (!batch.length) return resolve(all)
      all.push(...batch)
      readBatch()
    }, () => resolve(all))
    readBatch()
  })
}

function fileOf(entry: Entry): Promise<File | null> {
  return new Promise((resolve) => entry.file((f) => resolve(f), () => resolve(null)))
}

async function walk(entry: Entry, path: string[], into: DroppedFile[], budget: { left: number }): Promise<void> {
  if (budget.left <= 0) return

  if (entry.isFile) {
    const file = await fileOf(entry)
    // macOS drops a .DS_Store into everything; nobody means to upload it.
    if (file && file.name !== '.DS_Store' && !file.name.startsWith('.~')) {
      budget.left -= 1
      into.push({ file, path })
    }

    return
  }

  if (entry.isDirectory) {
    for (const child of await children(entry)) {
      await walk(child, [...path, entry.name], into, budget)
    }
  }
}

/**
 * Everything in a drop, folders walked through.
 *
 * `limit` is a guard rather than a rule: somebody who drags their Documents
 * folder in by accident should be told, not left watching a browser tab
 * upload nine thousand files.
 */
export async function filesFromDrop(transfer: DataTransfer, limit = 200): Promise<{
  files: DroppedFile[]
  truncated: boolean
}> {
  // Taken synchronously: the items are void once this handler yields.
  const entries = Array.from(transfer.items)
    .map((item) => (item.kind === 'file'
      ? (item.webkitGetAsEntry?.() as Entry | null | undefined) ?? null
      : null))
    .filter((entry): entry is Entry => entry !== null)

  const budget = { left: limit }
  const found: DroppedFile[] = []

  if (entries.length) {
    for (const entry of entries) await walk(entry, [], found, budget)
  } else {
    // A browser that will not admit to entries still gives plain files.
    for (const file of Array.from(transfer.files)) {
      if (budget.left <= 0) break
      budget.left -= 1
      found.push({ file, path: [] })
    }
  }

  return { files: found, truncated: budget.left <= 0 }
}

/** The distinct folder paths in a drop, parents before their children. */
export function foldersIn(files: DroppedFile[]): string[][] {
  const seen = new Map<string, string[]>()

  for (const { path } of files) {
    for (let i = 1; i <= path.length; i++) {
      const branch = path.slice(0, i)
      seen.set(branch.join('/'), branch)
    }
  }

  // Shallowest first, so a folder is always made after its parent exists.
  return [...seen.values()].sort((a, b) => a.length - b.length)
}
