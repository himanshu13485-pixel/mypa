/**
 * The third copy: a folder on the person's own computer.
 *
 * A server cannot reach a folder on somebody's laptop, and a mailbox opened
 * from three different machines has three different laptops - so this half
 * lives in the browser. The person picks a folder once; the browser
 * remembers the handle, and each time Mails is opened it drops the current
 * archive - a dated zip of .eml files, straight from the server's archive -
 * into that folder.
 *
 * It works in Chrome and Edge, which have the File System Access API.
 * Firefox and Safari do not, so there the choice is offered as an ordinary
 * download instead, and the person keeps it wherever they keep things.
 *
 * The handle lives in IndexedDB because it is an object, not text, and
 * localStorage only holds text. Permission is asked once and then confirmed
 * silently on later visits; a person who says no simply gets nothing
 * written, and the server's own copy is untouched either way.
 */

const DB = 'netvork-mails'
const STORE = 'handles'

type DirectoryHandle = FileSystemDirectoryHandle & {
  queryPermission?: (d: { mode: 'readwrite' }) => Promise<PermissionState>
  requestPermission?: (d: { mode: 'readwrite' }) => Promise<PermissionState>
}

type PickerWindow = Window & {
  showDirectoryPicker?: (options?: { mode?: 'readwrite'; id?: string }) => Promise<DirectoryHandle>
}

export const localFoldersSupported = (): boolean =>
  typeof window !== 'undefined' && typeof (window as PickerWindow).showDirectoryPicker === 'function'

function open(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB, 1)
    request.onupgradeneeded = () => request.result.createObjectStore(STORE)
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
}

async function put(key: string, value: DirectoryHandle | null): Promise<void> {
  const db = await open()
  await new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite')
    const store = tx.objectStore(STORE)
    if (value) store.put(value, key)
    else store.delete(key)
    tx.oncomplete = () => resolve(null)
    tx.onerror = () => reject(tx.error)
  })
  db.close()
}

async function get(key: string): Promise<DirectoryHandle | null> {
  const db = await open()
  const value = await new Promise<DirectoryHandle | null>((resolve, reject) => {
    const request = db.transaction(STORE, 'readonly').objectStore(STORE).get(key)
    request.onsuccess = () => resolve((request.result as DirectoryHandle) ?? null)
    request.onerror = () => reject(request.error)
  })
  db.close()

  return value
}

/** Ask for a folder, once, and remember it for this mailbox. */
export async function chooseFolder(mailbox: string): Promise<string | null> {
  const picker = (window as PickerWindow).showDirectoryPicker
  if (!picker) return null

  const handle = await picker({ mode: 'readwrite', id: 'netvork-mail-archive' })
  await put(mailbox, handle)

  return handle.name
}

export async function forgetFolder(mailbox: string): Promise<void> {
  await put(mailbox, null)
}

/** The folder chosen for this mailbox, if it is still ours to write to. */
export async function folderFor(mailbox: string, ask = false): Promise<DirectoryHandle | null> {
  try {
    const handle = await get(mailbox)
    if (!handle) return null

    const state = (await handle.queryPermission?.({ mode: 'readwrite' })) ?? 'granted'
    if (state === 'granted') return handle
    if (!ask) return null

    const asked = (await handle.requestPermission?.({ mode: 'readwrite' })) ?? 'denied'

    return asked === 'granted' ? handle : null
  } catch {
    return null
  }
}

export async function folderName(mailbox: string): Promise<string | null> {
  const handle = await get(mailbox)

  return handle?.name ?? null
}

/** Write one file into the chosen folder, replacing yesterday's of the same name. */
export async function writeToFolder(mailbox: string, filename: string, blob: Blob, ask = false): Promise<boolean> {
  const folder = await folderFor(mailbox, ask)
  if (!folder) return false

  const file = await folder.getFileHandle(filename, { create: true })
  const writable = await file.createWritable()
  await writable.write(blob)
  await writable.close()

  return true
}

/** When this mailbox was last written to the computer, and whether it is due. */
const stampKey = (mailbox: string) => `mails.local.${mailbox}`

export function lastLocalSync(mailbox: string): string | null {
  try {
    return window.localStorage.getItem(stampKey(mailbox))
  } catch {
    return null
  }
}

export function markLocalSync(mailbox: string): void {
  try {
    window.localStorage.setItem(stampKey(mailbox), new Date().toISOString())
  } catch { /* a private window remembers nothing, and that is fine */ }
}

export function localSyncDue(mailbox: string, hours = 24): boolean {
  const last = lastLocalSync(mailbox)

  return !last || Date.now() - new Date(last).getTime() > hours * 3600_000
}
