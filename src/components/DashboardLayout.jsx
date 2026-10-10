import { useState } from 'react'
import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../contexts/useAuth.js'
import Sidebar from './Sidebar.jsx'
import Topbar from './Topbar.jsx'

const titles = {
  '/dashboard': 'Dashboard',
  '/retail/dashboard': 'Retail dashboard',
  '/dashboard/profile': 'My Profile',
  '/dashboard/users': 'Users',
  '/dashboard/inventory/products': 'Inventory',
  '/dashboard/inventory/stock': 'Stock operations',
  '/dashboard/inventory/suppliers': 'Suppliers',
  '/dashboard/inventory/history': 'Stock history',
  '/dashboard/settings': 'Settings',
  '/pos': 'Point of Sale',
  '/pos/transactions': 'Sales history',
  '/retail/pos': 'Point of Sale',
  '/retail/transactions': 'Sales history',
  '/pharmacy/dashboard': 'Pharmacy dashboard',
  '/pharmacy/medicines': 'Medicine catalog',
  '/pharmacy/batches': 'Medicine batches',
  '/pharmacy/pos': 'Pharmacy counter POS',
}

function DashboardLayout() {
  const { user, signOut, switchBusiness } = useAuth()
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [isLoggingOut, setIsLoggingOut] = useState(false)
  const [logoutError, setLogoutError] = useState('')
  const location = useLocation()
  const navigate = useNavigate()
  const showSearch = location.pathname === '/dashboard/users'

  async function handleLogout() {
    setIsLoggingOut(true)
    setLogoutError('')
    try {
      await signOut()
      navigate('/login', { replace: true })
    } catch (error) {
      setLogoutError(error.message || 'Could not sign out. Please try again.')
      setIsLoggingOut(false)
    }
  }

  return (
    <div key={user.business_id} className="min-h-screen bg-[#f6f8f8] text-slate-900 lg:flex">
      <Sidebar user={user} onSwitchBusiness={switchBusiness} open={mobileNavOpen} onClose={() => setMobileNavOpen(false)} onLogout={handleLogout} isLoggingOut={isLoggingOut} />
      <div className="min-w-0 flex-1">
        <Topbar
          title={titles[location.pathname] || (location.pathname.startsWith('/pos/transactions/') || location.pathname.startsWith('/retail/transactions/') ? 'Transaction details' : 'Dashboard')}
          user={user}
          search={search}
          onSearch={setSearch}
          showSearch={showSearch}
          onMenu={() => setMobileNavOpen(true)}
          onLogout={handleLogout}
          isLoggingOut={isLoggingOut}
        />
        <main className="mx-auto w-full max-w-[1500px] p-4 sm:p-6 lg:p-8">
          {logoutError && <p className="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{logoutError}</p>}
          <Outlet context={{ search, setSearch }} />
        </main>
      </div>
    </div>
  )
}

export default DashboardLayout