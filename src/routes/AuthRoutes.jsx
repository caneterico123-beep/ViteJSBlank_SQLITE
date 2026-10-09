import { LoaderCircle } from 'lucide-react'
import { Navigate, Outlet } from 'react-router-dom'
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