import { useState } from 'react'
import { CreditCard, Smartphone, Wallet, X } from 'lucide-react'
import { calculateTotals, formatMoney } from '../../utils/money.js'

function amountToCents(value) {
  if (!/^\d{1,9}(\.\d{0,2})?$/.test(value)) return null
  const [whole, fraction = ''] = value.split('.')
  return Number(whole) * 100 + Number((fraction + '00').slice(0, 2))
}

function CheckoutDialog({ cart, config, isSubmitting, error, onConfirm, onCancel, onCancelTransaction }) {
  const [paymentMethod, setPaymentMethod] = useState('cash')
  const [discount, setDiscount] = useState('0')
  const [amountReceived, setAmountReceived] = useState('')
  const [customerName, setCustomerName] = useState('')
  const subtotal = cart.reduce((sum, item) => sum + item.price_cents * item.quantity, 0)
  const discountNumber = Number(discount)
  const discountBps = Number.isFinite(discountNumber) && discountNumber >= 0 ? Math.round(discountNumber * 100) : -1
  const totals = calculateTotals(subtotal, Math.max(0, discountBps), config.taxBps)
  const receivedCents = amountToCents(amountReceived)
  const insufficientCash = paymentMethod === 'cash' && receivedCents !== null && receivedCents < totals.totalCents
  const discountOverLimit = discountBps > config.discountMaxBps
  const canConfirm = cart.length > 0 && !discountOverLimit && !insufficientCash
    && (paymentMethod !== 'cash' || (receivedCents !== null && receivedCents >= totals.totalCents))

  async function handleSubmit(event) {
    event.preventDefault()
    if (!canConfirm || isSubmitting) return
    await onConfirm({
      items: cart.map((item) => ({ product_id: item.id, quantity: item.quantity })),
      discount_bps: discountBps,
      payment_method: paymentMethod,
      amount_received_cents: paymentMethod === 'cash' ? receivedCents : null,
      customer_name: customerName.trim(),
    })
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-end justify-center bg-slate-950/45 p-0 backdrop-blur-sm sm:items-center sm:p-5" role="presentation">
      <section role="dialog" aria-modal="true" aria-labelledby="checkout-title" className="max-h-[94dvh] w-full max-w-xl overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-2xl sm:p-7">
        <header className="mb-5 flex items-start justify-between gap-4">
          <div>
            <p className="text-[10px] font-bold uppercase tracking-[1px] text-orange-700">Review and pay</p>
            <h2 id="checkout-title" className="mt-1 text-xl font-bold text-slate-950">Checkout</h2>
          </div>
          <button type="button" onClick={onCancel} className="flex size-11 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100" aria-label="Close checkout"><X size={19} aria-hidden="true" /></button>
        </header>

        <form onSubmit={handleSubmit} className="space-y-5">
          <div className="max-h-36 space-y-2 overflow-y-auto rounded-lg bg-slate-50 p-3">
            {cart.map((item) => (
              <div key={item.id} className="flex justify-between gap-3 text-xs"><span className="min-w-0 truncate text-slate-600">{item.quantity} × {item.name}</span><span className="shrink-0 font-semibold tabular-nums text-slate-800">{formatMoney(item.price_cents * item.quantity, config.currency)}</span></div>
            ))}
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <label className="block">
              <span className="mb-1.5 block text-xs font-semibold text-slate-700">Customer / order label <span className="font-normal text-slate-400">(optional)</span></span>
              <input value={customerName} onChange={(event) => setCustomerName(event.target.value)} maxLength={120} className="h-12 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100" placeholder="Walk-in customer" />
            </label>
            <label className="block">
              <span className="mb-1.5 block text-xs font-semibold text-slate-700">Discount (%)</span>
              <input type="number" min="0" max={config.discountMaxBps / 100} step="0.01" value={discount} onChange={(event) => setDiscount(event.target.value)} className="h-12 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100" />
              <span className="mt-1 block text-[10px] text-slate-400">Role limit: {(config.discountMaxBps / 100).toFixed(2)}%</span>
            </label>
          </div>

          <fieldset>
            <legend className="mb-2 text-xs font-semibold text-slate-700">Payment method</legend>
            <div className="grid grid-cols-3 gap-2">
              {[
                { value: 'cash', label: 'Cash', icon: Wallet },
                { value: 'card', label: 'Card', icon: CreditCard },
                { value: 'ewallet', label: 'E-wallet', icon: Smartphone },
              ].map(({ value, label, icon: Icon }) => (
                <button key={value} type="button" onClick={() => setPaymentMethod(value)} aria-pressed={paymentMethod === value} className={`flex min-h-12 items-center justify-center gap-2 rounded-lg border px-2 text-xs font-semibold transition-colors ${paymentMethod === value ? 'border-orange-500 bg-orange-50 text-orange-800' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                  <Icon size={16} aria-hidden="true" />{label}
                </button>
              ))}
            </div>
          </fieldset>

          {paymentMethod === 'cash' ? (
            <div className="grid gap-4 sm:grid-cols-2">
              <label className="block">
                <span className="mb-1.5 block text-xs font-semibold text-slate-700">Cash received ({config.currency})</span>
                <input inputMode="decimal" value={amountReceived} onChange={(event) => setAmountReceived(event.target.value)} className="h-12 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100" placeholder="0.00" />
              </label>
              <div className="flex flex-col justify-center rounded-lg bg-emerald-50 px-4 py-2.5">
                <span className="text-[11px] font-medium text-emerald-800">Change due</span>
                <span className="mt-0.5 text-lg font-bold tabular-nums text-emerald-900">{formatMoney(Math.max(0, (receivedCents ?? 0) - totals.totalCents), config.currency)}</span>
              </div>
            </div>
          ) : (
            <p className="rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-3 text-xs leading-5 text-amber-900">
              No payment provider is connected. This {paymentMethod === 'card' ? 'card' : 'E-wallet'} order will be recorded as <strong>pending</strong>; do not treat it as paid.
            </p>
          )}

          <div className="space-y-2 border-t border-slate-100 pt-4 text-sm">
            <div className="flex justify-between text-slate-500"><span>Subtotal</span><span>{formatMoney(totals.subtotalCents, config.currency)}</span></div>
            <div className="flex justify-between text-slate-500"><span>Discount</span><span>−{formatMoney(totals.discountCents, config.currency)}</span></div>
            <div className="flex justify-between text-slate-500"><span>Tax ({(config.taxBps / 100).toFixed(2)}%)</span><span>{formatMoney(totals.taxCents, config.currency)}</span></div>
            <div className="flex justify-between border-t border-slate-100 pt-3 text-lg font-bold text-slate-950"><span>Total due</span><span className="tabular-nums">{formatMoney(totals.totalCents, config.currency)}</span></div>
          </div>

          {discountOverLimit && <p className="text-xs text-rose-700" role="alert">This discount exceeds your role limit.</p>}
          {insufficientCash && <p className="text-xs text-rose-700" role="alert">Cash received is less than the amount due.</p>}
          {paymentMethod === 'cash' && amountReceived !== '' && receivedCents === null && <p className="text-xs text-rose-700" role="alert">Enter a valid amount with up to two decimal places.</p>}
          {error && <p className="rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-3 text-xs text-rose-800" role="alert">{error}</p>}

          <div className="grid gap-2 sm:grid-cols-2">
            <button type="button" onClick={onCancelTransaction} className="min-h-12 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-600 hover:bg-slate-50">Cancel transaction</button>
            <button type="submit" disabled={!canConfirm || isSubmitting} className="min-h-12 rounded-lg bg-orange-600 px-4 text-sm font-bold text-white hover:bg-orange-700 disabled:cursor-not-allowed disabled:bg-slate-300">
              {isSubmitting ? 'Processing...' : paymentMethod === 'cash' ? 'Confirm cash sale' : 'Record pending order'}
            </button>
          </div>
        </form>
      </section>
    </div>
  )
}

export default CheckoutDialog