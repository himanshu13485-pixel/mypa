import { lazy, Suspense } from 'react'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import ErrorBoundary from './components/ErrorBoundary'
import Layout from './components/Layout'
import { RequireAdmin, RequireAuth } from './components/Protected'
import { ToastProvider } from './components/Toast'
import { Spinner } from './components/ui'
import Login from './pages/auth/Login'
import GuestJoinPage from './pages/GuestJoinPage'
import Register from './pages/auth/Register'

// Route-level code splitting keeps the initial bundle small; auth pages stay
// eager so first paint is instant.
const ForgotPassword = lazy(() => import('./pages/auth/ForgotPassword'))
const ResetPassword = lazy(() => import('./pages/auth/ResetPassword'))
const VerifyEmail = lazy(() => import('./pages/auth/VerifyEmail'))
const Dashboard = lazy(() => import('./pages/Dashboard'))
const TasksPage = lazy(() => import('./pages/TasksPage'))
const CalendarPage = lazy(() => import('./pages/CalendarPage'))
const CategoriesPage = lazy(() => import('./pages/CategoriesPage'))
const ConnectionsPage = lazy(() => import('./pages/ConnectionsPage'))
const MessagesPage = lazy(() => import('./pages/MessagesPage'))
const CallsPage = lazy(() => import('./pages/CallsPage'))
const NotesPage = lazy(() => import('./pages/NotesPage'))
const FilesPage = lazy(() => import('./pages/FilesPage'))
const GroupsPage = lazy(() => import('./pages/GroupsPage'))
const HabitsPage = lazy(() => import('./pages/HabitsPage'))
const GoalsPage = lazy(() => import('./pages/GoalsPage'))
const BillsPage = lazy(() => import('./pages/BillsPage'))
const ProjectsPage = lazy(() => import('./pages/ProjectsPage'))
const MeetingsPage = lazy(() => import('./pages/MeetingsPage'))
const MeetingRoomPage = lazy(() => import('./pages/MeetingRoomPage'))
const ScreenPage = lazy(() => import('./pages/ScreenPage'))
const ScreenSessionPage = lazy(() => import('./pages/ScreenSessionPage'))
const ReportsPage = lazy(() => import('./pages/ReportsPage'))
const SettingsPage = lazy(() => import('./pages/SettingsPage'))
const AdminPage = lazy(() => import('./pages/admin/AdminPage'))
const PricingPage = lazy(() => import('./pages/PricingPage'))
function LandingRedirect() {
  // The brand landing is a self-contained static page (public/landing/).
  window.location.replace('/landing/index.html')
  return null
}
const AboutPage = lazy(() => import('./pages/site/InfoPages').then((m) => ({ default: m.AboutPage })))
const ContactPage = lazy(() => import('./pages/site/InfoPages').then((m) => ({ default: m.ContactPage })))
const TermsPage = lazy(() => import('./pages/site/InfoPages').then((m) => ({ default: m.TermsPage })))
const PrivacyPage = lazy(() => import('./pages/site/InfoPages').then((m) => ({ default: m.PrivacyPage })))
const SubscriptionPage = lazy(() => import('./pages/SubscriptionPage'))
const PaymentStatusPage = lazy(() => import('./pages/PaymentStatusPage'))

export default function App() {
  return (
    <ErrorBoundary>
    <ToastProvider>
    <BrowserRouter>
      <Suspense fallback={<Spinner className="h-screen" />}>
        <Routes>
          {/* Public: joining a meeting with a passcode and no account. */}
          <Route path="/join" element={<GuestJoinPage />} />
          {/* Same page with the code already filled in, so invite links that
              are already out there keep working. */}
          <Route path="/join/:code" element={<GuestJoinPage />} />
          {/* The room again, without the app shell or the auth guard — a guest
              has no account to be guarded by. Same component, so the two never
              drift apart. */}
          <Route path="/guest/room/:code" element={<MeetingRoomPage />} />
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
            <Route path="/meetings/room/:code" element={<MeetingRoomPage />} />
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
    </ToastProvider>
    </ErrorBoundary>
  )
}
