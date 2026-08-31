import { Suspense, useEffect } from 'react'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import ErrorBoundary from './components/ErrorBoundary'
import { lazyRoute, type PreloadableRoute } from './lib/lazyRoute'
import { MeetingRoomRoute } from './components/MeetingHost'
import Layout from './components/Layout'
import { RequireAdmin, RequireAuth, RequireGuestPass } from './components/Protected'
import { ToastProvider } from './components/Toast'
import { PromptProvider } from './components/Prompt'
import { Spinner } from './components/ui'
import Login from './pages/auth/Login'
import GuestJoinPage from './pages/GuestJoinPage'
import Register from './pages/auth/Register'

// Route-level code splitting keeps the initial bundle small; auth pages stay
// eager so first paint is instant.
const ForgotPassword = lazyRoute('ForgotPassword', () => import('./pages/auth/ForgotPassword'))
const ResetPassword = lazyRoute('ResetPassword', () => import('./pages/auth/ResetPassword'))
const VerifyEmail = lazyRoute('VerifyEmail', () => import('./pages/auth/VerifyEmail'))
const Dashboard = lazyRoute('Dashboard', () => import('./pages/Dashboard'))
const ServicePanelPage = lazyRoute('ServicePanelPage', () => import('./pages/ServicePanelPage'))
const ServiceSignIn = lazyRoute('ServiceSignIn', () => import('./pages/ServiceSignIn'))
const TasksPage = lazyRoute('TasksPage', () => import('./pages/TasksPage'))
const CalendarPage = lazyRoute('CalendarPage', () => import('./pages/CalendarPage'))
const CategoriesPage = lazyRoute('CategoriesPage', () => import('./pages/CategoriesPage'))
const ConnectionsPage = lazyRoute('ConnectionsPage', () => import('./pages/ConnectionsPage'))
const MessagesPage = lazyRoute('MessagesPage', () => import('./pages/MessagesPage'))
const CallsPage = lazyRoute('CallsPage', () => import('./pages/CallsPage'))
const NotesPage = lazyRoute('NotesPage', () => import('./pages/NotesPage'))
const FilesPage = lazyRoute('FilesPage', () => import('./pages/FilesPage'))
const GroupsPage = lazyRoute('GroupsPage', () => import('./pages/GroupsPage'))
const HabitsPage = lazyRoute('HabitsPage', () => import('./pages/HabitsPage'))
const GoalsPage = lazyRoute('GoalsPage', () => import('./pages/GoalsPage'))
const BillsPage = lazyRoute('BillsPage', () => import('./pages/BillsPage'))
const ProjectsPage = lazyRoute('ProjectsPage', () => import('./pages/ProjectsPage'))
const MeetingsPage = lazyRoute('MeetingsPage', () => import('./pages/MeetingsPage'))
const MeetingRoomPage = lazyRoute('MeetingRoomPage', () => import('./pages/MeetingRoomPage'))
const ScreenPage = lazyRoute('ScreenPage', () => import('./pages/ScreenPage'))
const ScreenSessionPage = lazyRoute('ScreenSessionPage', () => import('./pages/ScreenSessionPage'))
const ReportsPage = lazyRoute('ReportsPage', () => import('./pages/ReportsPage'))
const SettingsPage = lazyRoute('SettingsPage', () => import('./pages/SettingsPage'))
const AdminPage = lazyRoute('AdminPage', () => import('./pages/admin/AdminPage'))
const PricingPage = lazyRoute('PricingPage', () => import('./pages/PricingPage'))
function LandingRedirect() {
  // The brand landing is a self-contained static page (public/landing/).
  window.location.replace('/landing/index.html')
  return null
}
const AboutPage = lazyRoute('AboutPage', () => import('./pages/site/InfoPages').then((m) => ({ default: m.AboutPage })))
const ContactPage = lazyRoute('ContactPage', () => import('./pages/site/InfoPages').then((m) => ({ default: m.ContactPage })))
const TermsPage = lazyRoute('TermsPage', () => import('./pages/site/InfoPages').then((m) => ({ default: m.TermsPage })))
const PrivacyPage = lazyRoute('PrivacyPage', () => import('./pages/site/InfoPages').then((m) => ({ default: m.PrivacyPage })))
const SubscriptionPage = lazyRoute('SubscriptionPage', () => import('./pages/SubscriptionPage'))
const PaymentStatusPage = lazyRoute('PaymentStatusPage', () => import('./pages/PaymentStatusPage'))

/**
 * The sections reachable from the sidebar, fetched before anyone asks.
 *
 * Each page is its own file, so the first visit to each one waits on a network
 * round trip — and because that wait suspends, the screen changes twice: once
 * to a placeholder, once to the page. Doing it while the browser is idle means
 * the file is already in memory when somebody clicks, and the navigation is a
 * single repaint.
 *
 * The sidebar only, deliberately. Admin and the meeting room are large — the
 * meeting room drags in the whole LiveKit client — and most people never open
 * either. Speculatively downloading a megabyte someone will not use is not a
 * kindness on a phone.
 */
const SIDEBAR_ROUTES = [
  Dashboard, TasksPage, CalendarPage, MessagesPage, NotesPage, FilesPage,
  GroupsPage, HabitsPage, GoalsPage, ConnectionsPage, CallsPage, MeetingsPage,
  // The rest of the sidebar, added once the numbers were actually looked at:
  // every one of these is between 8 and 24KB before compression, so the
  // whole remaining list costs less than a single photo. Leaving them out
  // bought nothing and made Bills, Projects and Settings the three sections
  // that visibly waited.
  ProjectsPage, CategoriesPage, BillsPage, ReportsPage, SubscriptionPage, SettingsPage,
  ScreenPage,
]

/**
 * The heavy pages, fetched only when somebody looks like they are going.
 *
 * These are the two that genuinely cost something — the meeting room drags
 * in the whole LiveKit client, and most people never open the admin panel at
 * all — so they stay out of the idle sweep above. Instead the sidebar asks
 * for them the moment a pointer touches the link, which is a few hundred
 * milliseconds before the click lands: enough of a head start that the page
 * is usually ready by the time the navigation happens, and nothing is
 * downloaded for somebody who never goes near it.
 */
const ROUTES_BY_PATH: Record<string, PreloadableRoute> = {
  '/': Dashboard,
  '/dashboard': Dashboard,
  '/tasks': TasksPage,
  '/calendar': CalendarPage,
  '/categories': CategoriesPage,
  '/connections': ConnectionsPage,
  '/messages': MessagesPage,
  '/calls': CallsPage,
  '/notes': NotesPage,
  '/files': FilesPage,
  '/groups': GroupsPage,
  '/habits': HabitsPage,
  '/goals': GoalsPage,
  '/bills': BillsPage,
  '/projects': ProjectsPage,
  '/meetings': MeetingsPage,
  '/screen': ScreenPage,
  '/reports': ReportsPage,
  '/subscription': SubscriptionPage,
  '/settings': SettingsPage,
  '/admin': AdminPage,
}

/** Sidebar links carry a query string; the chunk is decided by the path. */
function preloadPath(to: string): void {
  ROUTES_BY_PATH[to.split('?')[0]]?.preload()
}

function usePreloadedSections() {
  useEffect(() => {
    const preload = () => SIDEBAR_ROUTES.forEach((route) => route.preload())

    /*
     * requestIdleCallback, so this never competes with the work the person is
     * actually waiting for. Safari has no such thing, hence the timeout — and
     * the timeout on the idle call itself, so a permanently busy tab still
     * gets there rather than never.
     */
    // Read off first: `'requestIdleCallback' in window` narrows window itself
    // in TypeScript's eyes, leaving the Safari branch unreachable.
    const idle: typeof window.requestIdleCallback | undefined = window.requestIdleCallback

    if (typeof idle === 'function') {
      const id = idle(preload, { timeout: 4000 })

      return () => window.cancelIdleCallback(id)
    }

    const id = window.setTimeout(preload, 2000)

    return () => window.clearTimeout(id)
  }, [])
}

export default function App() {
  usePreloadedSections()

  return (
    <ErrorBoundary>
    <ToastProvider>
    <PromptProvider>
    <BrowserRouter>
      <Suspense fallback={<Spinner className="h-screen" />}>
        <Routes>
          {/* Public: joining a meeting with the meeting password and no
              account. Reached by typing a code, and by the auth guard when
              somebody signed out opens the ordinary invite link — there is one
              link and everybody gets the same one. */}
          <Route path="/join" element={<GuestJoinPage />} />
          <Route path="/join/:code" element={<GuestJoinPage />} />
          {/* The room again, without the app shell or the auth guard — a guest
              has no account to be guarded by. Same component, so the two never
              drift apart. */}
          <Route
            path="/guest/room/:code"
            element={
              <RequireGuestPass>
                <MeetingRoomPage />
              </RequireGuestPass>
            }
          />
          <Route path="/login" element={<Login />} />
          <Route path="/register" element={<Register />} />
          <Route path="/forgot-password" element={<ForgotPassword />} />
          <Route path="/verify-email" element={<VerifyEmail />} />
          <Route path="/reset-password" element={<ResetPassword />} />
          <Route path="/home" element={<LandingRedirect />} />
          <Route path="/about" element={<AboutPage />} />
          <Route path="/contact" element={<ContactPage />} />
          <Route path="/terms" element={<TermsPage />} />
          <Route path="/privacy" element={<PrivacyPage />} />
          <Route path="/pricing" element={<PricingPage />} />
          <Route
            path="/payment/status"
            element={
              <RequireAuth>
                <PaymentStatusPage />
              </RequireAuth>
            }
          />
          {/* No password exists for these accounts, so they cannot come in
              through the ordinary door. */}
          <Route path="/service/sign-in" element={<ServiceSignIn />} />
          {/* An application's own panel. Outside Layout deliberately: the
              sidebar is a list of things a service account cannot use. */}
          <Route
            path="/service"
            element={
              <RequireAuth>
                <ServicePanelPage />
              </RequireAuth>
            }
          />
          <Route
            element={
              <RequireAuth>
                <Layout preloadPath={preloadPath} />
              </RequireAuth>
            }
          >
            <Route path="/" element={<Dashboard />} />
            {/* In production "/" serves the static brand landing, so signed-in
                users arriving there are bounced to this alias instead. */}
            <Route path="/dashboard" element={<Dashboard />} />
            <Route path="/tasks" element={<TasksPage />} />
            <Route path="/calendar" element={<CalendarPage />} />
            <Route path="/categories" element={<CategoriesPage />} />
            <Route path="/connections" element={<ConnectionsPage />} />
            <Route path="/messages" element={<MessagesPage />} />
            <Route path="/calls" element={<CallsPage />} />
            <Route path="/notes" element={<NotesPage />} />
            <Route path="/files" element={<FilesPage />} />
            <Route path="/groups" element={<GroupsPage />} />
            <Route path="/habits" element={<HabitsPage />} />
            <Route path="/goals" element={<GoalsPage />} />
            <Route path="/bills" element={<BillsPage />} />
            <Route path="/projects" element={<ProjectsPage />} />
            <Route path="/meetings" element={<MeetingsPage />} />
            <Route path="/meetings/room/:code" element={<MeetingRoomRoute />} />
            <Route path="/screen" element={<ScreenPage />} />
            <Route path="/screen/session/:code" element={<ScreenSessionPage />} />
            <Route path="/reports" element={<ReportsPage />} />
            <Route path="/subscription" element={<SubscriptionPage />} />
            <Route path="/settings" element={<SettingsPage />} />
            <Route
              path="/admin"
              element={
                <RequireAdmin>
                  <AdminPage />
                </RequireAdmin>
              }
            />
          </Route>
        </Routes>
      </Suspense>
    </BrowserRouter>
    </PromptProvider>
    </ToastProvider>
    </ErrorBoundary>
  )
}
