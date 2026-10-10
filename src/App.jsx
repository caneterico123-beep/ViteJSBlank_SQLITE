import { lazy, Suspense } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './contexts/AuthContext.jsx'
import DashboardLayout from './components/DashboardLayout.jsx'
import { AdminRoute, CashierRoute, GuestRoute, InventoryRoute, PharmacyInventoryRoute, PharmacyRoute, ProtectedRoute, RetailRoute } from './routes/AuthRoutes.jsx'

const DashboardPage = lazy(() => import('./pages/DashboardPage.jsx'))
const InventoryManagementPage = lazy(() => import('./pages/InventoryManagementPage.jsx'))
const LoginPage = lazy(() => import('./pages/LoginPage.jsx'))
const NotFoundPage = lazy(() => import('./pages/NotFoundPage.jsx'))
const ProfilePage = lazy(() => import('./pages/ProfilePage.jsx'))
const RegisterPage = lazy(() => import('./pages/RegisterPage.jsx'))
const SettingsPage = lazy(() => import('./pages/SettingsPage.jsx'))
const UsersPage = lazy(() => import('./pages/UsersPage.jsx'))
const PosPage = lazy(() => import('./pages/PosPage.jsx'))
const TransactionsPage = lazy(() => import('./pages/TransactionsPage.jsx'))
const TransactionDetailPage = lazy(() => import('./pages/TransactionDetailPage.jsx'))
const RetailDashboardPage = lazy(() => import('./pages/RetailDashboardPage.jsx'))
const PharmacyDashboardPage = lazy(() => import('./pages/PharmacyDashboardPage.jsx'))
const PharmacyMedicinesPage = lazy(() => import('./pages/PharmacyMedicinesPage.jsx'))
const PharmacyBatchesPage = lazy(() => import('./pages/PharmacyBatchesPage.jsx'))

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
                <Route element={<InventoryRoute />}>
                  <Route path="inventory/*" element={<InventoryManagementPage />} />
                </Route>
              </Route>
              <Route element={<RetailRoute />}>
                <Route path="/retail/dashboard" element={<DashboardLayout />}>
                  <Route index element={<RetailDashboardPage />} />
                </Route>
              </Route>
              <Route element={<PharmacyRoute />}>
                <Route path="/pharmacy/dashboard" element={<DashboardLayout />}>
                  <Route index element={<PharmacyDashboardPage />} />
                </Route>
              </Route>
              <Route element={<PharmacyInventoryRoute />}>
                <Route path="/pharmacy/medicines" element={<DashboardLayout />}>
                  <Route index element={<PharmacyMedicinesPage />} />
                </Route>
                <Route path="/pharmacy/batches" element={<DashboardLayout />}>
                  <Route index element={<PharmacyBatchesPage />} />
                </Route>
              </Route>
              <Route element={<CashierRoute />}>
                <Route path="/pos" element={<DashboardLayout />}>
                  <Route index element={<PosPage />} />
                  <Route path="transactions" element={<TransactionsPage />} />
                  <Route path="transactions/:id" element={<TransactionDetailPage />} />
                </Route>
                <Route path="/retail/pos" element={<DashboardLayout />}>
                  <Route index element={<PosPage />} />
                </Route>
                <Route path="/retail/transactions" element={<DashboardLayout />}>
                  <Route index element={<TransactionsPage />} />
                  <Route path=":id" element={<TransactionDetailPage />} />
                </Route>
                <Route path="/pharmacy/pos" element={<DashboardLayout />}>
                  <Route index element={<PosPage />} />
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
