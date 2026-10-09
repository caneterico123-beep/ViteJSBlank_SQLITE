import { useMemo } from 'react'
import { ArrowRight, ShoppingBag, Trash2, X } from 'lucide-react'
import CartItem from './CartItem.jsx'
import { formatMoney } from '../../utils/money.js'

function CartPanel({ cart, currency, mobileOpen, onClose, onQuantityChange, onRemove, onClear, onCheckout, onHistory }) {
  const subtotal = useMemo(() => cart.reduce((sum, item) => sum + item.price_cents * item.quantity, 0), [cart])
  const quantity = cart.reduce((sum, item) => sum + item.quantity, 0)

  return (
    <>
      {mobileOpen && <button type="button" aria-label="Close cart" onClick={onClose} className="fixed inset-0 z-30 bg-slate-950/40 lg:hidden" />}
      <aside className={`fixed inset-y-0 right-0 z-40 flex w-full max-w-[420px] flex-col border-l border-slate-200 bg-white shadow-2xl transition-transform duration-200 lg:sticky lg:top-[88px] lg:z-0 lg:h-[calc(100vh-112px)] lg:w-auto lg:max-w-none lg:translate-x-0 lg:rounded-xl lg:border lg:shadow-sm ${mobileOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'}`} aria-label="Current sale cart">
        <div className="flex min-h-[64px] items-center justify-between border-b border-slate-100 px-4 sm:px-5">
          <div className="flex items-center gap-2.5">
            <span className="flex size-9 items-center justify-center rounded-lg bg-orange-50 text-orange-700"><ShoppingBag size={18} aria-hidden="true" /></span>
            <div>
              <h2 className="text-sm font-bold text-slate-900">Current sale</h2>
              <p className="text-[11px] text-slate-500">{quantity} {quantity === 1 ? 'item' : 'items'}</p>
            </div>
          </div>
          <div className="flex items-center gap-1">
            {cart.length > 0 && <button type="button" onClick={onClear} className="flex size-11 items-center justify-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Clear cart" aria-label="Clear cart"><Trash2 size={17} aria-hidden="true" /></button>}
            <button type="button" onClick={onClose} className="flex size-11 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 lg:hidden" aria-label="Close cart"><X size={18} aria-hidden="true" /></button>
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto px-4 sm:px-5">
          {cart.length === 0 ? (
            <div className="flex h-full min-h-[220px] flex-col items-center justify-center text-center">
              <span className="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><ShoppingBag size={21} aria-hidden="true" /></span>
              <p className="mt-3 text-sm font-semibold text-slate-700">Cart is empty</p>
              <p className="mt-1 text-xs text-slate-500">Tap a product to add it to this sale.</p>
            </div>
          ) : cart.map((item) => (
            <CartItem key={item.id} item={item} currency={currency} onQuantityChange={onQuantityChange} onRemove={onRemove} />
          ))}
        </div>

        <div className="border-t border-slate-100 p-4 sm:p-5">
          <div className="mb-4 flex items-center justify-between text-sm">
            <span className="font-medium text-slate-500">Subtotal</span>
            <span className="font-bold tabular-nums text-slate-900">{formatMoney(subtotal, currency)}</span>
          </div>
          <button type="button" disabled={cart.length === 0} onClick={onCheckout} className="flex min-h-14 w-full items-center justify-center gap-2 rounded-xl bg-orange-600 px-4 text-sm font-bold text-white shadow-sm transition-colors hover:bg-orange-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-700 disabled:cursor-not-allowed disabled:bg-slate-300">
            Checkout <ArrowRight size={17} aria-hidden="true" />
          </button>
          <button type="button" onClick={onHistory} className="mt-2 min-h-11 w-full rounded-lg text-xs font-semibold text-slate-500 hover:bg-slate-50 hover:text-slate-800">View sales history</button>
        </div>
      </aside>
    </>
  )
}

export default CartPanel