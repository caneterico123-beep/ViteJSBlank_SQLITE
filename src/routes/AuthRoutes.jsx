import { LoaderCircle } from 'lucide-react'
import { Navigate, Outlet, useLocation, useOutletContext } from 'react-router-dom'
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
  return ['owner', 'admin'].includes(user?.role) ? <Outlet context={outletContext} /> : <Navigate to="/dashboard" replace />
}

export function InventoryRoute() {
  const { user, isLoading } = useAuth()
  const location = useLocation()
  if (isLoading) return <RouteLoading />
  if (user?.role === 'inventory_staff' && /\/inventory\/(products|suppliers)$/.test(location.pathname)) {
    return <Navigate to="/dashboard/inventory/stock" replace />
  }
  return ['owner', 'admin', 'manager', 'inventory_staff'].includes(user?.role)
    ? <Outlet />
    : <Navigate to="/dashboard" replace />
}

export function CashierRoute() {
  const { user, isLoading } = useAuth()
  if (isLoading) return <RouteLoading />
  const posIndustries = ['retail', 'grocery', 'pharmacy', 'bakery', 'restaurant', 'computer_store', 'coffee_shop']
  return (['cashier', 'owner', 'admin', 'manager'].includes(user?.role)
    || (user?.business?.industry === 'pharmacy' && user?.role === 'pharmacist'))
    && posIndustries.includes(user?.business?.industry)
    ? <Outlet />
    : <Navigate to="/dashboard" replace />
}

export function RetailRoute() {
  const { user, isLoading } = useAuth()
  if (isLoading) return <RouteLoading />
  return user?.business?.industry === 'retail'
    && ['owner', 'admin', 'manager', 'cashier', 'inventory_staff'].includes(user.role)
    ? <Outlet />
    : <Navigate to="/dashboard" replace />
}

export function PharmacyRoute() {
  const { user, isLoading } = useAuth()
  if (isLoading) return <RouteLoading />
  return user?.business?.industry === 'pharmacy'
    && ['owner', 'admin', 'manager', 'pharmacist', 'inventory_staff', 'cashier'].includes(user.role)
    ? <Outlet />
    : <Navigate to="/dashboard" replace />
}

export function PharmacyInventoryRoute() {
  const { user, isLoading } = useAuth()
  if (isLoading) return <RouteLoading />
  return user?.business?.industry === 'pharmacy'
    && ['owner', 'admin', 'manager', 'pharmacist', 'inventory_staff'].includes(user.role)
    ? <Outlet />
    : <Navigate to="/pharmacy/dashboard" replace />
}