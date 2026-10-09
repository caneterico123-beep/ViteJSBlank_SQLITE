import { useEffect, useState } from 'react'
import { Plus, X } from 'lucide-react'
import { createCategory, deactivateCategory, getCategories, updateCategory } from '../../services/api.js'

function CategoryManager({ onClose, onChanged }) {
  const [categories, setCategories] = useState([])
  const [newName, setNewName] = useState('')
  const [names, setNames] = useState({})
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [busyId, setBusyId] = useState(null)

  async function refresh() {
    setIsLoading(true)
    try {
      const result = await getCategories({ includeInactive: true })
      setCategories(result.categories)
      setNames(Object.fromEntries(result.categories.map((category) => [category.id, category.name])))
      onChanged()
    } catch (loadError) {
      setError(loadError.message || 'Could not load categories.')
    } finally {
      setIsLoading(false)
    }
  }

  useEffect(() => {
    refresh()
  }, [])

  async function handleCreate(event) {
    event.preventDefault()
    setError('')
    setMessage('')
    try {
      await createCategory(newName.trim())
      setNewName('')
      setMessage('Category created.')
      await refresh()
    } catch (createError) {
      setError(createError.message || 'Could not create category.')
    }
  }

  async function handleSave(category) {
    setBusyId(category.id)
    setError('')
    setMessage('')
    try {
      await updateCategory(category.id, names[category.id].trim())
      setMessage('Category updated.')
      await refresh()
    } catch (saveError) {
      setError(saveError.message || 'Could not update category.')
    } finally {
      setBusyId(null)
    }
  }

  async function handleDeactivate(category) {
    if (!window.confirm(`Deactivate ${category.name}? Products and receipts will be preserved.`)) return
    setBusyId(category.id)
    setError('')
    setMessage('')
    try {
      await deactivateCategory(category.id)
      setMessage('Category deactivated.')
      await refresh()
    } catch (deactivateError) {
      setError(deactivateError.message || 'Could not deactivate category.')
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="fixed inset-0 z-[65] flex items-end justify-center bg-slate-950/45 p-0 backdrop-blur-sm sm:items-center sm:p-5" role="presentation">
      <section className="max-h-[90dvh] w-full max-w-lg overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-2xl sm:p-6" role="dialog" aria-modal="true" aria-labelledby="category-manager-title">
        <header className="mb-5 flex items-center justify-between gap-3">
          <div><h2 id="category-manager-title" className="text-lg font-bold text-slate-950">Manage categories</h2><p className="mt-1 text-xs text-slate-500">Changes apply to the live product catalog.</p></div>
          <button type="button" onClick={onClose} className="flex size-11 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100" aria-label="Close category manager"><X size={18} aria-hidden="true" /></button>
        </header>
        <form onSubmit={handleCreate} className="flex gap-2">
          <input value={newName} onChange={(event) => setNewName(event.target.value)} maxLength={60} required className="h-12 min-w-0 flex-1 rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100" placeholder="New category name" />
          <button type="submit" className="flex min-h-12 items-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800"><Plus size={16} aria-hidden="true" />Add</button>
        </form>
        {error && <p className="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-800" role="alert">{error}</p>}
        {message && <p className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800" role="status">{message}</p>}
        <div className="mt-5 space-y-2">
          {isLoading ? <p className="py-5 text-center text-sm text-slate-500" role="status">Loading categories...</p> : categories.length === 0 ? <p className="py-5 text-center text-sm text-slate-500">No categories yet.</p> : categories.map((category) => (
            <div key={category.id} className="flex items-center gap-2 rounded-lg border border-slate-100 p-2">
              <input value={names[category.id] ?? category.name} disabled={!category.is_active} onChange={(event) => setNames((current) => ({ ...current, [category.id]: event.target.value }))} aria-label={`Category ${category.name}`} className="h-11 min-w-0 flex-1 rounded-md px-2 text-sm outline-none focus:bg-slate-50 disabled:text-slate-400" />
              <button type="button" disabled={!category.is_active || busyId === category.id} onClick={() => handleSave(category)} className="min-h-11 rounded-md px-3 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-40">Save</button>
              {category.is_active ? <button type="button" disabled={busyId === category.id} onClick={() => handleDeactivate(category)} className="min-h-11 rounded-md px-3 text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-40">Deactivate</button> : <span className="px-2 text-[10px] font-semibold text-slate-400">Inactive</span>}
            </div>
          ))}
        </div>
      </section>
    </div>
  )
}

export default CategoryManager