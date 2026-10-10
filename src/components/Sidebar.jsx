import {
  LayoutDashboard,
  Boxes,
  LogOut,
  PackageSearch,
  ReceiptText,
  Settings,
  ShieldUser,
  ShoppingCart,
  UserRound,
  X,
} from 'lucide-react'
import { useState } from 'react'
import { NavLink } from 'react-router-dom'

const baseLink = 'group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors'

function Sidebar({ user, open, onClose, onLogout, isLoggingOut, onSwitchBusiness }) {
  const [switchError, setSwitchError] = useState('')
  const isAdministrator = ['owner', 'admin'].includes(user.role)
  const supportsPos = ['retail', 'grocery', 'pharmacy', 'bakery', 'restaurant', 'computer_store', 'coffee_shop'].includes(user.business?.industry)
  const links = [
    { to: user.business?.industry === 'retail' ? '/retail/dashboard' : user.business?.industry === 'pharmacy' ? '/pharmacy/dashboard' : '/dashboard', label: user.business?.industry === 'retail' ? 'Retail dashboard' : user.business?.industry === 'pharmacy' ? 'Pharmacy dashboard' : 'Dashboard', icon: LayoutDashboard, end: true },
    ...(supportsPos && ['cashier', 'owner', 'admin', 'manager', 'pharmacist'].includes(user.role) ? [
      { to: user.business?.industry === 'retail' ? '/retail/pos' : user.business?.industry === 'pharmacy' ? '/pharmacy/pos' : '/pos', label: 'Point of Sale', icon: ShoppingCart, end: true },
      { to: user.business?.industry === 'retail' ? '/retail/transactions' : '/pos/transactions', label: 'Sales history', icon: ReceiptText },
    ] : []),
    ...(['owner', 'admin', 'manager', 'inventory_staff', 'pharmacist'].includes(user.role) ? [{
      to: user.business?.industry === 'pharmacy' ? '/pharmacy/medicines' : user.role === 'inventory_staff' ? '/dashboard/inventory/stock' : '/dashboard/inventory/products',
      label: user.business?.industry === 'pharmacy' ? 'Medicines' : 'Inventory',
      icon: PackageSearch,
    }] : []),
    ...(user.business?.industry === 'pharmacy' && ['owner', 'admin', 'manager', 'pharmacist', 'inventory_staff'].includes(user.role)
      ? [{ to: '/pharmacy/batches', label: 'Medicine batches', icon: Boxes }]
      : []),
    { to: '/dashboard/profile', label: 'My Profile', icon: UserRound },
    ...(isAdministrator ? [{ to: '/dashboard/users', label: 'Users', icon: ShieldUser }] : []),
    { to: '/dashboard/settings', label: 'Settings', icon: Settings },
  ]

  return (
    <>
      {open && <button type="button" aria-label="Close navigation" onClick={onClose} className="fixed inset-0 z-30 bg-slate-950/35 lg:hidden" />}
      <aside className={`fixed inset-y-0 left-0 z-40 flex w-[260px] flex-col border-r border-slate-200 bg-white transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 ${open ? 'translate-x-0' : '-translate-x-full'}`}>
        <div className="flex h-[72px] items-center justify-between border-b border-slate-100 px-5">
          <a href="/dashboard" className="flex items-center gap-3 text-slate-900 no-underline">
            <span className="brand-mark flex size-9 items-center justify-center rounded-xl text-white shadow-sm">
              <LayoutDashboard size={18} aria-hidden="true" />
            </span>
            <span className="text-[17px] font-extrabold tracking-[-0.5px]">Northstar</span>
          </a>
          <button type="button" className="rounded-md p-2 text-slate-500 hover:bg-slate-100 lg:hidden" onClick={onClose} aria-label="Close navigation">
            <X size={18} aria-hidden="true" />
          </button>
        </div>

        <div className="px-4 pt-6">
          <p className="mb-3 px-3 text-[10px] font-bold uppercase tracking-[1px] text-slate-400">Workspace</p>
          {user.businesses?.length > 1 && (
            <div className="mb-3 px-2">
              <label htmlFor="business-switcher" className="sr-only">Select business workspace</label>
              <select
                id="business-switcher"
                value={user.business_id}
                onChange={async (event) => {
                  setSwitchError('')
                  try {
                    await onSwitchBusiness(Number(event.target.value))
                  } catch (error) {
                    setSwitchError(error.message || 'Could not switch business workspace.')
                  }
                }}
                className="min-h-10 w-full rounded-lg border border-slate-200 bg-white px-2 text-xs font-semibold text-slate-700"
              >
                {user.businesses.map((business) => <option key={business.id} value={business.id}>{business.name}</option>)}
              </select>
              {switchError && <p className="mt-1 text-xs text-rose-700" role="alert">{switchError}</p>}
            </div>
          )}
          <nav aria-label="Main navigation" className="space-y-1">
            {links.map(({ to, label, icon: Icon, end }) => (
              <NavLink
                key={to}
                to={to}
                end={end}
                onClick={onClose}
                className={({ isActive }) => `${baseLink} ${isActive ? 'bg-orange-50 text-orange-800' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950'}`}
              >
                <Icon size={18} className="shrink-0" aria-hidden="true" />
                <span>{label}</span>
              </NavLink>
            ))}
          </nav>
        </div>

        <div className="mt-auto border-t border-slate-100 p-4">
          <div className="mb-3 flex min-w-0 items-center gap-3 px-2 py-1">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-orange-100 text-xs font-bold text-orange-800" aria-hidden="true">
              {user.name.split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase()}
            </span>
            <div className="min-w-0">
              <p className="truncate text-xs font-semibold text-slate-800">{user.name}</p>
              <p className="truncate text-[11px] text-slate-500">{user.business?.name || 'Workspace'} · {user.role.replaceAll('_', ' ')}</p>
            </div>
          </div>
          <button type="button" onClick={onLogout} disabled={isLoggingOut} className={`${baseLink} w-full text-slate-500 hover:bg-slate-50 hover:text-slate-900 disabled:opacity-60`}>
            <LogOut size={18} aria-hidden="true" />
            <span>{isLoggingOut ? 'Signing out...' : 'Logout'}</span>
          </button>
        </div>
      </aside>
    </>
  )
}

export default Sidebar