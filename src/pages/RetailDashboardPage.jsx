import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  Banknote,
  Boxes,
  CircleDollarSign,
  ClipboardList,
  Package,
  ShoppingBag,
  ShoppingCart,
} from 'lucide-react'
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import StatCard from '../components/StatCard.jsx'
import { useAuth } from '../contexts/useAuth.js'
import { getRetailDashboard } from '../services/api.js'
import { formatMoney } from '../utils/money.js'

const chartColors = ['#e96b4c', '#398b74', '#6098c2', '#d6a343', '#8b79bd', '#da7e9e', '#6e879a', '#77a56a']

function RetailDashboardPage() {
  const { user } = useAuth()
  const currency = user.business?.currency || 'PHP'
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [groupBy, setGroupBy] = useState('day')
  const [appliedFilters, setAppliedFilters] = useState({ dateFrom: '', dateTo: '', groupBy: 'day' })
  const [dashboard, setDashboard] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    getRetailDashboard(appliedFilters)
      .then((result) => {
        if (active) setDashboard(result)
      })
      .catch((loadError) => {
        if (active) setError(loadError.message || 'Could not load retail dashboard data.')
      })
      .finally(() => {
        if (active) setIsLoading(false)
      })
    return () => {
      active = false
    }
  }, [appliedFilters])

  const today = dashboard?.today
  const inventory = dashboard?.inventory
  const dateRange = dashboard?.date_range

  function applyFilters(event) {
    event.preventDefault()
    setIsLoading(true)
    setError('')
    setAppliedFilters({ dateFrom, dateTo, groupBy })
  }

  function formatDate(value) {
    if (!value) return '—'
    const timestamp = new Date(`${value.replace(' ', 'T')}Z`)
    return new Intl.DateTimeFormat(undefined, {
      dateStyle: 'medium',
      timeStyle: 'short',
      timeZone: dashboard?.timezone || 'UTC',
    }).format(timestamp)
  }

  return (
    <div className="space-y-6">
      <section className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
        <div>
          <p className="mb-1 text-xs font-bold uppercase tracking-[1px] text-orange-700">Retail overview</p>
          <h1 className="text-2xl font-bold text-slate-950">{user.business?.name || 'Retail dashboard'}</h1>
          <p className="mt-1 text-sm text-slate-500">Live sales and inventory totals. Revenue excludes tax; pending and voided sales are not counted as sales.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to="/retail/pos" className="inline-flex min-h-10 items-center gap-2 rounded-lg bg-orange-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-orange-700">
            <ShoppingCart size={16} aria-hidden="true" /> Open POS
          </Link>
          <Link to="/dashboard/inventory/products" className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <Package size={16} aria-hidden="true" /> Products
          </Link>
        </div>
      </section>

      <form onSubmit={applyFilters} className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:flex-wrap sm:items-end">
        <div>
          <label htmlFor="retail-date-from" className="mb-1.5 block text-xs font-semibold text-slate-600">From</label>
          <input id="retail-date-from" type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} className="min-h-10 rounded-lg border border-slate-200 px-3 text-sm text-slate-800 outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100" />
        </div>
        <div>
          <label htmlFor="retail-date-to" className="mb-1.5 block text-xs font-semibold text-slate-600">To</label>
          <input id="retail-date-to" type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} className="min-h-10 rounded-lg border border-slate-200 px-3 text-sm text-slate-800 outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100" />
        </div>
        <div>
          <label htmlFor="retail-group-by" className="mb-1.5 block text-xs font-semibold text-slate-600">Sales grouping</label>
          <select id="retail-group-by" value={groupBy} onChange={(event) => setGroupBy(event.target.value)} className="min-h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100">
            <option value="day">By day</option>
            <option value="week">By week</option>
            <option value="month">By month</option>
          </select>
        </div>
        <button type="submit" disabled={isLoading} className="min-h-10 rounded-lg border border-slate-200 bg-slate-50 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-wait disabled:opacity-60">
          Apply filters
        </button>
        <p className="text-xs text-slate-500 sm:ml-auto sm:self-center">
          Showing {dateRange?.from || '…'} – {dateRange?.to || '…'} ({dashboard?.timezone || 'business timezone'})
        </p>
      </form>

      {error && <p className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{error}</p>}
      {isLoading && !dashboard && <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500" role="status">Loading retail performance…</div>}

      {dashboard && (
        <>
          <section aria-label="Today's retail performance" className="space-y-3">
            <h2 className="text-sm font-bold text-slate-900">Today</h2>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard label="Gross sales" value={formatMoney(today.gross_sales_cents, currency)} helper="Before discounts and tax" icon={CircleDollarSign} tone="orange" />
              <StatCard label="Net sales" value={formatMoney(today.net_sales_cents, currency)} helper="After discounts, before tax" icon={Banknote} tone="emerald" />
              <StatCard label="Transactions" value={today.transactions.toLocaleString()} helper="Paid transactions" icon={ShoppingBag} tone="sky" />
              <StatCard label="Average transaction" value={formatMoney(today.average_transaction_cents, currency)} helper="Net sales per paid transaction" icon={ClipboardList} tone="slate" />
            </div>
          </section>

          <section aria-label="Retail inventory" className="space-y-3">
            <h2 className="text-sm font-bold text-slate-900">Inventory health</h2>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard label="Active products" value={inventory.active_products.toLocaleString()} helper="Active catalog items" icon={Package} tone="sky" />
              <StatCard label="Low stock" value={inventory.low_stock.toLocaleString()} helper="Positive stock at or below reorder level" icon={AlertTriangle} tone="orange" />
              <StatCard label="Out of stock" value={inventory.out_of_stock.toLocaleString()} helper="Active products with no stock" icon={Boxes} tone="slate" />
              <StatCard label="Inventory value" value={formatMoney(inventory.inventory_value_cents, currency)} helper="Estimated at recorded product cost" icon={CircleDollarSign} tone="emerald" />
            </div>
          </section>

          <section className="grid gap-4 xl:grid-cols-2">
            <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
              <div className="mb-4">
                <h2 className="text-sm font-bold text-slate-900">Net sales trend</h2>
                <p className="mt-1 text-xs text-slate-500">Paid sales after discounts and before tax, grouped {groupBy}.</p>
              </div>
              <div className="h-[270px]" aria-label="Net sales trend chart">
                <ResponsiveContainer width="100%" height="100%">
                  <AreaChart data={dashboard.sales_by_period} margin={{ top: 8, right: 8, left: 4, bottom: 0 }}>
                    <defs>
                      <linearGradient id="retailSalesFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="#e96b4c" stopOpacity={0.2} />
                        <stop offset="95%" stopColor="#e96b4c" stopOpacity={0.015} />
                      </linearGradient>
                    </defs>
                    <CartesianGrid stroke="#edf0f2" vertical={false} />
                    <XAxis dataKey="label" tick={{ fill: '#84909f', fontSize: 10 }} tickLine={false} axisLine={false} minTickGap={24} />
                    <YAxis tick={{ fill: '#84909f', fontSize: 10 }} tickLine={false} axisLine={false} tickFormatter={(value) => formatMoney(value, currency)} width={78} />
                    <Tooltip formatter={(value) => [formatMoney(value, currency), 'Net sales']} contentStyle={{ border: '1px solid #e2e8f0', borderRadius: 10, fontSize: 12 }} />
                    <Area type="monotone" dataKey="sales_cents" stroke="#e96b4c" strokeWidth={2} fill="url(#retailSalesFill)" activeDot={{ r: 4, strokeWidth: 0 }} />
                  </AreaChart>
                </ResponsiveContainer>
              </div>
            </article>

            <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
              <div className="mb-4">
                <h2 className="text-sm font-bold text-slate-900">Payment method mix</h2>
                <p className="mt-1 text-xs text-slate-500">Paid transaction totals including tax for the selected period.</p>
              </div>
              <div className="h-[270px]" aria-label="Paid sales by payment method">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie data={dashboard.sales_by_payment_method.filter((item) => item.amount_cents > 0)} dataKey="amount_cents" nameKey="name" cx="50%" cy="50%" outerRadius={92} innerRadius={52} paddingAngle={3}>
                      {dashboard.sales_by_payment_method.filter((item) => item.amount_cents > 0).map((item, index) => <Cell key={item.name} fill={chartColors[index % chartColors.length]} />)}
                    </Pie>
                    <Tooltip formatter={(value) => formatMoney(value, currency)} contentStyle={{ border: '1px solid #e2e8f0', borderRadius: 10, fontSize: 12 }} />
                  </PieChart>
                </ResponsiveContainer>
              </div>
              <div className="flex flex-wrap justify-center gap-x-4 gap-y-2">
                {dashboard.sales_by_payment_method.map((item, index) => (
                  <span key={item.name} className="inline-flex items-center gap-1.5 text-xs text-slate-600">
                    <span className="size-2 rounded-full" style={{ backgroundColor: chartColors[index % chartColors.length] }} aria-hidden="true" />
                    {item.name}: {formatMoney(item.amount_cents, currency)}
                  </span>
                ))}
              </div>
            </article>
          </section>

          <section className="grid gap-4 xl:grid-cols-3">
            <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5 xl:col-span-1">
              <div className="mb-4">
                <h2 className="text-sm font-bold text-slate-900">Stock status</h2>
                <p className="mt-1 text-xs text-slate-500">Active catalog products.</p>
              </div>
              <div className="h-[230px]" aria-label="Inventory stock status chart">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie data={dashboard.stock_status} dataKey="products" nameKey="name" cx="50%" cy="50%" outerRadius={78} innerRadius={42} paddingAngle={3}>
                      {dashboard.stock_status.map((item, index) => <Cell key={item.name} fill={['#398b74', '#d6a343', '#e96b4c'][index]} />)}
                    </Pie>
                    <Tooltip formatter={(value) => [value, 'Products']} contentStyle={{ border: '1px solid #e2e8f0', borderRadius: 10, fontSize: 12 }} />
                  </PieChart>
                </ResponsiveContainer>
              </div>
            </article>

            <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
              <div className="mb-4">
                <h2 className="text-sm font-bold text-slate-900">Top-selling products</h2>
                <p className="mt-1 text-xs text-slate-500">Units sold in the selected period.</p>
              </div>
              {dashboard.top_products.length ? (
                <div className="h-[230px]" aria-label="Top-selling products chart">
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={dashboard.top_products} layout="vertical" margin={{ top: 0, right: 12, left: 4, bottom: 0 }}>
                      <CartesianGrid stroke="#edf0f2" horizontal={false} />
                      <XAxis type="number" allowDecimals={false} tick={{ fill: '#84909f', fontSize: 10 }} tickLine={false} axisLine={false} />
                      <YAxis type="category" dataKey="name" width={100} tick={{ fill: '#64748b', fontSize: 10 }} tickLine={false} axisLine={false} />
                      <Tooltip formatter={(value) => [value, 'Units sold']} contentStyle={{ border: '1px solid #e2e8f0', borderRadius: 10, fontSize: 12 }} />
                      <Bar dataKey="units_sold" fill="#e96b4c" radius={[0, 5, 5, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              ) : <p className="flex h-[230px] items-center justify-center text-sm text-slate-400">No paid product sales in this period.</p>}
            </article>

            <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
              <div className="mb-4">
                <h2 className="text-sm font-bold text-slate-900">Sales by category</h2>
                <p className="mt-1 text-xs text-slate-500">Units sold before transaction-level discounts.</p>
              </div>
              {dashboard.sales_by_category.length ? (
                <div className="h-[230px]" aria-label="Units sold by product category chart">
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={dashboard.sales_by_category} margin={{ top: 4, right: 8, left: -20, bottom: 0 }}>
                      <CartesianGrid stroke="#edf0f2" vertical={false} />
                      <XAxis dataKey="name" tick={{ fill: '#84909f', fontSize: 10 }} tickLine={false} axisLine={false} />
                      <YAxis allowDecimals={false} tick={{ fill: '#84909f', fontSize: 10 }} tickLine={false} axisLine={false} />
                      <Tooltip formatter={(value) => [value, 'Units sold']} contentStyle={{ border: '1px solid #e2e8f0', borderRadius: 10, fontSize: 12 }} />
                      <Bar dataKey="units_sold" fill="#6098c2" radius={[5, 5, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              ) : <p className="flex h-[230px] items-center justify-center text-sm text-slate-400">No category sales in this period.</p>}
            </article>
          </section>

          <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div className="flex flex-wrap items-end justify-between gap-2 border-b border-slate-100 px-4 py-4 sm:px-5">
              <div>
                <h2 className="text-sm font-bold text-slate-900">Recent transactions</h2>
                <p className="mt-1 text-xs text-slate-500">Transactions created in the selected date range.</p>
              </div>
              <Link to="/retail/transactions" className="text-xs font-semibold text-orange-700 hover:text-orange-900">View sales history</Link>
            </div>
            {dashboard.recent_transactions.length ? (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[640px] text-left text-sm">
                  <thead className="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                    <tr><th className="px-5 py-3 font-semibold">Receipt</th><th className="px-5 py-3 font-semibold">Date</th><th className="px-5 py-3 font-semibold">Cashier</th><th className="px-5 py-3 font-semibold">Payment</th><th className="px-5 py-3 text-right font-semibold">Total</th><th className="px-5 py-3 font-semibold">Status</th></tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {dashboard.recent_transactions.map((sale) => (
                      <tr key={sale.id} className="text-slate-700">
                        <td className="px-5 py-3 font-semibold text-slate-900">{sale.transaction_number}</td>
                        <td className="px-5 py-3 text-xs text-slate-500">{formatDate(sale.created_at)}</td>
                        <td className="px-5 py-3">{sale.cashier_name}</td>
                        <td className="px-5 py-3 capitalize">{sale.payment_method}</td>
                        <td className="px-5 py-3 text-right font-semibold tabular-nums">{formatMoney(Number(sale.total_cents), currency)}</td>
                        <td className="px-5 py-3"><span className={`rounded-full px-2 py-1 text-[10px] font-bold uppercase ${sale.payment_status === 'paid' ? 'bg-emerald-50 text-emerald-700' : sale.payment_status === 'pending' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'}`}>{sale.payment_status}</span></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : <p className="px-5 py-10 text-center text-sm text-slate-400">No transactions in this date range.</p>}
          </section>
        </>
      )}
    </div>
  )
}

export default RetailDashboardPage
