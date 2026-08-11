/**
 * The mesh-to-SFU handover, as arithmetic on a list of tiles.
 *
 * When a meeting outgrows the mesh everybody moves to the SFU, and the naive
 * way to do that — drop the peer connections, connect to the server, start
 * again — blacks the room out for as long as it takes the slowest browser to
 * arrive. Nobody would call that seamless.
 *
 * So both paths run at once and the room crosses over one person at a time. A
 * mesh connection is only let go once that person's video is already arriving
 * from the server; until then their tile keeps showing what the mesh is giving
 * it. There is no instant at which any tile has nothing to show, which is the
 * entire point.
 *
 * This lives outside the room component because it is where the seam would be
 * if there were one, and a black tile lasting half a second is not something
 * anyone would reliably catch by looking.
 */

export interface HandoverTile {
  uuid: string
  stream: MediaStream | null
}

/**
 * Who can now be let go of on the mesh: everyone whose media is already
 * arriving from the server.
 *
 * Connected but not publishing does not count. A participant who has reached
 * the SFU and has no track yet — camera off, still negotiating — would leave a
 * blank tile if the mesh connection went now, and the mesh is still carrying
 * them perfectly well.
 */
export function readyToRetire<T extends HandoverTile>(fromSfu: T[]): string[] {
  return fromSfu.filter((p) => p.stream).map((p) => p.uuid)
}

/**
 * The room, mid-handover: what the mesh has, plus what the SFU has, preferring
 * the SFU wherever it is actually delivering.
 *
 * @param stillOnMesh who we still hold a peer connection to. Somebody who has
 *   left the SFU's list but is still meshed is not gone — they are simply
 *   behind, and dropping them here would take a working tile off the screen.
 */
export function mergeForHandover<T extends HandoverTile>(
  current: T[],
  fromSfu: T[],
  stillOnMesh: (uuid: string) => boolean,
): T[] {
  const merged = new Map(current.map((p) => [p.uuid, p]))

  for (const incoming of fromSfu) {
    const existing = merged.get(incoming.uuid)
    // No stream from the server yet: keep whatever the mesh is still giving
    // us rather than blanking the tile.
    if (!incoming.stream && existing) continue
    merged.set(incoming.uuid, {
      ...(existing ?? {}),
      ...incoming,
      stream: incoming.stream ?? existing?.stream ?? null,
    })
  }

  const onSfu = new Set(fromSfu.map((p) => p.uuid))

  return [...merged.values()].filter((p) => onSfu.has(p.uuid) || stillOnMesh(p.uuid))
}
