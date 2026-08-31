import { Suspense } from 'react'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import ErrorBoundary from './components/ErrorBoundary'
import { lazyRoute } from './lib/lazyRoute'
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
// The CRM addon: its own shell and route tree, fully apart from the personal app.
const CrmLayout = lazyRoute('CrmLayout', () => import('./pages/crm/CrmLayout'))
const CrmDashboard = lazyRoute('CrmDashboard', () => import('./pages/crm/CrmDashboard'))
const CrmEmployeesPage = lazyRoute('CrmEmployeesPage', () => import('./pages/crm/CrmEmployeesPage'))
const CrmEmployeeFormPage = lazyRoute('CrmEmployeeFormPage', () => import('./pages/crm/CrmEmployeeFormPage'))
const CrmClientsPage = lazyRoute('CrmClientsPage', () => import('./pages/crm/CrmClientsPage'))
const CrmClientDetailPage = lazyRoute('CrmClientDetailPage', () => import('./pages/crm/CrmClientDetailPage'))
const CrmLeadsPage = lazyRoute('CrmLeadsPage', () => import('./pages/crm/CrmLeadsPage'))
const CrmLeadDetailPage = lazyRoute('CrmLeadDetailPage', () => import('./pages/crm/CrmLeadDetailPage'))
const CrmLeadLogPage = lazyRoute('CrmLeadLogPage', () => import('./pages/crm/CrmLeadLogPage'))
const CrmTargetsPage = lazyRoute('CrmTargetsPage', () => import('./pages/crm/CrmTargetsPage'))
const CrmContestsPage = lazyRoute('CrmContestsPage', () => import('./pages/crm/CrmContestsPage'))
const CrmContestPlayPage = lazyRoute('CrmContestPlayPage', () => import('./pages/crm/CrmContestPlayPage'))
const CrmDwrPage = lazyRoute('CrmDwrPage', () => import('./pages/crm/CrmDwrPage'))
const CrmPunchPage = lazyRoute('CrmPunchPage', () => import('./pages/crm/CrmPunchPage'))
const CrmPaymentsPage = lazyRoute('CrmPaymentsPage', () => import('./pages/crm/CrmPaymentsPage'))
const CrmExpensesPage = lazyRoute('CrmExpensesPage', () => import('./pages/crm/CrmExpensesPage'))
const CrmVendorsPage = lazyRoute('CrmVendorsPage', () => import('./pages/crm/CrmVendorsPage'))
const CrmComplaintsPage = lazyRoute('CrmComplaintsPage', () => import('./pages/crm/CrmComplaintsPage'))
const CrmComplaintDetailPage = lazyRoute('CrmComplaintDetailPage', () => import('./pages/crm/CrmComplaintDetailPage'))
const CrmComplaintLogPage = lazyRoute('CrmComplaintLogPage', () => import('./pages/crm/CrmComplaintLogPage'))
const CrmHrPolicyPage = lazyRoute('CrmHrPolicyPage', () => import('./pages/crm/CrmHrPolicyPage'))
const CrmIncentivesPage = lazyRoute('CrmIncentivesPage', () => import('./pages/crm/CrmIncentivesPage'))
const CrmSalaryPage = lazyRoute('CrmSalaryPage', () => import('./pages/crm/CrmSalaryPage'))
const CrmLeavesPage = lazyRoute('CrmLeavesPage', () => import('./pages/crm/CrmLeavesPage'))
const CrmTasksPage = lazyRoute('CrmTasksPage', () => import('./pages/crm/CrmTasksPage'))
const CrmApprovalsPage = lazyRoute('CrmApprovalsPage', () => import('./pages/crm/CrmApprovalsPage'))
const CrmNewslettersPage = lazyRoute('CrmNewslettersPage', () => import('./pages/crm/CrmNewslettersPage'))
const CrmCmsPage = lazyRoute('CrmCmsPage', () => import('./pages/crm/CrmCmsPage'))
const CrmUserLogPage = lazyRoute('CrmUserLogPage', () => import('./pages/crm/CrmUserLogPage'))
const CrmReportsPage = lazyRoute('CrmReportsPage', () => import('./pages/crm/CrmReportsPage'))
const CrmWorkspaceFieldsPage = lazyRoute('CrmWorkspaceFieldsPage', () => import('./pages/crm/CrmWorkspaceFieldsPage'))
const CrmFieldRequestsPage = lazyRoute('CrmFieldRequestsPage', () => import('./pages/crm/CrmFieldRequestsPage'))
const CrmInvoicesPage = lazyRoute('CrmInvoicesPage', () => import('./pages/crm/CrmInvoicesPage'))
const CrmInvoiceLogPage = lazyRoute('CrmInvoiceLogPage', () => import('./pages/crm/CrmInvoiceLogPage'))
const CrmRecurringPage = lazyRoute('CrmRecurringPage', () => import('./pages/crm/CrmRecurringPage'))
const CrmCommissionsPage = lazyRoute('CrmCommissionsPage', () => import('./pages/crm/CrmCommissionsPage'))
const CrmInvoiceFormPage = lazyRoute('CrmInvoiceFormPage', () => import('./pages/crm/CrmInvoiceFormPage'))
const CrmInvoiceViewPage = lazyRoute('CrmInvoiceViewPage', () => import('./pages/crm/CrmInvoiceViewPage'))
const CrmSettingsPage = lazyRoute('CrmSettingsPage', () => import('./pages/crm/CrmSettingsPage'))
const CrmOrganizationsPage = lazyRoute('CrmOrganizationsPage', () => import('./pages/crm/CrmOrganizationsPage'))
const CrmPlPage = lazyRoute('CrmPlPage', () => import('./pages/crm/CrmPlPage'))
const CrmAssetsPage = lazyRoute('CrmAssetsPage', () => import('./pages/crm/CrmAssetsPage'))
const CrmChurnPage = lazyRoute('CrmChurnPage', () => import('./pages/crm/CrmChurnPage'))
const CrmCommunicationPage = lazyRoute('CrmCommunicationPage', () => import('./pages/crm/CrmCommunicationPage'))

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
          {/* CRM addon: its own shell, not the personal Layout. */}
          <Route
            path="/crm"
            element={
              <RequireAuth>
                <CrmLayout />
              </RequireAuth>
            }
          >
            <Route index element={<CrmDashboard />} />
            <Route path="employees" element={<CrmEmployeesPage />} />
            <Route path="employees/new" element={<CrmEmployeeFormPage />} />
            <Route path="employees/:uuid" element={<CrmEmployeeFormPage />} />
            <Route path="clients" element={<CrmClientsPage />} />
            <Route path="clients/:uuid" element={<CrmClientDetailPage />} />
            <Route path="leads" element={<CrmLeadsPage />} />
            <Route path="leads/:uuid" element={<CrmLeadDetailPage />} />
            <Route path="lead-log" element={<CrmLeadLogPage />} />
            <Route path="targets" element={<CrmTargetsPage />} />
            <Route path="dwr" element={<CrmDwrPage />} />
            <Route path="punch" element={<CrmPunchPage />} />
            <Route path="payments" element={<CrmPaymentsPage />} />
            <Route path="complaints" element={<CrmComplaintsPage />} />
            <Route path="complaint-log" element={<CrmComplaintLogPage />} />
            <Route path="hr-policy" element={<CrmHrPolicyPage />} />
            <Route path="incentives" element={<CrmIncentivesPage />} />
            <Route path="complaints/:uuid" element={<CrmComplaintDetailPage />} />
            <Route path="vendors" element={<CrmVendorsPage />} />
            <Route path="expenses" element={<CrmExpensesPage />} />
            <Route path="salary" element={<CrmSalaryPage />} />
            <Route path="leaves" element={<CrmLeavesPage />} />
            <Route path="tasks" element={<CrmTasksPage />} />
            <Route path="approvals" element={<CrmApprovalsPage />} />
            <Route path="newsletters" element={<CrmNewslettersPage />} />
            <Route path="cms" element={<CrmCmsPage />} />
            <Route path="user-log" element={<CrmUserLogPage />} />
            <Route path="reports" element={<CrmReportsPage />} />
            <Route path="workspace-fields" element={<CrmWorkspaceFieldsPage />} />
            <Route path="field-requests" element={<CrmFieldRequestsPage />} />
            <Route path="contests" element={<CrmContestsPage />} />
            <Route path="contests/:uuid" element={<CrmContestPlayPage />} />
            <Route path="invoices" element={<CrmInvoicesPage />} />
            <Route path="invoice-log" element={<CrmInvoiceLogPage />} />
            <Route path="recurring" element={<CrmRecurringPage />} />
            <Route path="commissions" element={<CrmCommissionsPage />} />
            <Route path="invoices/new" element={<CrmInvoiceFormPage />} />
            <Route path="invoices/:uuid" element={<CrmInvoiceViewPage />} />
            <Route path="invoices/:uuid/edit" element={<CrmInvoiceFormPage />} />
            <Route path="settings" element={<CrmSettingsPage />} />
            {/* Connect inside the CRM: the same pages as the personal side,
                same data, just wearing the company shell. */}
            <Route path="connect/connections" element={<ConnectionsPage />} />
            <Route path="connect/groups" element={<GroupsPage />} />
            <Route path="connect/messages" element={<MessagesPage />} />
            <Route path="connect/calls" element={<CallsPage />} />
            <Route path="connect/meetings" element={<MeetingsPage />} />
            <Route path="connect/screen" element={<ScreenPage />} />
            <Route path="connect/calendar" element={<CalendarPage />} />
            <Route path="pl" element={<CrmPlPage />} />
            <Route path="assets" element={<CrmAssetsPage />} />
            <Route path="churn" element={<CrmChurnPage />} />
            <Route path="communication" element={<CrmCommunicationPage />} />
            <Route path="organizations" element={<CrmOrganizationsPage />} />
          </Route>
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
    </PromptProvider>
    </ToastProvider>
    </ErrorBoundary>
  )
}
