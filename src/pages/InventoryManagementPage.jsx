import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import {
  AlertTriangle,
  ArrowDownToLine,
  ArrowUpFromLine,
  Download,
  Package,
  Plus,
  Search,
  Truck,
  X,
} from 'lucide-react'
import {
  createInventoryOperation,
  createProduct,
  deactivateProduct,
  deactivateSupplier,
  getCategories,
  getInventoryHistory,
  getInventoryProducts,
  getInventorySummary,
  getSuppliers,
  restoreProduct,
  restoreSupplier,
  saveSupplier,
  updateProduct,
} from '../services/api.js'
import { formatMoney } from '../utils/money.js'
import CategoryManager from '../components/pos/CategoryManager.jsx'
import { useAuth } from '../contexts/useAuth.js'

const sections = [
  { key: 'products', label: 'Products', path: '/dashboard/inventory/products' },
  { key: 'stock', label: 'Stock operations', path: '/dashboard/inventory/stock' },
  { key: 'suppliers', label: 'Suppliers', path: '/dashboard/inventory/suppliers' },
  { key: 'history', label: 'Stock history', path: '/dashboard/inventory/history' },
]
const inputClass = 'h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100'
const emptyProduct = {
  name: '',
  sku: '',
  barcode: '',
  description: '',
  category_id: '',
  supplier_id: '',
  price: '',
  cost: '',
  stock_quantity: '0',
  reorder_level: '5',
  image_path: '',
}
const emptySupplier = { name: '', contact_person: '', email: '', phone: '', address: '', notes: '' }

function csvCell(value) {
  const text = String(value ?? '')
  const safeText = /^[\s]*[=+\-@]/.test(text) ? `'${text}` : text
  return `"${safeText.replaceAll('"', '""')}"`
}

function downloadCsv(filename, rows) {
  const content = rows.map((row) => row.map(csvCell).join(',')).join('\r\n')
  const url = URL.createObjectURL(new Blob([content], { type: 'text/csv;charset=utf-8' }))
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  anchor.click()
  URL.revokeObjectURL(url)
}

function moneyToCents(value) {
  const amount = Number(value)
  return Number.isFinite(amount) && amount >= 0 ? Math.round(amount * 100) : null
}

function ProductDialog({ product, categories, suppliers, onClose, onSave }) {
  const [form, setForm] = useState(() => product ? {
    name: product.name,
    sku: product.sku || '',
    barcode: product.barcode || '',
    description: product.description || '',
    category_id: product.category_id ? String(product.category_id) : '',
    supplier_id: product.supplier_id ? String(product.supplier_id) : '',
    price: (product.price_cents / 100).toFixed(2),
    cost: (product.cost_price_cents / 100).toFixed(2),
    stock_quantity: String(product.stock_quantity),
    reorder_level: String(product.reorder_level),
    image_path: product.image_path || '',
  } : emptyProduct)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(event) {
    event.preventDefault()
    setError('')
    const sellingPrice = moneyToCents(form.price)
    const costPrice = moneyToCents(form.cost)
    if (sellingPrice === null || costPrice === null) {
      setError('Enter valid non-negative prices.')
      return
    }
    const payload = {
      name: form.name.trim(),
      sku: form.sku.trim().toUpperCase(),
      barcode: form.barcode.trim(),
      description: form.description.trim(),
      category_id: form.category_id ? Number(form.category_id) : null,
      supplier_id: form.supplier_id ? Number(form.supplier_id) : null,
      price_cents: sellingPrice,
      cost_price_cents: costPrice,
      reorder_level: Number(form.reorder_level),
      image_path: form.image_path.trim() || null,
    }
    if (!product) payload.stock_quantity = Number(form.stock_quantity)
    setBusy(true)
    try {
      await onSave(payload)
    } catch (saveError) {
      setError(saveError.message || 'Could not save the product.')
      setBusy(false)
    }
  }

  return (
    <div className="fixed inset-0 z-[70] flex items-end justify-center bg-slate-950/45 p-0 sm:items-center sm:p-5">
      <section className="max-h-[92dvh] w-full max-w-2xl overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-2xl sm:p-6" role="dialog" aria-modal="true" aria-labelledby="product-dialog-title">
        <header className="mb-5 flex items-center justify-between">
          <div>
            <h2 id="product-dialog-title" className="text-lg font-bold text-slate-950">{product ? 'Edit product' : 'Add product'}</h2>
            <p className="mt-1 text-xs text-slate-500">{product ? 'Stock can only be changed from Stock operations.' : 'Initial stock is recorded as an opening balance.'}</p>
          </div>
          <button type="button" onClick={onClose} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="Close dialog"><X size={18} /></button>
        </header>
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
          <label className="space-y-1.5 text-xs font-semibold text-slate-600 sm:col-span-2">Product name
            <input className={inputClass} maxLength={120} required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
          </label>
          <label className="space-y-1.5 text-xs font-semibold text-slate-600">SKU
            <div className="flex gap-2">
              <input className={inputClass} maxLength={80} required value={form.sku} onChange={(event) => setForm({ ...form, sku: event.target.value })} />
              {!product && <button type="button" className="shrink-0 rounded-lg border border-slate-200 px-3 text-xs hover:bg-slate-50" onClick={() => setForm({ ...form, sku: `SKU-${globalThis.crypto?.randomUUID?.().slice(0, 8).toUpperCase() || Date.now()}` })}>Generate</button>}
            </div>
          </label>
          <label className="space-y-1.5 text-xs font-semibold text-slate-600">Barcode
            <input className={inputClass} maxLength={80} value={form.barcode} onChange={(event) => setForm({ ...form, barcode: event.target.value })} />
          </label>
          <label className="space-y-1.5 text-xs font-semibold text-slate-600">Category
            <select className={inputClass} value={form.category_id} onChange={(event) => setForm({ ...form, category_id: event.target.value })}>
              <option value="">Uncategorized</option>
              {categories.map((category) => <option key={category.id} value={category.id} disabled={!category.is_active && (!product || product.category_id !== category.id)}>{category.name}{!category.is_active ? ' (inactive)' : ''}</option>)}
            </select>
          </label>
          <label className="space-y-1.5 text-xs font-semibold text-slate-600">Supplier
            <select className={inputClass} value={form.supplier_id} onChange={(event) => setForm({ ...form, supplier_id: event.target.value })}>
              <option value="">No supplier</option>
              {suppliers.map((supplier) => <option key={supplier.id} value={supplier.id} disabled={!supplier.is_active && (!product || product.supplier_id !== supplier.id)}>{supplier.name}{!supplier.is_active ? ' (inactive)' : ''}</option>)}
            </select>
          </label>
          <label className="space-y-1.5 text-xs font-semibold text-slate-600">Selling price (PHP)
            <input className={inputClass} type="number" min="0" step="0.01" required value={form.price} onChange={(event) => setForm({ ...form, price: event.target.value })} />
          </label>
          <label className="space-y-1.5 text-xs font-semibold text-slate-600">Cost price (PHP)
            <input className={inputClass} type="number" min="0" step="0.01" required value={form.cost} onChange={(event) => setForm({ ...form, cost: event.target.value })} />
          </label>
          {!product && <label className="space-y-1.5 text-xs font-semibold text-slate-600">Initial stock
            <input className={inputClass} type="number" min="0" max="1000000" step="1" required value={form.stock_quantity} onChange={(event) => setForm({ ...form, stock_quantity: event.target.value })} />
          </label>}
          {product && <div className="space-y-1.5 text-xs font-semibold text-slate-600">Current stock
            <p className="flex h-11 items-center rounded-lg bg-slate-50 px-3 text-sm text-slate-700">{product.stock_quantity} units</p>
          </div>}
          <label className="space-y-1.5 text-xs font-semibold text-slate-600">Reorder level
            <input className={inputClass} type="number" min="0" max="1000000" step="1" required value={form.reorder_level} onChange={(event) => setForm({ ...form, reorder_level: event.target.value })} />
          </label>
          <label className="space-y-1.5 text-xs font-semibold text-slate-600 sm:col-span-2">Image URL (HTTPS)
            <input className={inputClass} type="url" value={form.image_path} onChange={(event) => setForm({ ...form, image_path: event.target.value })} placeholder="https://" />
          </label>
          <label className="space-y-1.5 text-xs font-semibold text-slate-600 sm:col-span-2">Description
            <textarea className="min-h-20 w-full rounded-lg border border-slate-200 p-3 text-sm outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100" maxLength={1000} value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} />
          </label>
          {error && <p className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800 sm:col-span-2" role="alert">{error}</p>}
          <div className="flex justify-end gap-2 sm:col-span-2">
            <button type="button" onClick={onClose} className="min-h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700">Cancel</button>
            <button type="submit" disabled={busy} className="min-h-11 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white disabled:opacity-60">{busy ? 'Saving...' : 'Save product'}</button>
          </div>
        </form>
      </section>
    </div>
  )
}

function SupplierDialog({ supplier, onClose, onSave }) {
  const [form, setForm] = useState(supplier ? {
    name: supplier.name,
    contact_person: supplier.contact_person || '',
    email: supplier.email || '',
    phone: supplier.phone || '',
    address: supplier.address || '',
    notes: supplier.notes || '',
  } : emptySupplier)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      await onSave({ ...form, name: form.name.trim() }, supplier?.id)
    } catch (saveError) {
      setError(saveError.message || 'Could not save the supplier.')
      setBusy(false)
    }
  }

  return (
    <div className="fixed inset-0 z-[70] flex items-end justify-center bg-slate-950/45 p-0 sm:items-center sm:p-5">
      <section className="w-full max-w-lg rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-2xl sm:p-6" role="dialog" aria-modal="true" aria-labelledby="supplier-dialog-title">
        <header className="mb-5 flex items-center justify-between">
          <h2 id="supplier-dialog-title" className="text-lg font-bold text-slate-950">{supplier ? 'Edit supplier' : 'Add supplier'}</h2>
          <button type="button" onClick={onClose} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="Close dialog"><X size={18} /></button>
        </header>
        <form onSubmit={submit} className="space-y-3">
          {[
            ['name', 'Supplier name', true],
            ['contact_person', 'Contact person'],
            ['email', 'Email', false, 'email'],
            ['phone', 'Phone'],
            ['address', 'Address'],
            ['notes', 'Notes'],
          ].map(([key, label, required = false, type = 'text']) => (
            <label key={key} className="block space-y-1.5 text-xs font-semibold text-slate-600">{label}
              <input className={inputClass} type={type} maxLength={key === 'notes' ? 1000 : 500} required={required} value={form[key]} onChange={(event) => setForm({ ...form, [key]: event.target.value })} />
            </label>
          ))}
          {error && <p className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800" role="alert">{error}</p>}
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="min-h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold">Cancel</button>
            <button type="submit" disabled={busy} className="min-h-11 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white disabled:opacity-60">{busy ? 'Saving...' : 'Save supplier'}</button>
          </div>
        </form>
      </section>
    </div>
  )
}

function InventoryManagementPage() {
  const { user } = useAuth()
  const currency = user.business?.currency || 'PHP'
  const location = useLocation()
  const section = sections.find((item) => location.pathname.endsWith(item.key))?.key || 'products'
  const [summary, setSummary] = useState(null)
  const [products, setProducts] = useState([])
  const [operationProducts, setOperationProducts] = useState([])
  const [categories, setCategories] = useState([])
  const [suppliers, setSuppliers] = useState([])
  const [transactions, setTransactions] = useState([])
  const [pagination, setPagination] = useState({ page: 1, pages: 1, total: 0 })
  const [historyPagination, setHistoryPagination] = useState({ page: 1, pages: 1, total: 0 })
  const [search, setSearch] = useState('')
  const [stockStatus, setStockStatus] = useState('')
  const [categoryFilter, setCategoryFilter] = useState('')
  const [supplierFilter, setSupplierFilter] = useState('')
  const [productSort, setProductSort] = useState('name')
  const [historyType, setHistoryType] = useState('')
  const [historyDateFrom, setHistoryDateFrom] = useState('')
  const [historyDateTo, setHistoryDateTo] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [productDialog, setProductDialog] = useState(null)
  const [supplierDialog, setSupplierDialog] = useState(undefined)
  const [categoryDialogOpen, setCategoryDialogOpen] = useState(false)
  const [operation, setOperation] = useState({ product_id: '', transaction_type: 'stock_in', quantity: '', supplier_id: '', reference_number: '', reason: '' })
  const [savingOperation, setSavingOperation] = useState(false)

  const refresh = useCallback(async (page = 1, requestedHistoryPage = historyPagination.page) => {
    setLoading(true)
    setError('')
    try {
      const [summaryResult, productResult, categoryResult, supplierResult, historyResult, operationProductResult] = await Promise.all([
        getInventorySummary(),
        getInventoryProducts({ page, perPage: 20, search: search.trim(), stockStatus, categoryId: categoryFilter, supplierId: supplierFilter, sort: productSort, includeInactive: true }),
        getCategories({ includeInactive: true }),
        getSuppliers({ includeInactive: true }),
        getInventoryHistory({ page: requestedHistoryPage, perPage: 20, search: search.trim(), type: historyType, dateFrom: historyDateFrom, dateTo: historyDateTo }),
        getInventoryProducts({ page: 1, perPage: 100, includeInactive: false }),
      ])
      setSummary(summaryResult.summary)
      setProducts(productResult.products)
      setPagination(productResult.pagination)
      setOperationProducts(operationProductResult.products)
      setCategories(categoryResult.categories)
      setSuppliers(supplierResult.suppliers)
      setTransactions(historyResult.transactions)
      setHistoryPagination(historyResult.pagination)
    } catch (loadError) {
      setError(loadError.message || 'Could not load inventory data.')
    } finally {
      setLoading(false)
    }
  }, [categoryFilter, historyDateFrom, historyDateTo, historyPagination.page, historyType, productSort, search, stockStatus, supplierFilter])

  useEffect(() => {
    const timer = window.setTimeout(() => { refresh(1, 1) }, 180)
    return () => window.clearTimeout(timer)
  }, [refresh])

  const activeSuppliers = useMemo(() => suppliers.filter((supplier) => supplier.is_active), [suppliers])

  async function saveProduct(payload) {
    if (productDialog?.id) await updateProduct(productDialog.id, payload)
    else await createProduct(payload)
    setProductDialog(null)
    setNotice(productDialog?.id ? 'Product updated.' : 'Product created.')
    await refresh(pagination.page)
  }

  async function archiveProduct(product) {
    if (!window.confirm(`Archive ${product.name}? Existing transactions will be preserved.`)) return
    try {
      await deactivateProduct(product.id)
      setNotice('Product archived.')
      await refresh(pagination.page)
    } catch (archiveError) {
      setError(archiveError.message || 'Could not archive the product.')
    }
  }

  async function unarchiveProduct(product) {
    try {
      await restoreProduct(product.id)
      setNotice('Product restored.')
      await refresh(pagination.page)
    } catch (restoreError) {
      setError(restoreError.message || 'Could not restore the product.')
    }
  }

  async function saveSupplierRecord(payload, id) {
    await saveSupplier(payload, id)
    setSupplierDialog(undefined)
    setNotice(id ? 'Supplier updated.' : 'Supplier created.')
    await refresh(pagination.page)
  }

  async function archiveSupplier(supplier) {
    if (!window.confirm(`Deactivate ${supplier.name}? Historical records will be preserved.`)) return
    try {
      await deactivateSupplier(supplier.id)
      setNotice('Supplier deactivated.')
      await refresh(pagination.page)
    } catch (archiveError) {
      setError(archiveError.message || 'Could not deactivate the supplier.')
    }
  }

  async function unarchiveSupplier(supplier) {
    try {
      await restoreSupplier(supplier.id)
      setNotice('Supplier reactivated.')
      await refresh(pagination.page)
    } catch (restoreError) {
      setError(restoreError.message || 'Could not reactivate the supplier.')
    }
  }

  async function submitOperation(event) {
    event.preventDefault()
    setSavingOperation(true)
    setError('')
    setNotice('')
    try {
      const result = await createInventoryOperation({
        ...operation,
        product_id: Number(operation.product_id),
        quantity: Number(operation.quantity),
        supplier_id: operation.supplier_id ? Number(operation.supplier_id) : null,
      })
      setNotice(`Stock updated: ${result.transaction.quantity_before} → ${result.transaction.quantity_after}.`)
      setOperation({ ...operation, quantity: '', reference_number: '', reason: '' })
      await refresh()
    } catch (operationError) {
      setError(operationError.message || 'Could not update stock.')
    } finally {
      setSavingOperation(false)
    }
  }

  async function exportProducts() {
    try {
      const rows = []
      let page = 1
      let pages = 1
      do {
        const result = await getInventoryProducts({ page, perPage: 100, search: search.trim(), stockStatus, categoryId: categoryFilter, supplierId: supplierFilter, sort: productSort, includeInactive: true })
        rows.push(...result.products)
        pages = result.pagination.pages
        page += 1
      } while (page <= pages)
      downloadCsv('inventory-products.csv', [
        ['Name', 'SKU', 'Category', 'Supplier', 'Quantity', 'Reorder level', 'Cost price (PHP)', 'Inventory value (PHP)', 'Status'],
        ...rows.map((product) => [product.name, product.sku, product.category_name, product.supplier_name, product.stock_quantity, product.reorder_level, formatMoney(product.cost_price_cents, currency), formatMoney(product.inventory_value_cents, currency), product.stock_status]),
      ])
    } catch (exportError) {
      setError(exportError.message || 'Could not export products.')
    }
  }

  async function exportHistory() {
    try {
      const rows = []
      let page = 1
      let pages = 1
      do {
        const result = await getInventoryHistory({ page, perPage: 100, search: search.trim(), type: historyType, dateFrom: historyDateFrom, dateTo: historyDateTo })
        rows.push(...result.transactions)
        pages = result.pagination.pages
        page += 1
      } while (page <= pages)
      downloadCsv('inventory-history.csv', [
        ['Date', 'Product', 'SKU', 'Type', 'Change', 'Before', 'After', 'Reason', 'Reference', 'Supplier', 'Performed by'],
        ...rows.map((item) => [item.created_at, item.product_name, item.sku, item.transaction_type, item.quantity_change, item.quantity_before, item.quantity_after, item.reason, item.reference_number, item.supplier_name, item.performed_by]),
      ])
    } catch (exportError) {
      setError(exportError.message || 'Could not export stock history.')
    }
  }

  return (
    <div className="space-y-6">
      <header className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div>
          <p className="mb-1 text-xs font-bold uppercase tracking-[1px] text-orange-700">Inventory workspace</p>
          <h1 className="text-2xl font-bold text-slate-950">Products & inventory</h1>
          <p className="mt-1 text-sm text-slate-500">Manage catalog, suppliers, stock movements, and valuation.</p>
        </div>
        {section === 'products' && <div className="flex gap-2"><button type="button" onClick={() => setCategoryDialogOpen(true)} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700">Manage categories</button><button type="button" onClick={() => setProductDialog({})} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white"><Plus size={16} />Add product</button></div>}
        {section === 'suppliers' && <button type="button" onClick={() => setSupplierDialog(null)} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white"><Plus size={16} />Add supplier</button>}
      </header>

      <nav className="flex gap-1 overflow-x-auto rounded-xl border border-slate-200 bg-white p-1.5" aria-label="Inventory sections">
        {sections.map((item) => <Link key={item.key} to={item.path} className={`shrink-0 rounded-lg px-3 py-2.5 text-sm font-semibold ${section === item.key ? 'bg-orange-50 text-orange-800' : 'text-slate-500 hover:bg-slate-50'}`}>{item.label}</Link>)}
      </nav>

      {summary && <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5" aria-label="Inventory summary">
        {[
          ['Active products', summary.total_products, Package],
          ['Categories', summary.total_categories, Package],
          ['Suppliers', summary.total_suppliers, Truck],
          ['Low stock', summary.low_stock, AlertTriangle],
          ['Inventory at cost', formatMoney(summary.inventory_value_cents, currency), ArrowDownToLine],
        ].map(([label, value, Icon]) => <article key={label} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="flex items-center justify-between gap-2"><p className="text-xs font-medium text-slate-500">{label}</p><Icon size={17} className="text-orange-700" /></div>
          <p className="mt-2 text-xl font-bold text-slate-950">{value}</p>
          {label === 'Low stock' && <p className="mt-1 text-xs text-slate-500">{summary.out_of_stock} out of stock</p>}
        </article>)}
      </section>}

      {error && <p className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{error}</p>}
      {notice && <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{notice}</p>}

      {section === 'products' && <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="relative min-w-0 flex-1 sm:max-w-md"><Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" /><input className={`${inputClass} pl-9`} placeholder="Search product, SKU, or barcode" value={search} onChange={(event) => setSearch(event.target.value)} /></div>
          <div className="flex gap-2">
            <select className={`${inputClass} min-w-40`} value={stockStatus} onChange={(event) => setStockStatus(event.target.value)} aria-label="Filter stock status"><option value="">All stock statuses</option><option value="in_stock">In stock</option><option value="low_stock">Low stock</option><option value="out_of_stock">Out of stock</option></select>
            <select className={inputClass} value={categoryFilter} onChange={(event) => setCategoryFilter(event.target.value)} aria-label="Filter category"><option value="">All categories</option>{categories.filter((category) => category.is_active).map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select>
            <select className={inputClass} value={supplierFilter} onChange={(event) => setSupplierFilter(event.target.value)} aria-label="Filter supplier"><option value="">All suppliers</option>{activeSuppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</select>
            <select className={inputClass} value={productSort} onChange={(event) => setProductSort(event.target.value)} aria-label="Sort products"><option value="name">Sort: name</option><option value="price">Sort: selling price</option><option value="cost">Sort: cost price</option><option value="quantity">Sort: quantity</option><option value="updated_at">Sort: update date</option></select>
            <button type="button" onClick={exportProducts} className="inline-flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-700"><Download size={16} />Export</button>
          </div>
        </div>
        {loading ? <p className="p-8 text-center text-sm text-slate-500" role="status">Loading products...</p> : products.length === 0 ? <p className="p-8 text-center text-sm text-slate-500">No products match these filters.</p> : <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-3">Product</th><th className="px-4 py-3">Category / supplier</th><th className="px-4 py-3">Cost</th><th className="px-4 py-3">Quantity</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Inventory value</th><th className="px-4 py-3">Actions</th></tr></thead>
            <tbody className="divide-y divide-slate-100">{products.map((product) => <tr key={product.id} className={product.is_active ? '' : 'bg-slate-50 text-slate-400'}>
              <td className="px-4 py-3"><p className="font-semibold text-slate-900">{product.name}</p><p className="mt-0.5 text-xs text-slate-500">{product.sku || 'No SKU'}{product.barcode ? ` · ${product.barcode}` : ''}</p></td>
              <td className="px-4 py-3"><p>{product.category_name || '—'}</p><p className="mt-0.5 text-xs text-slate-500">{product.supplier_name || 'No supplier'}</p></td>
              <td className="px-4 py-3">{formatMoney(product.cost_price_cents, currency)}</td>
              <td className="px-4 py-3">{product.stock_quantity}<span className="text-xs text-slate-400"> / reorder {product.reorder_level}</span></td>
              <td className="px-4 py-3"><span className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ${!product.is_active ? 'bg-slate-100 text-slate-500' : product.stock_status === 'out_of_stock' ? 'bg-rose-50 text-rose-700' : product.stock_status === 'low_stock' ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'}`}>{product.is_active ? product.stock_status.replaceAll('_', ' ') : 'archived'}</span></td>
              <td className="px-4 py-3">{formatMoney(product.inventory_value_cents, currency)}</td>
              <td className="px-4 py-3"><div className="flex gap-2">{product.is_active && <><button type="button" onClick={() => setProductDialog(product)} className="rounded-md px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">Edit</button><button type="button" onClick={() => archiveProduct(product)} className="rounded-md px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">Archive</button></>}{!product.is_active && <button type="button" onClick={() => unarchiveProduct(product)} className="rounded-md px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Restore</button>}</div></td>
            </tr>)}</tbody>
          </table>
        </div>}
        <footer className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-xs text-slate-500">
          <span>{pagination.total} products · page {pagination.page} of {Math.max(1, pagination.pages)}</span>
          <div className="flex gap-2"><button type="button" disabled={pagination.page <= 1 || loading} onClick={() => refresh(pagination.page - 1)} className="min-h-9 rounded-md border border-slate-200 px-3 disabled:opacity-40">Previous</button><button type="button" disabled={pagination.page >= pagination.pages || loading} onClick={() => refresh(pagination.page + 1)} className="min-h-9 rounded-md border border-slate-200 px-3 disabled:opacity-40">Next</button></div>
        </footer>
      </section>}

      {section === 'stock' && <section className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(260px,0.8fr)]">
        <form onSubmit={submitOperation} className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <div><h2 className="font-bold text-slate-950">Record a stock movement</h2><p className="mt-1 text-xs text-slate-500">Stock and its immutable history entry are saved atomically.</p></div>
          <label className="block space-y-1.5 text-xs font-semibold text-slate-600">Product
            <select required className={inputClass} value={operation.product_id} onChange={(event) => setOperation({ ...operation, product_id: event.target.value })}><option value="">Select product</option>{operationProducts.map((product) => <option key={product.id} value={product.id}>{product.name} · {product.stock_quantity} in stock</option>)}</select>
          </label>
          <label className="block space-y-1.5 text-xs font-semibold text-slate-600">Operation
            <select className={inputClass} value={operation.transaction_type} onChange={(event) => setOperation({ ...operation, transaction_type: event.target.value })}>
              <option value="stock_in">Receive stock</option><option value="stock_out">Issue stock</option><option value="adjustment_increase">Adjustment increase</option><option value="adjustment_decrease">Adjustment decrease</option>
            </select>
          </label>
          <label className="block space-y-1.5 text-xs font-semibold text-slate-600">Quantity
            <input className={inputClass} type="number" min="1" max="1000000" step="1" required value={operation.quantity} onChange={(event) => setOperation({ ...operation, quantity: event.target.value })} />
          </label>
          {operation.transaction_type === 'stock_in' && <label className="block space-y-1.5 text-xs font-semibold text-slate-600">Supplier
            <select className={inputClass} value={operation.supplier_id} onChange={(event) => setOperation({ ...operation, supplier_id: event.target.value })}><option value="">No supplier</option>{activeSuppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</select>
          </label>}
          <label className="block space-y-1.5 text-xs font-semibold text-slate-600">Reference number
            <input className={inputClass} maxLength={100} value={operation.reference_number} onChange={(event) => setOperation({ ...operation, reference_number: event.target.value })} />
          </label>
          <label className="block space-y-1.5 text-xs font-semibold text-slate-600">Reason / notes {operation.transaction_type !== 'stock_in' && <span aria-hidden="true">*</span>}
            <textarea required={operation.transaction_type !== 'stock_in'} className="min-h-20 w-full rounded-lg border border-slate-200 p-3 text-sm outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100" maxLength={500} value={operation.reason} onChange={(event) => setOperation({ ...operation, reason: event.target.value })} />
          </label>
          <button disabled={savingOperation} className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white disabled:opacity-60">{operation.transaction_type.includes('increase') || operation.transaction_type === 'stock_in' ? <ArrowDownToLine size={16} /> : <ArrowUpFromLine size={16} />}{savingOperation ? 'Saving stock movement...' : 'Save movement'}</button>
        </form>
        <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 className="font-bold text-slate-950">Stock status</h2>
          <div className="rounded-lg bg-amber-50 p-4"><p className="text-xs font-semibold text-amber-800">Low stock</p><p className="mt-1 text-2xl font-bold text-amber-900">{summary?.low_stock ?? '—'}</p><p className="mt-1 text-xs text-amber-800">Products at or below their reorder level.</p></div>
          <div className="rounded-lg bg-rose-50 p-4"><p className="text-xs font-semibold text-rose-800">Out of stock</p><p className="mt-1 text-2xl font-bold text-rose-900">{summary?.out_of_stock ?? '—'}</p><p className="mt-1 text-xs text-rose-800">Active products with zero available units.</p></div>
          <Link to="/dashboard/inventory/history" className="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700">View stock history</Link>
        </div>
      </section>}

      {section === 'suppliers' && <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {loading ? <p className="p-8 text-center text-sm text-slate-500" role="status">Loading suppliers...</p> : suppliers.length === 0 ? <p className="p-8 text-center text-sm text-slate-500">No suppliers yet.</p> : <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-3">Supplier</th><th className="px-4 py-3">Contact</th><th className="px-4 py-3">Email / phone</th><th className="px-4 py-3">Actions</th></tr></thead>
            <tbody className="divide-y divide-slate-100">{suppliers.map((supplier) => <tr key={supplier.id}>
              <td className="px-4 py-3 font-semibold text-slate-900">{supplier.name}{!supplier.is_active && <span className="ml-2 rounded-full bg-slate-100 px-2 py-1 text-[10px] text-slate-500">Inactive</span>}</td><td className="px-4 py-3">{supplier.contact_person || '—'}</td><td className="px-4 py-3"><p>{supplier.email || '—'}</p><p className="mt-0.5 text-xs text-slate-500">{supplier.phone || ''}</p></td>
              <td className="px-4 py-3">{supplier.is_active ? <><button type="button" onClick={() => setSupplierDialog(supplier)} className="rounded-md px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">Edit</button><button type="button" onClick={() => archiveSupplier(supplier)} className="rounded-md px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">Deactivate</button></> : <button type="button" onClick={() => unarchiveSupplier(supplier)} className="rounded-md px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Reactivate</button>}</td>
            </tr>)}</tbody>
          </table>
        </div>}
      </section>}

      {section === 'history' && <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="relative min-w-0 flex-1 sm:max-w-md"><Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" /><input className={`${inputClass} pl-9`} placeholder="Search product or SKU" value={search} onChange={(event) => setSearch(event.target.value)} /></div>
          <div className="flex flex-wrap gap-2"><select className={inputClass} value={historyType} onChange={(event) => setHistoryType(event.target.value)} aria-label="Filter transaction type"><option value="">All movements</option>{['stock_in', 'stock_out', 'adjustment_increase', 'adjustment_decrease', 'opening_balance', 'sale', 'void', 'return'].map((type) => <option key={type} value={type}>{type.replaceAll('_', ' ')}</option>)}</select><input className={inputClass} type="date" value={historyDateFrom} onChange={(event) => setHistoryDateFrom(event.target.value)} aria-label="History from date" /><input className={inputClass} type="date" value={historyDateTo} onChange={(event) => setHistoryDateTo(event.target.value)} aria-label="History to date" /><button type="button" onClick={exportHistory} className="inline-flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-700"><Download size={16} />Export</button></div>
        </div>
        {loading ? <p className="p-8 text-center text-sm text-slate-500" role="status">Loading history...</p> : transactions.length === 0 ? <p className="p-8 text-center text-sm text-slate-500">No stock movements found.</p> : <div className="overflow-x-auto">
          <table className="w-full min-w-[920px] text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-3">Date</th><th className="px-4 py-3">Product</th><th className="px-4 py-3">Movement</th><th className="px-4 py-3">Before → after</th><th className="px-4 py-3">Reference / reason</th><th className="px-4 py-3">Performed by</th></tr></thead>
            <tbody className="divide-y divide-slate-100">{transactions.map((item) => <tr key={item.id}>
              <td className="whitespace-nowrap px-4 py-3 text-xs text-slate-500">{new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(`${item.created_at.replace(' ', 'T')}Z`))}</td>
              <td className="px-4 py-3"><p className="font-semibold text-slate-900">{item.product_name}</p><p className="mt-0.5 text-xs text-slate-500">{item.sku || 'No SKU'}</p></td>
              <td className="px-4 py-3"><p className="capitalize">{item.transaction_type.replaceAll('_', ' ')}</p><p className={`mt-0.5 text-xs font-semibold ${item.quantity_change > 0 ? 'text-emerald-700' : 'text-rose-700'}`}>{item.quantity_change > 0 ? '+' : ''}{item.quantity_change}</p></td>
              <td className="px-4 py-3">{item.quantity_before === null ? '—' : `${item.quantity_before} → ${item.quantity_after}`}</td>
              <td className="px-4 py-3"><p>{item.reference_number || '—'}</p><p className="mt-0.5 max-w-56 truncate text-xs text-slate-500">{item.reason || item.supplier_name || '—'}</p></td>
              <td className="px-4 py-3">{item.performed_by}</td>
            </tr>)}</tbody>
          </table>
        </div>}
        <footer className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-xs text-slate-500">
          <span>{historyPagination.total} movements · page {historyPagination.page} of {Math.max(1, historyPagination.pages)}</span>
          <div className="flex gap-2"><button type="button" disabled={historyPagination.page <= 1 || loading} onClick={() => refresh(pagination.page, historyPagination.page - 1)} className="min-h-9 rounded-md border border-slate-200 px-3 disabled:opacity-40">Previous</button><button type="button" disabled={historyPagination.page >= historyPagination.pages || loading} onClick={() => refresh(pagination.page, historyPagination.page + 1)} className="min-h-9 rounded-md border border-slate-200 px-3 disabled:opacity-40">Next</button></div>
        </footer>
      </section>}

      {productDialog !== null && <ProductDialog product={productDialog.id ? productDialog : null} categories={categories} suppliers={suppliers} onClose={() => setProductDialog(null)} onSave={saveProduct} />}
      {supplierDialog !== undefined && <SupplierDialog supplier={supplierDialog} onClose={() => setSupplierDialog(undefined)} onSave={saveSupplierRecord} />}
      {categoryDialogOpen && <CategoryManager onClose={() => setCategoryDialogOpen(false)} onChanged={() => refresh(pagination.page)} />}
    </div>
  )
}

export default InventoryManagementPage
