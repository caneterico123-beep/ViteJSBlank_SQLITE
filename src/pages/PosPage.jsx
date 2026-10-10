import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertCircle, Wifi, WifiOff, Search, Settings2, ShoppingCart } from 'lucide-react'
import { useAuth } from '../contexts/useAuth.js'
import {
  checkoutSale,
  getCategories,
  getPosConfig,
  getProducts,
} from '../services/api.js'
import CartPanel from '../components/pos/CartPanel.jsx'
import CategoryManager from '../components/pos/CategoryManager.jsx'
import CheckoutDialog from '../components/pos/CheckoutDialog.jsx'
import ProductCard from '../components/pos/ProductCard.jsx'
import Receipt from '../components/pos/Receipt.jsx'

function createIdempotencyKey() {
  const value = globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`
  return `pos:${value}`
}

function PosPage() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [config, setConfig] = useState(null)
  const [categories, setCategories] = useState([])
  const [products, setProducts] = useState([])
  const [categoryId, setCategoryId] = useState('')
  const [search, setSearch] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState('')
  const [online, setOnline] = useState(navigator.onLine)
  const [cart, setCart] = useState([])
  const [mobileCartOpen, setMobileCartOpen] = useState(false)
  const [checkoutOpen, setCheckoutOpen] = useState(false)
  const [categoryManagerOpen, setCategoryManagerOpen] = useState(false)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [checkoutError, setCheckoutError] = useState('')
  const [receipt, setReceipt] = useState(null)
  const [idempotencyKey, setIdempotencyKey] = useState(createIdempotencyKey)

  const refreshCategories = useCallback(async () => {
    const result = await getCategories()
    setCategories(result.categories)
  }, [])

  const refreshProducts = useCallback(async (query = search, selectedCategory = categoryId) => {
    const result = await getProducts({ search: query.trim(), categoryId: selectedCategory })
    setProducts(result.products)
  }, [categoryId, search])

  useEffect(() => {
    let active = true
    Promise.all([getPosConfig(), getCategories()])
      .then(([posConfig, categoryResult]) => {
        if (!active) return
        setConfig(posConfig)
        setCategories(categoryResult.categories)
      })
      .catch((loadError) => {
        if (active) setError(loadError.message || 'Could not load the POS configuration.')
      })
    return () => { active = false }
  }, [])

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      setIsLoading(true)
      setError('')
      getProducts({ search: search.trim(), categoryId })
        .then((result) => { if (active) setProducts(result.products) })
        .catch((loadError) => { if (active) setError(loadError.message || 'Could not load products.') })
        .finally(() => { if (active) setIsLoading(false) })
    }, 180)
    return () => {
      active = false
      window.clearTimeout(timer)
    }
  }, [search, categoryId])

  useEffect(() => {
    const update = () => setOnline(navigator.onLine)
    window.addEventListener('online', update)
    window.addEventListener('offline', update)
    return () => {
      window.removeEventListener('online', update)
      window.removeEventListener('offline', update)
    }
  }, [])

  const cartQuantity = useMemo(() => cart.reduce((sum, item) => sum + item.quantity, 0), [cart])
  const addProduct = useCallback((product) => {
    setCart((current) => {
      const existing = current.find((item) => item.id === product.id)
      if (existing) {
        if (existing.quantity >= product.stock_quantity) return current
        return current.map((item) => item.id === product.id ? { ...item, quantity: item.quantity + 1 } : item)
      }
      if (product.stock_quantity < 1) return current
      return [...current, { ...product, quantity: 1 }]
    })
  }, [])

  function changeQuantity(productId, quantity) {
    setCart((current) => current.flatMap((item) => {
      if (item.id !== productId) return [item]
      if (quantity < 1) return []
      const product = products.find((entry) => entry.id === productId)
      return [{ ...item, quantity: Math.min(quantity, product?.stock_quantity ?? item.stock_quantity) }]
    }))
  }

  function clearCart() {
    if (cart.length && !window.confirm('Clear all items from this sale?')) return
    setCart([])
  }

  async function handleBarcodeSubmit(event) {
    if (event.key !== 'Enter' || !search.trim()) return
    event.preventDefault()
    try {
      const result = await getProducts({ search: search.trim() })
      const normalized = search.trim().toLocaleLowerCase()
      const match = result.products.find((product) =>
        [product.barcode, product.sku].some((value) => value?.toLocaleLowerCase() === normalized))
      if (match) {
        addProduct(match)
        setSearch('')
        setError('')
      } else {
        setError('No product matches that barcode or SKU.')
      }
    } catch (loadError) {
      setError(loadError.message || 'Could not search for that barcode.')
    }
  }

  async function handleCheckout(payload) {
    setIsSubmitting(true)
    setCheckoutError('')
    try {
      const result = await checkoutSale(payload, idempotencyKey)
      setReceipt(result.sale)
      setCheckoutOpen(false)
      setCart([])
      setIdempotencyKey(createIdempotencyKey())
      await refreshProducts()
    } catch (submitError) {
      setCheckoutError(submitError.message || 'Checkout failed. No sale was completed.')
      if (submitError.status === 409) {
        try { await refreshProducts() } catch (refreshError) { setError(refreshError.message) }
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  const selectedQuantities = useMemo(
    () => Object.fromEntries(cart.map((item) => [item.id, item.quantity])),
    [cart],
  )

  return (
    <section className="space-y-5">
      <header className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm sm:px-5">
        <div className="flex min-w-0 items-center gap-3">
          <span className="brand-mark flex size-10 shrink-0 items-center justify-center rounded-xl text-white"><ShoppingCart size={19} aria-hidden="true" /></span>
          <div className="min-w-0">
            <p className="truncate text-sm font-bold text-slate-900">{config?.storeName || 'Point of Sale'}</p>
            <p className="text-xs text-slate-500">Cashier: {user.name} · New transaction</p>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <span className={`inline-flex min-h-10 items-center gap-2 rounded-lg px-3 text-xs font-semibold ${online ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'}`} role="status">
            {online ? <Wifi size={15} aria-hidden="true" /> : <WifiOff size={15} aria-hidden="true" />}
            {online ? 'Online · Live inventory' : 'Offline · Checkout unavailable'}
          </span>
          <button type="button" onClick={() => navigate('/pos/transactions')} className="min-h-11 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50">Sales history</button>
          {['owner', 'admin', 'manager'].includes(user.role) && <button type="button" onClick={() => setCategoryManagerOpen(true)} className="flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50"><Settings2 size={15} aria-hidden="true" />Categories</button>}
          <button type="button" onClick={() => setMobileCartOpen(true)} className="flex min-h-11 items-center gap-2 rounded-lg bg-orange-600 px-3 text-xs font-bold text-white hover:bg-orange-700 lg:hidden"><ShoppingCart size={16} aria-hidden="true" />Cart ({cartQuantity})</button>
        </div>
      </header>

      <div className="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_350px]">
        <div className="min-w-0 space-y-4">
          <form role="search" onSubmit={(event) => event.preventDefault()} className="relative">
            <Search size={18} className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true" />
            <input autoFocus value={search} onChange={(event) => setSearch(event.target.value)} onKeyDown={handleBarcodeSubmit} aria-label="Search products or scan barcode" placeholder="Search products or scan barcode / SKU" className="h-12 w-full rounded-xl border border-slate-200 bg-white pl-11 pr-4 text-sm outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100" />
          </form>

          <nav aria-label="Product categories" className="flex gap-2 overflow-x-auto pb-1">
            <button type="button" onClick={() => setCategoryId('')} aria-pressed={categoryId === ''} className={`min-h-11 shrink-0 rounded-lg px-4 text-xs font-bold ${categoryId === '' ? 'bg-slate-900 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}>All Products</button>
            {categories.map((category) => (
              <button key={category.id} type="button" onClick={() => setCategoryId(String(category.id))} aria-pressed={categoryId === String(category.id)} className={`min-h-11 shrink-0 rounded-lg px-4 text-xs font-semibold ${categoryId === String(category.id) ? 'bg-orange-600 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}>{category.name}</button>
            ))}
          </nav>

          {error && <p className="flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert"><AlertCircle size={17} className="mt-0.5 shrink-0" aria-hidden="true" />{error}</p>}
          {isLoading ? (
            <p className="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500" role="status">Loading products…</p>
          ) : products.length === 0 ? (
            <p className="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">No products found. Try another search or category.</p>
          ) : (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
              {products.map((product) => <ProductCard key={product.id} product={product} quantity={selectedQuantities[product.id] || 0} currency={config?.currency || 'USD'} onSelect={addProduct} />)}
            </div>
          )}
        </div>

        <CartPanel
          cart={cart}
          currency={config?.currency || 'USD'}
          mobileOpen={mobileCartOpen}
          onClose={() => setMobileCartOpen(false)}
          onQuantityChange={changeQuantity}
          onRemove={(id) => setCart((current) => current.filter((item) => item.id !== id))}
          onClear={clearCart}
          onCheckout={() => { setCheckoutError(''); setCheckoutOpen(true); setMobileCartOpen(false) }}
          onHistory={() => navigate('/pos/transactions')}
          canCheckout={Boolean(config) && online}
        />
      </div>

      {checkoutOpen && config && <CheckoutDialog cart={cart} config={config} isSubmitting={isSubmitting} error={checkoutError} onConfirm={handleCheckout} onCancel={() => setCheckoutOpen(false)} onCancelTransaction={() => setCheckoutOpen(false)} />}
      {receipt && config && <Receipt sale={receipt} storeName={config.storeName} currency={config.currency || 'USD'} onDone={() => setReceipt(null)} />}
      {categoryManagerOpen && <CategoryManager onClose={() => setCategoryManagerOpen(false)} onChanged={() => { refreshCategories().catch((loadError) => setError(loadError.message)) }} />}
    </section>
  )
}

export default PosPage
