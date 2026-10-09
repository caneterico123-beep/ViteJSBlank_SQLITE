import { useState } from 'react'
import { Check, Copy } from 'lucide-react'

function UsersTable({ users, loading, error, pagination, onPageChange, showPagination = true, title = 'Recent users', subtitle = 'Account directory' }) {
  const [copiedId, setCopiedId] = useState(null)

  function statusFor(user) {
    if (user.is_active) return { label: 'Active', active: true }
    if (user.last_login_at) return { label: 'Inactive', active: false }
    return { label: 'Never signed in', active: false }
  }

  async function copyEmail(user) {
    try {
      await navigator.clipboard.writeText(user.email)
      setCopiedId(user.id)
      window.setTimeout(() => setCopiedId(null), 1500)
    } catch {
      setCopiedId(null)
    }
  }

  return (
    <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
        <div>
          <h2 className="text-sm font-bold text-slate-900">{title}</h2>
          <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p>
        </div>
        {pagination && <span className="text-xs text-slate-500">{pagination.total} total</span>}
      </div>

      {error ? (
        <div className="px-5 py-8 text-center text-sm text-rose-700" role="alert">{error}</div>
      ) : loading ? (
        <div className="px-5 py-10 text-center text-sm text-slate-500" role="status">Loading users...</div>
      ) : users.length === 0 ? (
        <div className="px-5 py-10 text-center text-sm text-slate-500">No users found.</div>
      ) : (
        <>
          <div className="divide-y divide-slate-100 md:hidden">
            {users.map((user) => {
              const status = statusFor(user)
              return (
                <article key={user.id} className="flex items-start justify-between gap-3 px-4 py-4">
                <div className="flex min-w-0 items-start gap-3">
                  <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-700" aria-hidden="true">
                    {user.name.split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase()}
                  </span>
                  <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-slate-800">{user.name}</p>
                    <p className="break-all text-xs text-slate-500">{user.email}</p>
                    <p className="mt-1 text-[11px] text-slate-400">ID {user.id} · {new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(user.created_at))}</p>
                    <span className={`mt-2 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-semibold ${status.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>
                      <span className={`size-1.5 rounded-full ${status.active ? 'bg-emerald-500' : 'bg-slate-400'}`} aria-hidden="true" />
                      {status.label}
                    </span>
                  </div>
                </div>
                <button type="button" onClick={() => copyEmail(user)} className="shrink-0 rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label={`Copy email for ${user.name}`} title={copiedId === user.id ? 'Copied' : 'Copy email'}>
                  {copiedId === user.id ? <Check size={15} aria-hidden="true" /> : <Copy size={15} aria-hidden="true" />}
                </button>
                </article>
              )
            })}
          </div>
          <div className="hidden overflow-x-auto md:block">
            <table className="w-full min-w-[720px] border-collapse text-left">
              <thead className="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.7px] text-slate-500">
                <tr>
                  <th className="px-5 py-3">ID</th>
                  <th className="px-5 py-3">User</th>
                  <th className="px-5 py-3">Email address</th>
                  <th className="px-5 py-3">Registered</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {users.map((user) => {
                  const status = statusFor(user)
                  return (
                    <tr key={user.id} className="text-xs text-slate-600 hover:bg-slate-50/70">
                    <td className="px-5 py-3.5 tabular-nums text-slate-400">#{user.id}</td>
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-3">
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-700" aria-hidden="true">
                          {user.name.split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase()}
                        </span>
                        <span className="font-semibold text-slate-800">{user.name}</span>
                      </div>
                    </td>
                    <td className="px-5 py-3.5">{user.email}</td>
                    <td className="px-5 py-3.5">{new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(user.created_at))}</td>
                    <td className="px-5 py-3.5">
                      <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-semibold ${status.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>
                        <span className={`size-1.5 rounded-full ${status.active ? 'bg-emerald-500' : 'bg-slate-400'}`} aria-hidden="true" />
                        {status.label}
                      </span>
                    </td>
                    <td className="px-5 py-3.5 text-right">
                      <button type="button" onClick={() => copyEmail(user)} className="rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label={`Copy email for ${user.name}`} title={copiedId === user.id ? 'Copied' : 'Copy email'}>
                        {copiedId === user.id ? <Check size={15} aria-hidden="true" /> : <Copy size={15} aria-hidden="true" />}
                      </button>
                    </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {showPagination && pagination && (
            <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
              <span>Page {pagination.page} of {pagination.totalPages}</span>
              <div className="flex gap-2">
                <button type="button" disabled={pagination.page <= 1} onClick={() => onPageChange(pagination.page - 1)} className="rounded-md border border-slate-200 px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40">Previous</button>
                <button type="button" disabled={pagination.page >= pagination.totalPages} onClick={() => onPageChange(pagination.page + 1)} className="rounded-md border border-slate-200 px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40">Next</button>
              </div>
            </div>
          )}
        </>
      )}
    </section>
  )
}

export default UsersTable