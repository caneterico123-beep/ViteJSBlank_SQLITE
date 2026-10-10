import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { AlertTriangle, Banknote, Boxes, ClipboardList, Package, Pill, ShoppingCart } from 'lucide-react'
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
import { useAuth } from '../contexts/useAuth.js'
import { getPharmacyDashboard } from '../services/api.js'
import { formatMoney } from '../utils/money.js'

function PharmacyDashboardPage() {
  const { user } = useAuth()
  const currency = user.business?.currency || 'PHP'
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [appliedRange, setAppliedRange] = useState({ dateFrom: '', dateTo: '' })
  const [dashboard, setDashboard] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    getPharmacyDashboard(appliedRange)
      .then((result) => {
        if (active) setDashboard(result)
      })
      .catch((loadError) => {
        if (active) setError(loadError.message || 'Could not load pharmacy dashboard data.')
      })
      .finally(() => {
        if (active) setIsLoading(false)
      })
    return () => { active = false }
  }, [appliedRange])

  function applyRange(event) {
    event.preventDefault()
    setIsLoading(true)
    setError('')
    setAppliedRange({ dateFrom, dateTo })
  }

  function formatDate(value) {
    if (!value) return '—'
    return new Intl.DateTimeFormat(undefined, {
      dateStyle: 'medium',
      timeStyle: 'short',
      timeZone: dashboard?.timezone || 'UTC',
    }).format(new Date(`${value.replace(' ', 'T')}Z`))
  }

  const metrics = dashboard?.metrics

  return (
    <div className="space-y-6">
      <section className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
        <div>
          <p className="mb-1 text-xs font-bold uppercase tracking-[1px] text-emerald-700">Pharmacy overview</p>
          <h1 className="text-2xl font-bold text-slate-950">{user.business?.name || 'Pharmacy'}</h1>
          <p className="mt-1 text-sm text-slate-500">Live medicine inventory, batch expiry, sales and stock activity.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to="/pharmacy/pos" className="inline-flex min-h-10 items-center gap-2 rounded-lg bg-emerald-700 px-4 text-sm font-semibold text-white hover:bg-emerald-800">
            <ShoppingCart size={16} aria-hidden="true" /> Counter POS
          </Link>
          <Link to="/pharmacy/batches" className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <Boxes size={16} aria-hidden="true" /> Receive batches
          </Link>
          <Link to="/pharmacy/medicines" className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <Pill size={16} aria-hidden="true" /> Medicine catalog
          </Link>
        </div>
      </section>

      <form onSubmit={applyRange} className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:flex-wrap sm:items-end">
        <div>
          <label htmlFor="pharmacy-date-from" className="mb-1.5 block text-xs font-semibold text-slate-600">Sales from</label>
          <input id="pharmacy-date-from" type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} className="min-h-10 rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100" />
        </div>
        <div>
          <label htmlFor="pharmacy-date-to" className="mb-1.5 block text-xs font-semibold text-slate-600">Sales to</label>
          <input id="pharmacy-date-to" type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} className="min-h-10 rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100" />
        </div>
        <button type="submit" disabled={isLoading} className="min-h-10 rounded-lg border border-slate-200 bg-slate-50 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-60">Apply dates</button>
        <p className="text-xs text-slate-500 sm:ml-auto sm:self-center">Sales period: {dashboard?.date_range?.from || '…'} – {dashboard?.date_range?.to || '…'} · {dashboard?.timezone || 'business timezone'}</p>
      </form>

      {error && <p className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{error}</p>}
      {isLoading && !dashboard && <p className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500" role="status">Loading pharmacy data…</p>}

      {metrics && (
        <>
          <section className="space-y-3">
            <h2 className="text-sm font-bold text-slate-900">Medicine inventory</h2>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard label="Medicines" value={metrics.medicine_count.toLocaleString()} helper="Active products configured for pharmacy" icon={Pill} tone="sky" />
              <StatCard label="Medicines in stock" value={metrics.in_stock_count.toLocaleString()} helper="Have eligible, unexpired batch stock" icon={Package} tone="emerald" />
              <StatCard label="Low stock" value={metrics.low_stock_count.toLocaleString()} helper="Eligible quantity at or below reorder level" icon={AlertTriangle} tone="orange" />
              <StatCard label="Out of stock" value={metrics.out_of_stock_count.toLocaleString()} helper="No eligible batch quantity available" icon={Boxes} tone="slate" />
              <StatCard label="Expired batches" value={metrics.expired_batch_count.toLocaleString()} helper={`${metrics.expired_quantity.toLocaleString()} units require review`} icon={AlertTriangle} tone="orange" />
              <StatCard label="Batches expiring ≤90 days" value={metrics.expiring_batch_count_90_days.toLocaleString()} helper={`${metrics.expiring_quantity_90_days.toLocaleString()} units in stock`} icon={ClipboardList} tone="slate" />
              <StatCard label="Today's sales" value={formatMoney(metrics.today_net_sales_cents, currency)} helper={`${metrics.today_transactions.toLocaleString()} paid transactions · after discounts, before tax`} icon={Banknote} tone="emerald" />
              <StatCard label="Inventory value" value={formatMoney(metrics.inventory_value_cents, currency)} helper="Eligible stock at recorded product cost" icon={Banknote} tone="sky" />
            </div>
          </section>

          {dashboard.not_implemented_modules.length > 0 && (
            <section className="rounded-xl border border-amber-200 bg-amber-50 p-4">
              <h2 className="text-sm font-bold text-amber-950">Pharmacy workflows still being built</h2>
              <p className="mt-1 text-xs leading-5 text-amber-900">Prescription intake and dispensing, purchase orders, returns/refunds, and validated clinical interaction checking are not available yet. Prescription-required medicines are blocked at checkout. No prescription or dispensing counts are presented as if they were available.</p>
            </section>
          )}

          <section className="grid gap-4 xl:grid-cols-2">
            <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
              <h2 className="text-sm font-bold text-slate-900">Sales trend</h2>
              <p className="mb-4 mt-1 text-xs text-slate-500">Paid sales after discounts and before tax.</p>
              <div className="h-[270px]" aria-label="Pharmacy sales trend chart">
                <ResponsiveContainer width="100%" height="100%">
                  <AreaChart data={dashboard.sales_trend} margin={{ top: 8, right: 8, left: 4, bottom: 0 }}>
                    <defs>
                      <linearGradient id="pharmacySalesFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="#398b74" stopOpacity={0.2} />
                        <stop offset="95%" stopColor="#398b74" stopOpacity={0.015} />
                      </linearGradient>
                    </defs>
                    <CartesianGrid stroke="#edf0f2" vertical={false} />
                    <XAxis dataKey="label" tick={{ fill: '#84909f', fontSize: 10 }} tickLine={false} axisLine={false} minTickGap={24} />
                    <YAxis tick={{ fill: '#84909f', fontSize: 10 }} tickLine={false} axisLine={false} tickFormatter={(value) => formatMoney(value, currency)} width={78} />
                    <Tooltip formatter={(value) => [formatMoney(value, currency), 'Net sales']} contentStyle={{ border: '1px solid #e2e8f0', borderRadius: 10, fontSize: 12 }} />
                    <Area type="monotone" dataKey="sales_cents" stroke="#398b74" strokeWidth={2} fill="url(#pharmacySalesFill)" />
                  </AreaChart>
                </ResponsiveContainer>
              </div>
            </article>

            <article className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
              <div className="border-b border-slate-100 px-4 py-4 sm:px-5">
                <h2 className="text-sm font-bold text-slate-900">Expiry alerts</h2>
                <p className="mt-1 text-xs text-slate-500">Available batches expiring within 180 days, ordered by expiry.</p>
              </div>
              {dashboard.expiry_alerts.length ? (
                <div className="max-h-[295px] overflow-auto">
                  <table className="w-full min-w-[520px] text-left text-sm">
                    <thead className="sticky top-0 bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                      <tr><th className="px-4 py-3 font-semibold">Medicine / batch</th><th className="px-4 py-3 font-semibold">Expiry</th><th className="px-4 py-3 text-right font-semibold">Qty</th><th className="px-4 py-3 text-right font-semibold">Days</th></tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {dashboard.expiry_alerts.map((batch) => (
                        <tr key={batch.batch_id}>
                          <td className="px-4 py-3"><p className="font-semibold text-slate-900">{batch.product_name}</p><p className="text-xs text-slate-500">Lot {batch.batch_number}</p></td>
                          <td className="px-4 py-3 text-slate-600">{batch.expires_on}</td>
                          <td className="px-4 py-3 text-right tabular-nums">{batch.available_quantity.toLocaleString()}</td>
                          <td className={`px-4 py-3 text-right font-semibold tabular-nums ${batch.days_remaining <= 30 ? 'text-rose-700' : 'text-amber-700'}`}>{batch.days_remaining < 0 ? 'Expired' : batch.days_remaining}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : <p className="px-5 py-10 text-center text-sm text-slate-400">No available batches expiring within 180 days.</p>}
            </article>
          </section>

          <section className="grid gap-4 xl:grid-cols-2">
            <article className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
              <div className="border-b border-slate-100 px-4 py-4 sm:px-5">
                <h2 className="text-sm font-bold text-slate-900">Top-selling medicines</h2>
                <p className="mt-1 text-xs text-slate-500">Paid sale items for selected dates.</p>
              </div>
              {dashboard.top_medicines.length ? (
                <div className="divide-y divide-slate-100">
                  {dashboard.top_medicines.map((item) => (
                    <div key={item.product_id} className="flex items-center justify-between gap-4 px-5 py-3">
                      <div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-900">{item.name}</p><p className="text-xs text-slate-500">{item.units_sold} sold</p></div>
                      <p className="shrink-0 text-sm font-semibold tabular-nums text-slate-700">{formatMoney(item.sales_cents, currency)}</p>
                    </div>
                  ))}
                </div>
              ) : <p className="px-5 py-10 text-center text-sm text-slate-400">No medicine sales in the selected period.</p>}
            </article>

            <article className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
              <div className="border-b border-slate-100 px-4 py-4 sm:px-5">
                <h2 className="text-sm font-bold text-slate-900">Recent sales</h2>
                <p className="mt-1 text-xs text-slate-500">Persisted sales from the selected dates.</p>
              </div>
              {dashboard.recent_sales.length ? (
                <div className="divide-y divide-slate-100">
                  {dashboard.recent_sales.map((sale) => (
                    <div key={sale.id} className="flex items-center justify-between gap-4 px-5 py-3">
                      <div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-900">{sale.transaction_number}</p><p className="text-xs text-slate-500">{sale.cashier_name} · {formatDate(sale.created_at)}</p></div>
                      <div className="shrink-0 text-right"><p className="text-sm font-semibold tabular-nums text-slate-700">{formatMoney(Number(sale.total_cents), currency)}</p><p className="text-[10px] uppercase text-slate-400">{sale.payment_status}</p></div>
                    </div>
                  ))}
                </div>
              ) : <p className="px-5 py-10 text-center text-sm text-slate-400">No sales in the selected period.</p>}
            </article>
          </section>
        </>
      )}
    </div>
  )
}

export default PharmacyDashboardPage
