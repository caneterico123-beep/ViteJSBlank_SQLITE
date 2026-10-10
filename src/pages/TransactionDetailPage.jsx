import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { cancelTransaction, getPosConfig, getTransaction } from '../services/api.js'
import { useAuth } from '../contexts/useAuth.js'
import Receipt from '../components/pos/Receipt.jsx'

function TransactionDetailPage() {
  const { id } = useParams()
  const { user } = useAuth()
  const [sale, setSale] = useState(null)
  const [storeName, setStoreName] = useState('Northstar Store')
  const [currency, setCurrency] = useState('USD')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [showReceipt, setShowReceipt] = useState(false)

  useEffect(() => {
    let active = true
    Promise.all([getTransaction(id), getPosConfig()])
      .then(([transaction, config]) => {
        if (!active) return
        setSale(transaction.sale)
        setStoreName(config.storeName)
        setCurrency(config.currency || 'USD')
      })
      .catch((loadError) => { if (active) setError(loadError.message || 'Could not load this transaction.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [id])

  async function handleCancel() {
    const reason = window.prompt('Enter a reason for cancelling or voiding this transaction:')
    if (reason === null) return
    if (!window.confirm('Cancel or void this transaction and restore its stock?')) return
    setBusy(true)
    setError('')
    try {
      const result = await cancelTransaction(id, reason)
      setSale(result.sale)
    } catch (cancelError) {
      setError(cancelError.message || 'Could not cancel this transaction.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <p className="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500" role="status">Loading transaction…</p>
  if (error && !sale) return <div><p className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800" role="alert">{error}</p><Link to="/pos/transactions" className="mt-4 inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-slate-700"><ArrowLeft size={16} aria-hidden="true" />Sales history</Link></div>
  if (!sale) return null
  const canCancel = !['voided', 'cancelled'].includes(sale.payment_status)
    && (sale.payment_status !== 'paid' || ['owner', 'admin'].includes(user.role))

  return (
    <section className="mx-auto max-w-3xl space-y-5">
      <Link to="/pos/transactions" className="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950"><ArrowLeft size={16} aria-hidden="true" />Sales history</Link>
      {error && <p className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800" role="alert">{error}</p>}
      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div><p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Transaction details</p><h1 className="mt-1 font-mono text-lg font-bold text-slate-950">{sale.transaction_number}</h1><p className="mt-1 text-sm text-slate-500">{new Date(`${sale.created_at.replace(' ', 'T')}Z`).toLocaleString()}</p></div>
          <span className="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold capitalize text-slate-700">{sale.payment_status}</span>
        </div>
        <div className="mt-5 grid gap-3 border-y border-slate-100 py-4 text-sm sm:grid-cols-3">
          <p><span className="block text-xs text-slate-400">Cashier</span><span className="font-semibold text-slate-800">{sale.cashier_name}</span></p>
          <p><span className="block text-xs text-slate-400">Customer / label</span><span className="font-semibold text-slate-800">{sale.customer_name || 'Walk-in customer'}</span></p>
          <p><span className="block text-xs text-slate-400">Payment</span><span className="font-semibold capitalize text-slate-800">{sale.payment_method}</span></p>
        </div>
        <div className="divide-y divide-slate-100">
          {sale.items.map((item) => <div key={item.id} className="flex justify-between gap-4 py-3 text-sm"><div><p className="font-semibold text-slate-800">{item.product_name}</p><p className="text-xs text-slate-500">{item.quantity} × {new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(item.unit_price_cents / 100)}</p></div><span className="font-semibold tabular-nums text-slate-900">{new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(item.line_total_cents / 100)}</span></div>)}
        </div>
        <div className="ml-auto mt-4 max-w-sm space-y-2 border-t border-slate-100 pt-4 text-sm">
          <div className="flex justify-between text-slate-600"><span>Subtotal</span><span>{new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(sale.subtotal_cents / 100)}</span></div>
          <div className="flex justify-between text-slate-600"><span>Discount</span><span>−{new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(sale.discount_cents / 100)}</span></div>
          <div className="flex justify-between text-slate-600"><span>Tax</span><span>{new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(sale.tax_cents / 100)}</span></div>
          <div className="flex justify-between border-t border-slate-100 pt-3 text-base font-bold text-slate-950"><span>Total</span><span>{new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(sale.total_cents / 100)}</span></div>
        </div>
        {sale.audit?.length > 0 && <div className="mt-6 border-t border-slate-100 pt-4"><h2 className="text-sm font-bold text-slate-800">Audit trail</h2><ul className="mt-2 space-y-2">{sale.audit.map((entry, index) => <li key={`${entry.action}-${entry.created_at}-${index}`} className="text-xs text-slate-600"><strong className="capitalize">{entry.action}</strong> by {entry.actor_name} · {entry.created_at}{entry.reason ? ` · ${entry.reason}` : ''}</li>)}</ul></div>}
        <div className="mt-6 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
          <button type="button" onClick={() => setShowReceipt(true)} className="min-h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">View / print receipt</button>
          {canCancel && <button type="button" disabled={busy} onClick={handleCancel} className="min-h-11 rounded-lg border border-rose-200 px-4 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50">{busy ? 'Processing…' : sale.payment_status === 'paid' ? 'Void sale' : 'Cancel pending sale'}</button>}
        </div>
      </div>
      {showReceipt && <Receipt sale={sale} storeName={storeName} currency={currency} onDone={() => setShowReceipt(false)} />}
    </section>
  )
}

export default TransactionDetailPage
