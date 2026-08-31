import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { useLocation, useParams } from 'react-router-dom'

import { lazyRoute } from '../lib/lazyRoute'

const MeetingRoomPage = lazyRoute('MeetingRoomPage', () => import('../pages/MeetingRoomPage'))

/**
 * Keeps a meeting alive while you walk around the app.
 *
 * The room used to be a route, so opening Dashboard or Files unmounted it and
 * the meeting simply ended — no warning, no way back, and no floating window
 * on any device. Calls never had that problem because CallProvider sits
 * outside the Outlet; this puts meetings on the same footing.
 *
 * The room is therefore rendered here, beside the router rather than inside
 * it, and the route only says which meeting is open. Navigating changes
 * nothing about the connection: the same component keeps running and simply
 * draws itself small.
 *
 * Distinct from Document Picture-in-Picture, which is what happens when you
 * leave the browser altogether. That is a Chrome and Edge feature on the
 * desktop and exists nowhere else, which is why the floating window appeared
 * on some devices and not others. This works everywhere, because it is our
 * own markup.
 */
interface MeetingSession {
  /** The meeting being held, or null. */
  code: string | null
  /** True while you are somewhere else in the app and it is a floating tile. */
  minimised: boolean
  open: (code: string) => void
  close: () => void
}

/*
 * Through context rather than props, because the room is loaded lazily and a
 * lazy component's props are awkward to type — and because the guest route
 * renders the room straight from the router with no host above it. The
 * defaults here are what that guest sees: one meeting, full size, nothing to
 * close.
 */
const Ctx = createContext<MeetingSession>({
  code: null, minimised: false, open: () => {}, close: () => {},
})

export const useMeetingSession = () => useContext(Ctx)

/** Where the room lives while it is on screen full size. */
const MEETING_ROUTE = /^\/meetings\/room\//

/**
 * The route element. It holds no meeting of its own — it hands the code to the
 * host above and steps aside, leaving a gap the real room fills.
 */
export function MeetingRoomRoute() {
  const { code = '' } = useParams()
  const { open } = useMeetingSession()

  useEffect(() => {
    if (code) open(code)
  }, [code, open])

  return null
}

export function MeetingHost({ children }: { children: React.ReactNode }) {
  const [code, setCode] = useState<string | null>(null)
  const { pathname } = useLocation()
  const onRoute = MEETING_ROUTE.test(pathname)

  const close = useCallback(() => setCode(null), [])
  const session = useMemo<MeetingSession>(
    () => ({ code, minimised: !onRoute, open: setCode, close }),
    [code, onRoute, close],
  )

  return <Ctx.Provider value={session}>{children}</Ctx.Provider>
}

/**
 * Where the room is actually drawn — beside the Outlet, inside the same main
 * element, and never moved.
 *
 * Position in the React tree is what decides whether a component keeps its
 * state, so this renders in exactly one place whether the room is full size
 * or a floating tile; only its own styling changes. Moving it — into a portal,
 * or under a different parent when minimised — would unmount and remount it,
 * which is precisely the meeting-ending behaviour this exists to prevent.
 *
 * On the meeting route the Outlet renders nothing (see MeetingRoomRoute), so
 * the room has the main area to itself. Anywhere else the Outlet draws the
 * page and the room floats above it.
 */
export function MeetingSlot() {
  const { code } = useMeetingSession()

  return code ? <MeetingRoomPage /> : null
}
