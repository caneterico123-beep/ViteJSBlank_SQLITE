import { LoaderCircle } from 'lucide-react'
import { Navigate, Outlet, useOutletContext } from 'react-router-dom'
import { useAuth } from '../contexts/useAuth.js'

function RouteLoading() {
  return (
    <main className="login-background flex min-h-screen items-center justify-center" aria-label="Loading session">
      <LoaderCircle className="animate-spin text-orange-600" size={28} aria-hidden="true" />
    </main>
  )
}

export function ProtectedRoute() {
  const { user, isLoading } = useAuth()
  if (isLoading) return <RouteLoading />
  return user ? <Outlet /> : <Navigate to="/login" replace />
}

export function GuestRoute() {
  const { user, isLoading } = useAuth()
  if (isLoading) return <RouteLoading />
  return user ? <Navigate to="/dashboard" replace /> : <Outlet />
}

export function AdminRoute() {
  const { user, isLoading } = useAuth()
  const outletContext = useOutletContext()
  if (isLoading) return <RouteLoading />
  return user?.role === 'admin' ? <Outlet context={outletContext} /> : <Navigate to="/dashboard" replace />
}

export function CashierRoute() {
  const { user, isLoading } = useAuth()
  if (isLoading) return <RouteLoading />
  return ['cashier', 'admin'].includes(user?.role) ? <Outlet /> : <Navigate to="/dashboard" replace />
}