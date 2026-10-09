import { useState } from 'react'
import { Command, LogOut, ShieldCheck } from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import LoadingButton from '../components/LoadingButton.jsx'
import { useAuth } from '../contexts/useAuth.js'

function DashboardPage() {
  const { user, signOut } = useAuth()
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [error, setError] = useState('')
  const navigate = useNavigate()
  const createdAt = new Date(user.created_at)
  const joinedDate = Number.isNaN(createdAt.getTime())
    ? 'Recently'
    : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(createdAt)

  async function handleLogout() {
    setIsSubmitting(true)
    setError('')
    try {
      await signOut()
      navigate('/login', { replace: true })
    } catch (logoutError) {
      setError(logoutError.message || 'Could not sign out. Please try again.')
      setIsSubmitting(false)
    }
  }

  return (
    <main className="login-background relative flex min-h-screen flex-col px-5 py-8 sm:px-8">
      <header className="relative z-10 mx-auto flex w-full max-w-5xl items-center justify-between">
        <Link to="/dashboard" className="flex items-center gap-3 text-slate-900 no-underline">
          <span className="brand-mark flex size-10 items-center justify-center rounded-[13px] text-white shadow-lg shadow-orange-900/15">
            <Command size={21} strokeWidth={2.4} aria-hidden="true" />
          </span>
          <span className="text-[20px] font-extrabold tracking-[-0.7px]">Northstar</span>
        </Link>
        <button
          type="button"
          onClick={handleLogout}
          disabled={isSubmitting}
          className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600 disabled:opacity-60"
        >
          <LogOut size={16} aria-hidden="true" />
          Sign out
        </button>
      </header>

      <section className="relative z-10 mx-auto my-auto w-full max-w-5xl py-12">
        <div className="mb-8">
          <p className="mb-2 text-xs font-bold uppercase tracking-[1.1px] text-orange-700">Your workspace</p>
          <h1 className="text-3xl font-bold leading-tight text-slate-950 sm:text-4xl">Welcome, {user.name}</h1>
          <p className="mt-2 text-sm text-slate-500">You are signed in to Northstar.</p>
        </div>

        <div className="login-card max-w-xl rounded-[18px] border border-white/80 bg-white/90 p-6 shadow-xl shadow-slate-900/[0.06] backdrop-blur-xl sm:p-8">
          <div className="mb-6 flex items-center gap-3">
            <span className="flex size-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
              <ShieldCheck size={20} aria-hidden="true" />
            </span>
            <div>
              <h2 className="text-base font-bold text-slate-900">Account overview</h2>
              <p className="text-xs text-slate-500">Your authenticated profile</p>
            </div>
          </div>
          <dl className="grid gap-5 border-t border-slate-100 pt-5 sm:grid-cols-2">
            <div>
              <dt className="text-xs font-semibold text-slate-500">Full name</dt>
              <dd className="mt-1 text-sm font-semibold text-slate-900">{user.name}</dd>
            </div>
            <div>
              <dt className="text-xs font-semibold text-slate-500">Email address</dt>
              <dd className="mt-1 break-all text-sm font-semibold text-slate-900">{user.email}</dd>
            </div>
            <div>
              <dt className="text-xs font-semibold text-slate-500">Member since</dt>
              <dd className="mt-1 text-sm font-semibold text-slate-900">{joinedDate}</dd>
            </div>
          </dl>
        </div>

        {error && <p className="mt-4 max-w-xl rounded-lg border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-900" role="alert">{error}</p>}
        {isSubmitting && <div className="mt-4 max-w-xl"><LoadingButton loading loadingText="Signing out...">Sign out</LoadingButton></div>}
      </section>
    </main>
  )
}

export default DashboardPage