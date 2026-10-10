import { useCallback, useEffect, useState } from 'react'
import { getPharmacyBatches, getPharmacyMedicines, receivePharmacyBatch } from '../services/api.js'

function newReceiptKey() {
  const id = globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`
  return `pharmacy-receipt:${id}`
}

function PharmacyBatchesPage() {
  const [medicines, setMedicines] = useState([])
  const [batches, setBatches] = useState([])
  const [form, setForm] = useState({
    product_id: '',
    batch_number: '',
    expires_on: '',
    manufactured_on: '',
    quantity: '',
    purchase_cost_cents: '',
    reference_number: '',
    supplier_id: '',
    receipt_key: newReceiptKey(),
  })
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState('')
  const [feedback, setFeedback] = useState('')

  const refresh = useCallback(async () => {
    setIsLoading(true)
    try {
      const [medicineResult, batchResult] = await Promise.all([getPharmacyMedicines(), getPharmacyBatches()])
      setMedicines(medicineResult.medicines)
      setBatches(batchResult.batches)
      setError('')
    } catch (loadError) {
      setError(loadError.message || 'Could not load batch inventory.')
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    let active = true
    Promise.all([getPharmacyMedicines(), getPharmacyBatches()])
      .then(([medicineResult, batchResult]) => {
        if (!active) return
        setMedicines(medicineResult.medicines)
        setBatches(batchResult.batches)
      })
      .catch((loadError) => {
        if (active) setError(loadError.message || 'Could not load batch inventory.')
      })
      .finally(() => {
        if (active) setIsLoading(false)
      })
    return () => { active = false }
  }, [])

  function update(field, value) {
    setForm((current) => ({ ...current, [field]: value }))
    setFeedback('')
  }

  async function submit(event) {
    event.preventDefault()
    setIsSaving(true)
    setError('')
    setFeedback('')
    try {
      const result = await receivePharmacyBatch({
        ...form,
        product_id: Number(form.product_id),
        supplier_id: form.supplier_id ? Number(form.supplier_id) : null,
        quantity: Number(form.quantity),
        purchase_cost_cents: Number(form.purchase_cost_cents),
      })
      setFeedback(result.replayed ? 'This delivery was already recorded; no additional stock was added.' : 'Batch received and stock ledger updated.')
      setForm((current) => ({
        ...current,
        batch_number: '',
        expires_on: '',
        manufactured_on: '',
        quantity: '',
        purchase_cost_cents: '',
        reference_number: '',
        receipt_key: newReceiptKey(),
      }))
      await refresh()
    } catch (saveError) {
      setError(saveError.message || 'Could not receive the batch.')
    } finally {
      setIsSaving(false)
    }
  }

  const trackedMedicines = medicines.filter((medicine) => medicine.pharmacy_detail_id && medicine.batch_tracking)

  return (
    <div className="space-y-6">
      <header>
        <p className="mb-1 text-xs font-bold uppercase tracking-[1px] text-emerald-700">Pharmacy inventory</p>
        <h1 className="text-2xl font-bold text-slate-950">Medicine batches</h1>
        <p className="mt-1 text-sm text-slate-500">Receive lot-level stock with expiry dates. Batch and shared inventory ledgers update together.</p>
      </header>

      <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div>
          <h2 className="text-sm font-bold text-slate-900">Receive medicine batch</h2>
          <p className="mt-1 text-xs text-slate-500">Receipt keys make retries safe. A successful receipt gets a new key for the next delivery.</p>
        </div>
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <div>
            <label htmlFor="batch-product" className="mb-1.5 block text-xs font-semibold text-slate-700">Medicine</label>
            <select id="batch-product" required value={form.product_id} onChange={(event) => update('product_id', event.target.value)} className="min-h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
              <option value="">Select medicine</option>
              {trackedMedicines.map((medicine) => <option key={medicine.id} value={medicine.id}>{medicine.name} · {medicine.sku}</option>)}
            </select>
          </div>
          <div>
            <label htmlFor="batch-number" className="mb-1.5 block text-xs font-semibold text-slate-700">Batch / lot number</label>
            <input id="batch-number" required maxLength={80} value={form.batch_number} onChange={(event) => update('batch_number', event.target.value)} className="min-h-11 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100" />
          </div>
          <div>
            <label htmlFor="batch-expiry" className="mb-1.5 block text-xs font-semibold text-slate-700">Expiry date</label>
            <input id="batch-expiry" required type="date" value={form.expires_on} onChange={(event) => update('expires_on', event.target.value)} className="min-h-11 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100" />
          </div>
          <div>
            <label htmlFor="batch-manufactured" className="mb-1.5 block text-xs font-semibold text-slate-700">Manufacturing date (optional)</label>
            <input id="batch-manufactured" type="date" value={form.manufactured_on} onChange={(event) => update('manufactured_on', event.target.value)} className="min-h-11 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100" />
          </div>
          <div>
            <label htmlFor="batch-quantity" className="mb-1.5 block text-xs font-semibold text-slate-700">Accepted quantity</label>
            <input id="batch-quantity" required type="number" min="1" max="1000000" step="1" value={form.quantity} onChange={(event) => update('quantity', event.target.value)} className="min-h-11 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100" />
          </div>
          <div>
            <label htmlFor="batch-cost" className="mb-1.5 block text-xs font-semibold text-slate-700">Purchase cost per unit (minor currency units)</label>
            <input id="batch-cost" required type="number" min="0" max="1000000000" step="1" value={form.purchase_cost_cents} onChange={(event) => update('purchase_cost_cents', event.target.value)} className="min-h-11 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100" />
          </div>
          <div>
            <label htmlFor="batch-reference" className="mb-1.5 block text-xs font-semibold text-slate-700">Delivery / invoice reference</label>
            <input id="batch-reference" maxLength={100} value={form.reference_number} onChange={(event) => update('reference_number', event.target.value)} className="min-h-11 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100" />
          </div>
          <div>
            <label htmlFor="batch-manufacturer" className="mb-1.5 block text-xs font-semibold text-slate-700">Manufacturer (optional)</label>
            <input id="batch-manufacturer" maxLength={200} value={form.manufacturer || ''} onChange={(event) => update('manufacturer', event.target.value)} className="min-h-11 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100" />
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <button type="submit" disabled={isSaving || trackedMedicines.length === 0} className="min-h-10 rounded-lg bg-emerald-700 px-4 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">{isSaving ? 'Receiving…' : 'Receive batch'}</button>
          {feedback && <p className="text-sm text-emerald-700" role="status">{feedback}</p>}
        </div>
      </form>

      {error && <p className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{error}</p>}

      <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="border-b border-slate-100 px-4 py-4 sm:px-5">
          <h2 className="text-sm font-bold text-slate-900">Batch inventory</h2>
          <p className="mt-1 text-xs text-slate-500">All batches are shown, including empty and expired lots for traceability.</p>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[880px] text-left text-sm">
            <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
              <tr><th className="px-4 py-3 font-semibold">Medicine</th><th className="px-4 py-3 font-semibold">Batch</th><th className="px-4 py-3 font-semibold">Supplier</th><th className="px-4 py-3 font-semibold">Expiry</th><th className="px-4 py-3 text-right font-semibold">Received</th><th className="px-4 py-3 text-right font-semibold">Available</th><th className="px-4 py-3 font-semibold">Status</th></tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {batches.map((batch) => {
                const expired = batch.is_expired === 1
                return (
                  <tr key={batch.id}>
                    <td className="px-4 py-3"><p className="font-semibold text-slate-900">{batch.product_name}</p><p className="text-xs text-slate-500">{batch.sku}</p></td>
                    <td className="px-4 py-3 font-medium text-slate-700">{batch.batch_number}</td>
                    <td className="px-4 py-3 text-slate-600">{batch.supplier_name || '—'}</td>
                    <td className={`px-4 py-3 ${expired ? 'font-semibold text-rose-700' : 'text-slate-600'}`}>{batch.expires_on}{expired && <span className="ml-2 text-[10px] uppercase">Expired</span>}</td>
                    <td className="px-4 py-3 text-right tabular-nums">{batch.quantity_received.toLocaleString()}</td>
                    <td className="px-4 py-3 text-right font-semibold tabular-nums">{batch.available_quantity.toLocaleString()}</td>
                    <td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-[10px] font-bold uppercase ${batch.status === 'available' && !expired ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'}`}>{expired ? 'expired' : batch.status}</span></td>
                  </tr>
                )
              })}
              {!isLoading && batches.length === 0 && <tr><td colSpan={7} className="px-5 py-10 text-center text-sm text-slate-400">No medicine batches have been received.</td></tr>}
            </tbody>
          </table>
        </div>
        {isLoading && <p className="border-t border-slate-100 px-5 py-3 text-xs text-slate-500" role="status">Refreshing batches…</p>}
      </section>
    </div>
  )
}

export default PharmacyBatchesPage
