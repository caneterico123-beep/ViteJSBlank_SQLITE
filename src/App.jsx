import { lazy, Suspense } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './contexts/AuthContext.jsx'
import DashboardLayout from './components/DashboardLayout.jsx'
import { AdminRoute, GuestRoute, ProtectedRoute } from './routes/AuthRoutes.jsx'
import { CashierRoute } from './routes/AuthRoutes.jsx'

const DashboardPage = lazy(() => import('./pages/DashboardPage.jsx'))
const LoginPage = lazy(() => import('./pages/LoginPage.jsx'))
const NotFoundPage = lazy(() => import('./pages/NotFoundPage.jsx'))
const ProfilePage = lazy(() => import('./pages/ProfilePage.jsx'))
const RegisterPage = lazy(() => import('./pages/RegisterPage.jsx'))
const SettingsPage = lazy(() => import('./pages/SettingsPage.jsx'))
const UsersPage = lazy(() => import('./pages/UsersPage.jsx'))
const PosPage = lazy(() => import('./pages/PosPage.jsx'))
const TransactionsPage = lazy(() => import('./pages/TransactionsPage.jsx'))
const TransactionDetailPage = lazy(() => import('./pages/TransactionDetailPage.jsx'))

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Suspense fallback={<main className="login-background flex min-h-screen items-center justify-center text-sm text-slate-500" role="status">Loading workspace...</main>}>
          <Routes>
            <Route path="/" element={<Navigate to="/login" replace />} />
            <Route element={<GuestRoute />}>
              <Route path="/login" element={<LoginPage />} />
              <Route path="/register" element={<RegisterPage />} />
            </Route>
            <Route element={<ProtectedRoute />}>
              <Route path="/dashboard" element={<DashboardLayout />}>
                <Route index element={<DashboardPage />} />
                <Route path="profile" element={<ProfilePage />} />
                <Route path="settings" element={<SettingsPage />} />
                <Route element={<AdminRoute />}>
                  <Route path="users" element={<UsersPage />} />
                </Route>
              </Route>
              <Route element={<CashierRoute />}>
                <Route path="/pos" element={<DashboardLayout />}>
                  <Route index element={<PosPage />} />
                  <Route path="transactions" element={<TransactionsPage />} />
                  <Route path="transactions/:id" element={<TransactionDetailPage />} />
                </Route>
              </Route>
            </Route>
            <Route path="*" element={<NotFoundPage />} />
          </Routes>
        </Suspense>
      </AuthProvider>
    </BrowserRouter>
  )
}

export default App
