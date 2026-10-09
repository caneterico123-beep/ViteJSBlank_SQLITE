import { useEffect, useState } from 'react'
import { Activity, BadgeCheck, UserPlus, UsersRound } from 'lucide-react'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import StatCard from '../components/StatCard.jsx'
import UsersTable from '../components/UsersTable.jsx'
import { useAuth } from '../contexts/useAuth.js'
import { getDashboardStats, getRegistrationAnalytics, getUsers } from '../services/api.js'

function DashboardPage() {
  const { user } = useAuth()
  const [stats, setStats] = useState(null)
  const [registrations, setRegistrations] = useState([])
  const [recentUsers, setRecentUsers] = useState([])
  const [isLoading, setIsLoading] = useState(user.role === 'admin')
  const [error, setError] = useState('')

  useEffect(() => {
    if (user.role !== 'admin') return undefined
    let active = true
    Promise.all([getDashboardStats(), getRegistrationAnalytics(), getUsers({ page: 1, perPage: 5 })])
      .then(([summary, analytics, directory]) => {
        if (!active) return
        setStats(summary.stats)
        setRegistrations(analytics.registrations)
        setRecentUsers(directory.users)
      })
      .catch((loadError) => {
        if (active) setError(loadError.message || 'Could not load dashboard data.')
      })
      .finally(() => {
        if (active) setIsLoading(false)
      })
    return () => {
      active = false
    }
  }, [user.role])

  return (
    <div className="space-y-6">
      <section className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div>
          <p className="mb-1 text-xs font-bold uppercase tracking-[1px] text-orange-700">Overview</p>
          <h2 className="text-2xl font-bold text-slate-950">Welcome, {user.name}</h2>
          <p className="mt-1 text-sm text-slate-500">{user.role === 'admin' ? 'Here is what is happening across your workspace.' : 'Your account and workspace at a glance.'}</p>
        </div>
        <p className="text-xs text-slate-500">{user.role === 'admin' ? 'Live data from your database' : 'Signed in as a member'}</p>
      </section>

      {error && <p className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{error}</p>}

      {user.role === 'admin' ? (
        <>
          {isLoading && !stats ? (
            <div className="rounded-xl border border-slate-200 bg-white px-5 py-8 text-sm text-slate-500" role="status">Loading dashboard statistics...</div>
          ) : stats && (
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard label="Total users" value={stats.totalUsers.toLocaleString()} helper="Registered accounts" icon={UsersRound} tone="orange" />
              <StatCard label="Active users" value={stats.activeUsers.toLocaleString()} helper="Signed in within 30 days" icon={Activity} tone="emerald" />
              <StatCard label="New registrations" value={stats.newRegistrations.toLocaleString()} helper="Last 7 days" icon={UserPlus} tone="sky" />
              <StatCard label="Account status" value={stats.accountStatus} helper="Administrator account" icon={BadgeCheck} tone="slate" />
            </div>
          )}

          <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div className="mb-5 flex flex-wrap items-end justify-between gap-2">
              <div>
                <h2 className="text-sm font-bold text-slate-900">Registration analytics</h2>
                <p className="mt-1 text-xs text-slate-500">New accounts by day, last 30 days</p>
              </div>
              <span className="text-[11px] text-slate-400">Source: users table</span>
            </div>
            <div className="h-[260px] w-full" aria-label="User registrations over the last 30 days">
              <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={registrations} margin={{ top: 8, right: 8, left: -20, bottom: 0 }}>
                  <defs>
                    <linearGradient id="registrationFill" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="#e96b4c" stopOpacity={0.2} />
                      <stop offset="95%" stopColor="#e96b4c" stopOpacity={0.015} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid stroke="#edf0f2" vertical={false} />
                  <XAxis dataKey="label" tick={{ fill: '#84909f', fontSize: 10 }} tickLine={false} axisLine={false} minTickGap={24} />
                  <YAxis allowDecimals={false} tick={{ fill: '#84909f', fontSize: 10 }} tickLine={false} axisLine={false} />
                  <Tooltip contentStyle={{ border: '1px solid #e2e8f0', borderRadius: 10, fontSize: 12 }} formatter={(value) => [value, 'Registrations']} />
                  <Area type="monotone" dataKey="registrations" stroke="#e96b4c" strokeWidth={2} fill="url(#registrationFill)" activeDot={{ r: 4, strokeWidth: 0 }} />
                </AreaChart>
              </ResponsiveContainer>
            </div>
          </section>

          <UsersTable users={recentUsers} loading={isLoading} error={error} showPagination={false} title="Recent users" subtitle="Latest accounts added to the workspace" />
        </>
      ) : (
        <section className="max-w-2xl rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
          <h2 className="text-sm font-bold text-slate-900">Your account</h2>
          <p className="mt-1 text-xs text-slate-500">Personal workspace access</p>
          <dl className="mt-5 grid gap-5 border-t border-slate-100 pt-5 sm:grid-cols-2">
            <div><dt className="text-xs font-semibold text-slate-500">Full name</dt><dd className="mt-1 text-sm font-semibold text-slate-900">{user.name}</dd></div>
            <div><dt className="text-xs font-semibold text-slate-500">Email address</dt><dd className="mt-1 break-all text-sm font-semibold text-slate-900">{user.email}</dd></div>
            <div><dt className="text-xs font-semibold text-slate-500">Role</dt><dd className="mt-1 text-sm font-semibold capitalize text-slate-900">{user.role}</dd></div>
            <div><dt className="text-xs font-semibold text-slate-500">Member since</dt><dd className="mt-1 text-sm font-semibold text-slate-900">{new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(user.created_at))}</dd></div>
          </dl>
        </section>
      )}
    </div>
  )
}

export default DashboardPage