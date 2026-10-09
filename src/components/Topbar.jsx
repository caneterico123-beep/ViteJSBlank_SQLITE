import { useState } from 'react'
import { Bell, ChevronDown, LogOut, Menu, Search, Settings, UserRound } from 'lucide-react'
import { Link } from 'react-router-dom'

function Topbar({ title, user, search, onSearch, showSearch, onMenu, onLogout, isLoggingOut }) {
  const [menuOpen, setMenuOpen] = useState(false)
  const [notificationsOpen, setNotificationsOpen] = useState(false)
  const initials = user.name.split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase()

  return (
    <header className="sticky top-0 z-20 flex min-h-[72px] items-center gap-3 border-b border-slate-200 bg-white/95 px-4 backdrop-blur sm:px-6">
      <button type="button" onClick={onMenu} className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" aria-label="Open navigation">
        <Menu size={19} aria-hidden="true" />
      </button>
      <h1 className="shrink-0 text-lg font-bold text-slate-950 sm:text-xl">{title}</h1>

      {showSearch && (
        <label className="ml-auto hidden h-10 w-full max-w-[300px] items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 text-slate-400 sm:flex">
          <Search size={16} aria-hidden="true" />
          <span className="sr-only">Search users</span>
          <input value={search} onChange={(event) => onSearch(event.target.value)} placeholder="Search users..." className="min-w-0 flex-1 bg-transparent text-sm text-slate-800 outline-none placeholder:text-slate-400" />
        </label>
      )}

      <div className={`relative flex items-center gap-1 ${showSearch ? 'ml-0' : 'ml-auto'}`}>
        <button type="button" onClick={() => setNotificationsOpen((open) => !open)} className="relative rounded-lg p-2.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800" aria-label="Notifications" aria-expanded={notificationsOpen}>
          <Bell size={18} aria-hidden="true" />
          <span className="absolute right-2 top-2 size-1.5 rounded-full bg-orange-500" aria-hidden="true" />
        </button>
        {notificationsOpen && <div className="absolute right-14 top-12 w-60 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600 shadow-xl" role="status">You&apos;re all caught up.</div>}

        <div className="relative">
          <button type="button" onClick={() => setMenuOpen((open) => !open)} className="flex items-center gap-2 rounded-lg p-1.5 text-left hover:bg-slate-50" aria-expanded={menuOpen} aria-label="Open user menu">
            <span className="flex size-8 items-center justify-center rounded-full bg-orange-100 text-[11px] font-bold text-orange-800" aria-hidden="true">{initials}</span>
            <span className="hidden max-w-36 truncate text-xs font-semibold text-slate-700 md:block">{user.name}</span>
            <ChevronDown size={15} className="hidden text-slate-400 md:block" aria-hidden="true" />
          </button>
          {menuOpen && (
            <div className="absolute right-0 top-12 w-48 rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl">
              <Link onClick={() => setMenuOpen(false)} to="/dashboard/profile" className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-700 no-underline hover:bg-slate-50"><UserRound size={16} aria-hidden="true" />Profile</Link>
              <Link onClick={() => setMenuOpen(false)} to="/dashboard/settings" className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-700 no-underline hover:bg-slate-50"><Settings size={16} aria-hidden="true" />Settings</Link>
              <button type="button" disabled={isLoggingOut} onClick={onLogout} className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-60"><LogOut size={16} aria-hidden="true" />Logout</button>
            </div>
          )}
        </div>
      </div>
    </header>
  )
}

export default Topbar