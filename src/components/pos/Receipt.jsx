import { Printer } from 'lucide-react'
import { formatMoney } from '../../utils/money.js'

function Receipt({ sale, storeName, currency, onDone }) {
  if (!sale) return null
  const paid = sale.payment_status === 'paid'

  return (
    <div className="fixed inset-0 z-[70] flex items-end justify-center bg-slate-950/45 p-0 backdrop-blur-sm sm:items-center sm:p-5" role="presentation">
      <section className="receipt-print max-h-[92dvh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-2xl sm:p-7" role="dialog" aria-modal="true" aria-labelledby="receipt-title">
        <div className="text-center">
          <p className="text-[10px] font-bold uppercase tracking-[1.2px] text-slate-400">{storeName}</p>
          <h2 id="receipt-title" className="mt-2 text-lg font-bold text-slate-950">{paid ? 'Sale complete' : 'Payment pending'}</h2>
          <p className="mt-1 font-mono text-xs text-slate-500">{sale.transaction_number}</p>
          <p className="mt-1 text-[11px] text-slate-500">{new Date(`${sale.created_at.replace(' ', 'T')}Z`).toLocaleString()}</p>
        </div>

        <dl className="my-5 grid grid-cols-2 gap-3 border-y border-dashed border-slate-200 py-4 text-xs">
          <div><dt className="text-slate-400">Cashier</dt><dd className="mt-1 font-semibold text-slate-700">{sale.cashier_name}</dd></div>
          <div><dt className="text-slate-400">Payment</dt><dd className="mt-1 font-semibold capitalize text-slate-700">{sale.payment_method} · {sale.payment_status}</dd></div>
        </dl>

        <div className="space-y-3">
          {sale.items.map((item) => (
            <div key={item.id} className="flex justify-between gap-3 text-xs">
              <div><p className="font-semibold text-slate-800">{item.product_name}</p><p className="mt-0.5 text-slate-500">{item.quantity} × {formatMoney(item.unit_price_cents, currency)}</p></div>
              <span className="font-semibold tabular-nums text-slate-800">{formatMoney(item.line_total_cents, currency)}</span>
            </div>
          ))}
        </div>

        <div className="mt-5 space-y-2 border-t border-dashed border-slate-200 pt-4 text-xs">
          <div className="flex justify-between text-slate-600"><span>Subtotal</span><span>{formatMoney(sale.subtotal_cents, currency)}</span></div>
          <div className="flex justify-between text-slate-600"><span>Discount</span><span>−{formatMoney(sale.discount_cents, currency)}</span></div>
          <div className="flex justify-between text-slate-600"><span>Tax</span><span>{formatMoney(sale.tax_cents, currency)}</span></div>
          <div className="flex justify-between border-t border-slate-100 pt-3 text-sm font-bold text-slate-950"><span>Total</span><span>{formatMoney(sale.total_cents, currency)}</span></div>
          {sale.amount_received_cents !== null && sale.amount_received_cents !== undefined && <div className="flex justify-between text-slate-600"><span>Received</span><span>{formatMoney(sale.amount_received_cents, currency)}</span></div>}
          {sale.change_cents > 0 && <div className="flex justify-between text-slate-600"><span>Change</span><span>{formatMoney(sale.change_cents, currency)}</span></div>}
        </div>

        {!paid && <p className="mt-4 rounded-lg bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-900">No card or E-wallet provider is connected. This order is pending and must not be treated as a confirmed payment.</p>}

        <div className="mt-5 flex gap-2 print:hidden">
          <button type="button" onClick={() => window.print()} className="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-lg border border-slate-200 text-sm font-semibold text-slate-700 hover:bg-slate-50"><Printer size={16} aria-hidden="true" />Print receipt</button>
          <button type="button" onClick={onDone} className="min-h-12 flex-1 rounded-lg bg-slate-900 px-4 text-sm font-bold text-white hover:bg-slate-800">Done</button>
        </div>
      </section>
    </div>
  )
}

export default Receipt