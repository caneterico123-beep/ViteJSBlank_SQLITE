import { useCallback, useEffect, useState } from 'react'
import { Search } from 'lucide-react'
import { useAuth } from '../contexts/useAuth.js'
import InputField from '../components/InputField.jsx'
import { getPharmacyMedicines, savePharmacyMedicineDetails } from '../services/api.js'

const emptyForm = {
  product_id: '',
  generic_name: '',
  brand_name: '',
  manufacturer: '',
  active_ingredients: '',
  strength: '',
  dosage_form: '',
  route: '',
  package_size: '',
  unit_of_measure: 'unit',
  prescription_required: false,
  controlled_classification: 'none',
  storage_instructions: '',
  cold_chain: false,
  batch_tracking: true,
  warnings: '',
}

function PharmacyMedicinesPage() {
  const { user } = useAuth()
  const canManage = ['owner', 'admin', 'manager'].includes(user.role)
  const [medicines, setMedicines] = useState([])
  const [search, setSearch] = useState('')
  const [form, setForm] = useState(emptyForm)
  const [editingId, setEditingId] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState('')
  const [feedback, setFeedback] = useState('')

  const refresh = useCallback(async (query = '') => {
    setIsLoading(true)
    try {
      const result = await getPharmacyMedicines(query)
      setMedicines(result.medicines)
      setError('')
    } catch (loadError) {
      setError(loadError.message || 'Could not load pharmacy medicines.')
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    let active = true
    getPharmacyMedicines()
      .then((result) => {
        if (active) setMedicines(result.medicines)
      })
      .catch((loadError) => {
        if (active) setError(loadError.message || 'Could not load pharmacy medicines.')
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

  function startEdit(medicine) {
    setEditingId(medicine.pharmacy_detail_id)
    setForm({
      ...emptyForm,
      product_id: String(medicine.id),
      generic_name: medicine.generic_name || '',
      brand_name: medicine.brand_name || '',
      manufacturer: medicine.manufacturer || '',
      active_ingredients: medicine.active_ingredients || '',
      strength: medicine.strength || '',
      dosage_form: medicine.dosage_form || '',
      route: medicine.route || '',
      package_size: medicine.package_size || '',
      unit_of_measure: medicine.unit_of_measure || 'unit',
      prescription_required: medicine.prescription_required,
      controlled_classification: medicine.controlled_classification || 'none',
      storage_instructions: medicine.storage_instructions || '',
      cold_chain: medicine.cold_chain,
      batch_tracking: medicine.batch_tracking,
      warnings: medicine.warnings || '',
    })
    setFeedback('')
  }

  function resetForm() {
    setEditingId(null)
    setForm(emptyForm)
  }

  async function save(event) {
    event.preventDefault()
    setIsSaving(true)
    setError('')
    setFeedback('')
    try {
      await savePharmacyMedicineDetails({ ...form, product_id: Number(form.product_id) }, editingId)
      setFeedback('Pharmacy medicine details saved.')
      resetForm()
      await refresh('')
    } catch (saveError) {
      setError(saveError.message || 'Could not save pharmacy medicine details.')
    } finally {
      setIsSaving(false)
    }
  }

  const unconfigured = medicines.filter((medicine) => !medicine.pharmacy_detail_id)

  return (
    <div className="space-y-6">
      <header>
        <p className="mb-1 text-xs font-bold uppercase tracking-[1px] text-emerald-700">Pharmacy catalog</p>
        <h1 className="text-2xl font-bold text-slate-950">Medicines</h1>
        <p className="mt-1 text-sm text-slate-500">Pharmacy information extends existing BizFlow products; prices, categories, suppliers, and inventory remain shared.</p>
      </header>

      {canManage && (
        <form onSubmit={save} className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
          <div>
            <h2 className="text-sm font-bold text-slate-900">{editingId ? 'Edit medicine information' : 'Configure a product as a medicine'}</h2>
            <p className="mt-1 text-xs text-slate-500">Only products without pharmacy details are selectable when creating a medicine.</p>
          </div>
          <div>
            <label htmlFor="pharmacy-medicine-product" className="mb-1.5 block text-xs font-semibold text-slate-700">Existing product</label>
            <select id="pharmacy-medicine-product" required disabled={Boolean(editingId)} value={form.product_id} onChange={(event) => update('product_id', event.target.value)} className="min-h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-100">
              <option value="">Select a shared product</option>
              {(editingId ? medicines.filter((medicine) => medicine.id === Number(form.product_id)) : unconfigured).map((medicine) => (
                <option key={medicine.id} value={medicine.id}>{medicine.name} · {medicine.sku}</option>
              ))}
            </select>
          </div>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <InputField id="pharmacy-generic-name" label="Generic name" value={form.generic_name} onChange={(event) => update('generic_name', event.target.value)} placeholder="Generic medicine name" />
            <InputField id="pharmacy-brand-name" label="Brand name" value={form.brand_name} onChange={(event) => update('brand_name', event.target.value)} placeholder="Brand, if applicable" />
            <InputField id="pharmacy-manufacturer" label="Manufacturer" value={form.manufacturer} onChange={(event) => update('manufacturer', event.target.value)} placeholder="Manufacturer" />
            <InputField id="pharmacy-ingredients" label="Active ingredients" value={form.active_ingredients} onChange={(event) => update('active_ingredients', event.target.value)} placeholder="Enter validated ingredient information" />
            <InputField id="pharmacy-strength" label="Strength / concentration" value={form.strength} onChange={(event) => update('strength', event.target.value)} placeholder="e.g. 10 mg" />
            <InputField id="pharmacy-dosage-form" label="Dosage form" value={form.dosage_form} onChange={(event) => update('dosage_form', event.target.value)} placeholder="tablet, capsule, syrup…" />
            <InputField id="pharmacy-route" label="Route" value={form.route} onChange={(event) => update('route', event.target.value)} placeholder="e.g. oral" />
            <InputField id="pharmacy-package-size" label="Package size" value={form.package_size} onChange={(event) => update('package_size', event.target.value)} placeholder="e.g. 10 tablets" />
            <InputField id="pharmacy-unit" label="Stock unit" value={form.unit_of_measure} onChange={(event) => update('unit_of_measure', event.target.value)} placeholder="tablet, bottle, box…" />
            <InputField id="pharmacy-controlled-class" label="Controlled classification" value={form.controlled_classification} onChange={(event) => update('controlled_classification', event.target.value)} placeholder="none or configured class" />
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" checked={form.prescription_required} onChange={(event) => update('prescription_required', event.target.checked)} className="size-4 accent-emerald-700" />
              Prescription required (checkout will block until dispensing is implemented)
            </label>
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" checked={form.cold_chain} onChange={(event) => update('cold_chain', event.target.checked)} className="size-4 accent-emerald-700" />
              Cold-chain / temperature-sensitive
            </label>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="pharmacy-storage-instructions" className="mb-1.5 block text-xs font-semibold text-slate-700">Storage instructions</label>
              <textarea id="pharmacy-storage-instructions" value={form.storage_instructions} onChange={(event) => update('storage_instructions', event.target.value)} rows={2} maxLength={500} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100" />
            </div>
            <div>
              <label htmlFor="pharmacy-product-warnings" className="mb-1.5 block text-xs font-semibold text-slate-700">Product warnings</label>
              <textarea id="pharmacy-product-warnings" value={form.warnings} onChange={(event) => update('warnings', event.target.value)} rows={2} maxLength={1000} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100" />
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <button type="submit" disabled={isSaving || !form.product_id} className="min-h-10 rounded-lg bg-emerald-700 px-4 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">{isSaving ? 'Saving…' : editingId ? 'Save medicine details' : 'Add medicine details'}</button>
            {editingId && <button type="button" onClick={resetForm} className="min-h-10 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel edit</button>}
            {feedback && <p className="text-sm text-emerald-700" role="status">{feedback}</p>}
          </div>
        </form>
      )}

      {error && <p className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{error}</p>}

      <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-end sm:justify-between sm:px-5">
          <div>
            <h2 className="text-sm font-bold text-slate-900">Shared pharmacy products</h2>
            <p className="mt-1 text-xs text-slate-500">Search by product, generic/brand name, ingredient, SKU, or barcode.</p>
          </div>
          <form onSubmit={(event) => { event.preventDefault(); refresh(search) }} className="flex gap-2">
            <label htmlFor="pharmacy-medicine-search" className="sr-only">Search medicines</label>
            <div className="flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 px-3 focus-within:border-emerald-600">
              <Search size={15} className="text-slate-400" aria-hidden="true" />
              <input id="pharmacy-medicine-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search medicines" className="w-full min-w-0 text-sm outline-none sm:w-56" />
            </div>
            <button className="min-h-10 rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Search</button>
          </form>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[880px] text-left text-sm">
            <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
              <tr><th className="px-4 py-3 font-semibold">Medicine</th><th className="px-4 py-3 font-semibold">Brand / strength</th><th className="px-4 py-3 font-semibold">Form</th><th className="px-4 py-3 font-semibold">Identifiers</th><th className="px-4 py-3 text-right font-semibold">Eligible stock</th><th className="px-4 py-3 font-semibold">Next expiry</th><th className="px-4 py-3 font-semibold">Requirements</th>{canManage && <th className="px-4 py-3 font-semibold">Action</th>}</tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {medicines.map((medicine) => (
                <tr key={medicine.id}>
                  <td className="px-4 py-3"><p className="font-semibold text-slate-900">{medicine.name}</p><p className="text-xs text-slate-500">{medicine.generic_name || 'Pharmacy details not configured'}</p></td>
                  <td className="px-4 py-3 text-slate-600">{medicine.brand_name || '—'}{medicine.strength ? ` · ${medicine.strength}` : ''}</td>
                  <td className="px-4 py-3 text-slate-600">{medicine.dosage_form || '—'}</td>
                  <td className="px-4 py-3 text-xs text-slate-500">{medicine.sku}{medicine.barcode ? ` · ${medicine.barcode}` : ''}</td>
                  <td className="px-4 py-3 text-right font-semibold tabular-nums">{medicine.eligible_batch_stock}</td>
                  <td className="px-4 py-3 text-slate-600">{medicine.nearest_expiry || '—'}</td>
                  <td className="px-4 py-3"><div className="flex flex-wrap gap-1">{medicine.pharmacy_detail_id ? (
                    <>
                      {medicine.prescription_required && <span className="rounded-full bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-800">Prescription</span>}
                      {medicine.cold_chain && <span className="rounded-full bg-sky-50 px-2 py-1 text-[10px] font-semibold text-sky-800">Cold chain</span>}
                      <span className="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-semibold text-emerald-800">Batch tracked</span>
                    </>
                  ) : <span className="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-semibold text-slate-600">Setup needed</span>}</div></td>
                  {canManage && <td className="px-4 py-3">{medicine.pharmacy_detail_id && <button type="button" onClick={() => startEdit(medicine)} className="text-xs font-semibold text-emerald-700 hover:text-emerald-900">Edit</button>}</td>}
                </tr>
              ))}
              {!isLoading && medicines.length === 0 && <tr><td colSpan={canManage ? 8 : 7} className="px-5 py-10 text-center text-sm text-slate-400">No products match this search.</td></tr>}
            </tbody>
          </table>
        </div>
        {isLoading && <p className="border-t border-slate-100 px-5 py-3 text-xs text-slate-500" role="status">Refreshing medicines…</p>}
      </section>
    </div>
  )
}

export default PharmacyMedicinesPage
