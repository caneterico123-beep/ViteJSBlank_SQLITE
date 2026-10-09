import { Image, PackageCheck } from 'lucide-react'
import { formatMoney } from '../../utils/money.js'

function ProductCard({ product, quantity, currency, onSelect }) {
  const outOfStock = product.stock_quantity < 1

  return (
    <button
      type="button"
      disabled={outOfStock}
      onClick={() => onSelect(product)}
      aria-label={`${product.name}, ${formatMoney(product.price_cents, currency)}, ${product.stock_quantity} in stock${outOfStock ? ', unavailable' : ''}`}
      className={`group relative flex min-h-[172px] flex-col overflow-hidden rounded-xl border bg-white text-left shadow-sm transition-all active:scale-[0.99] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600 ${quantity ? 'border-orange-400 ring-2 ring-orange-100' : 'border-slate-200 hover:border-orange-300 hover:shadow-md'} disabled:cursor-not-allowed disabled:opacity-50`}
    >
      <div className="relative flex h-[102px] w-full items-center justify-center overflow-hidden bg-slate-50">
        {product.image_path ? (
          <img src={product.image_path} alt="" loading="lazy" className="h-full w-full object-cover transition-transform duration-200 group-hover:scale-[1.03]" />
        ) : (
          <Image size={28} className="text-slate-300" aria-hidden="true" />
        )}
        {quantity > 0 && <span className="absolute right-2 top-2 flex size-7 items-center justify-center rounded-full bg-orange-600 text-xs font-bold text-white shadow">{quantity}</span>}
        {outOfStock && <span className="absolute inset-x-0 bottom-0 bg-slate-900/75 py-1 text-center text-[10px] font-bold uppercase tracking-wide text-white">Out of stock</span>}
      </div>
      <div className="flex min-h-[70px] flex-1 flex-col justify-between p-3">
        <div>
          <p className="line-clamp-2 text-xs font-semibold leading-4 text-slate-800">{product.name}</p>
          {product.sku && <p className="mt-1 truncate text-[10px] text-slate-400">{product.sku}</p>}
        </div>
        <div className="mt-2 flex items-center justify-between gap-2">
          <span className="text-sm font-bold tabular-nums text-slate-950">{formatMoney(product.price_cents, currency)}</span>
          <span className={`inline-flex items-center gap-1 text-[10px] font-medium ${product.stock_quantity < 5 ? 'text-amber-700' : 'text-slate-400'}`}>
            <PackageCheck size={12} aria-hidden="true" />{product.stock_quantity}
          </span>
        </div>
      </div>
    </button>
  )
}

export default ProductCard