import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Search } from 'lucide-react'
import { getTransactions } from '../services/api.js'
import { formatMoney } from '../utils/money.js'
import { useAuth } from '../contexts/useAuth.js'

function TransactionsPage() {
  const { user } = useAuth()
  const isBusinessAdmin = ['owner', 'admin', 'manager'].includes(user.role)
  const [filters, setFilters] = useState({ search: '', dateFrom: '', dateTo: '', status: '', paymentMethod: '' })
  const [page, setPage] = useState(1)
  const [result, setResult] = useState({ transactions: [], pagination: { totalPages: 1, total: 0 } })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      setLoading(true)
      setError('')
      getTransactions({ ...filters, page, perPage: 20 })
        .then((data) => { if (active) setResult(data) })
        .catch((loadError) => { if (active) setError(loadError.message || 'Could not load sales history.') })
        .finally(() => { if (active) setLoading(false) })
    }, 150)
    return () => { active = false; window.clearTimeout(timer) }
  }, [filters, page])

  function updateFilter(field, value) {
    setFilters((current) => ({ ...current, [field]: value }))
    setPage(1)
  }

  return (
    <section className="space-y-5">
      <header>
        <h1 className="text-xl font-bold text-slate-950">Sales history</h1>
        <p className="mt-1 text-sm text-slate-500">{isBusinessAdmin ? 'All POS transactions' : 'Your POS transactions'} · {result.pagination.total} total</p>
      </header>
      <div className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 xl:grid-cols-5">
        <label className="relative sm:col-span-2 xl:col-span-1">
          <span className="sr-only">Search transactions</span>
          <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true" />
          <input value={filters.search} onChange={(event) => updateFilter('search', event.target.value)} placeholder="Search transaction…" className="h-11 w-full rounded-lg border border-slate-200 pl-9 pr-3 text-sm outline-none focus:border-orange-500" />
        </label>
        <label className="text-xs font-medium text-slate-600">From<input type="date" value={filters.dateFrom} onChange={(event) => updateFilter('dateFrom', event.target.value)} className="mt-1 block h-11 w-full rounded-lg border border-slate-200 px-3 text-sm" /></label>
        <label className="text-xs font-medium text-slate-600">To<input type="date" value={filters.dateTo} onChange={(event) => updateFilter('dateTo', event.target.value)} className="mt-1 block h-11 w-full rounded-lg border border-slate-200 px-3 text-sm" /></label>
        <label className="text-xs font-medium text-slate-600">Payment status<select value={filters.status} onChange={(event) => updateFilter('status', event.target.value)} className="mt-1 block h-11 w-full rounded-lg border border-slate-200 px-3 text-sm"><option value="">All statuses</option><option value="paid">Paid</option><option value="pending">Pending</option><option value="voided">Voided</option><option value="cancelled">Cancelled</option></select></label>
        <label className="text-xs font-medium text-slate-600">Payment method<select value={filters.paymentMethod} onChange={(event) => updateFilter('paymentMethod', event.target.value)} className="mt-1 block h-11 w-full rounded-lg border border-slate-200 px-3 text-sm"><option value="">All methods</option><option value="cash">Cash</option><option value="card">Card</option><option value="ewallet">E-wallet</option></select></label>
      </div>

      {error && <p className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800" role="alert">{error}</p>}
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        {loading ? <p className="p-10 text-center text-sm text-slate-500" role="status">Loading transactions…</p> : result.transactions.length === 0 ? <p className="p-10 text-center text-sm text-slate-500">No transactions match these filters.</p> : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[700px] text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-3 font-semibold">Transaction</th><th className="px-4 py-3 font-semibold">Date</th>{isBusinessAdmin && <th className="px-4 py-3 font-semibold">Cashier</th>}<th className="px-4 py-3 font-semibold">Payment</th><th className="px-4 py-3 font-semibold">Status</th><th className="px-4 py-3 text-right font-semibold">Total</th><th className="px-4 py-3" /></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {result.transactions.map((sale) => <tr key={sale.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3"><Link to={`/pos/transactions/${sale.id}`} className="font-semibold text-orange-800 hover:underline">{sale.transaction_number}</Link></td>
                  <td className="whitespace-nowrap px-4 py-3 text-slate-600">{new Date(`${sale.created_at.replace(' ', 'T')}Z`).toLocaleString()}</td>
                  {isBusinessAdmin && <td className="px-4 py-3 text-slate-600">{sale.cashier_name}</td>}
                  <td className="px-4 py-3 capitalize text-slate-600">{sale.payment_method}</td>
                  <td className="px-4 py-3"><span className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ${sale.payment_status === 'paid' ? 'bg-emerald-50 text-emerald-800' : sale.payment_status === 'pending' ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600'}`}>{sale.payment_status}</span></td>
                  <td className="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">{formatMoney(sale.total_cents)}</td>
                  <td className="px-4 py-3 text-right"><Link to={`/pos/transactions/${sale.id}`} className="inline-flex min-h-10 items-center rounded-lg px-3 text-xs font-semibold text-slate-700 hover:bg-slate-100">Details</Link></td>
                </tr>)}
              </tbody>
            </table>
          </div>
        )}
        <footer className="flex items-center justify-between border-t border-slate-100 px-4 py-3">
          <span className="text-xs text-slate-500">Page {result.pagination.page || page} of {result.pagination.totalPages || 1}</span>
          <div className="flex gap-2"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((current) => Math.max(1, current - 1))} className="min-h-10 rounded-lg border border-slate-200 px-3 text-xs font-semibold disabled:opacity-40">Previous</button><button type="button" disabled={page >= (result.pagination.totalPages || 1) || loading} onClick={() => setPage((current) => current + 1)} className="min-h-10 rounded-lg border border-slate-200 px-3 text-xs font-semibold disabled:opacity-40">Next</button></div>
        </footer>
      </div>
    </section>
  )
}

export default TransactionsPage
