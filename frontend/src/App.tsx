import { Suspense } from 'react'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import ErrorBoundary from './components/ErrorBoundary'
import { lazyRoute } from './lib/lazyRoute'
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

export default function App() {
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
          <Route
            element={
              <RequireAuth>
                <Layout />
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
