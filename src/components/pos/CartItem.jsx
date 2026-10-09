import { Minus, Plus, Trash2 } from 'lucide-react'
import { formatMoney } from '../../utils/money.js'

function CartItem({ item, currency, onQuantityChange, onRemove }) {
  return (
    <article className="flex gap-3 border-b border-slate-100 py-4 last:border-b-0">
      <div className="min-w-0 flex-1">
        <p className="line-clamp-2 text-sm font-semibold text-slate-800">{item.name}</p>
        <p className="mt-1 text-xs text-slate-500">{formatMoney(item.price_cents, currency)} each</p>
        <div className="mt-3 flex items-center gap-2">
          <button type="button" onClick={() => onQuantityChange(item.id, item.quantity - 1)} className="flex size-11 items-center justify-center rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50" aria-label={`Decrease ${item.name} quantity`}>
            <Minus size={16} aria-hidden="true" />
          </button>
          <span className="min-w-8 text-center text-sm font-bold tabular-nums text-slate-900" aria-live="polite">{item.quantity}</span>
          <button type="button" disabled={item.quantity >= item.stock_quantity} onClick={() => onQuantityChange(item.id, item.quantity + 1)} className="flex size-11 items-center justify-center rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40" aria-label={`Increase ${item.name} quantity`}>
            <Plus size={16} aria-hidden="true" />
          </button>
          <button type="button" onClick={() => onRemove(item.id)} className="ml-auto flex size-11 items-center justify-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label={`Remove ${item.name}`}>
            <Trash2 size={16} aria-hidden="true" />
          </button>
        </div>
      </div>
      <p className="shrink-0 pt-0.5 text-sm font-bold tabular-nums text-slate-900">{formatMoney(item.price_cents * item.quantity, currency)}</p>
    </article>
  )
}

export default CartItem