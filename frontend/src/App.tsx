import { BrowserRouter, Route, Routes } from 'react-router-dom'
import Layout from './components/Layout'
import { RequireAdmin, RequireAuth } from './components/Protected'
import Login from './pages/auth/Login'
import Register from './pages/auth/Register'
import ForgotPassword from './pages/auth/ForgotPassword'
import ResetPassword from './pages/auth/ResetPassword'
import Dashboard from './pages/Dashboard'
import TasksPage from './pages/TasksPage'
import CalendarPage from './pages/CalendarPage'
import CategoriesPage from './pages/CategoriesPage'
import ConnectionsPage from './pages/ConnectionsPage'
import NotesPage from './pages/NotesPage'
import FilesPage from './pages/FilesPage'
import GroupsPage from './pages/GroupsPage'
import ReportsPage from './pages/ReportsPage'
import SettingsPage from './pages/SettingsPage'
import AdminPage from './pages/admin/AdminPage'

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password" element={<ResetPassword />} />
        <Route
          element={
            <RequireAuth>
              <Layout />
            </RequireAuth>
          }
        >
          <Route path="/" element={<Dashboard />} />
          <Route path="/tasks" element={<TasksPage />} />
          <Route path="/calendar" element={<CalendarPage />} />
          <Route path="/categories" element={<CategoriesPage />} />
          <Route path="/connections" element={<ConnectionsPage />} />
          <Route path="/notes" element={<NotesPage />} />
          <Route path="/files" element={<FilesPage />} />
          <Route path="/groups" element={<GroupsPage />} />
          <Route path="/reports" element={<ReportsPage />} />
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
    </BrowserRouter>
  )
}
