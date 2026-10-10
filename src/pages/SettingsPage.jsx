import { useEffect, useState } from 'react'
import LoadingButton from '../components/LoadingButton.jsx'
import { useAuth } from '../contexts/useAuth.js'
import { getBusiness, getSettings, updateBusiness, updateSettings } from '../services/api.js'

function SettingsPage() {
  const { user, setUser } = useAuth()
  const [theme, setTheme] = useState('system')
  const [emailNotifications, setEmailNotifications] = useState(true)
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [business, setBusiness] = useState(null)
  const [confirmIndustryChange, setConfirmIndustryChange] = useState(false)
  const [isSavingBusiness, setIsSavingBusiness] = useState(false)

  useEffect(() => {
    let active = true
    Promise.all([getSettings(), getBusiness()])
      .then(([{ settings }, { business: businessSettings }]) => {
        if (!active) return
        setTheme(settings.theme)
        setEmailNotifications(settings.email_notifications)
        setBusiness(businessSettings)
      })
      .catch((loadError) => {
        if (active) setError(loadError.message || 'Could not load your settings.')
      })
      .finally(() => {
        if (active) setIsLoading(false)
      })
    return () => {
      active = false
    }
  }, [])

  async function handleSubmit(event) {
    event.preventDefault()
    setIsSaving(true)
    setError('')
    setMessage('')
    try {
      const result = await updateSettings({ theme, email_notifications: emailNotifications })
      setTheme(result.settings.theme)
      setEmailNotifications(result.settings.email_notifications)
      setMessage(result.message)
    } catch (saveError) {
      setError(saveError.message || 'Could not save your settings.')
    } finally {
      setIsSaving(false)
    }
  }

  async function handleBusinessSubmit(event) {
    event.preventDefault()
    setIsSavingBusiness(true)
    setError('')
    setMessage('')
    try {
      const result = await updateBusiness({
        name: business.name,
        industry: business.industry,
        currency: business.currency,
        timezone: business.timezone,
        confirm_industry_change: confirmIndustryChange,
      })
      setBusiness(result.business)
      setUser((current) => ({ ...current, business: result.business }))
      setConfirmIndustryChange(false)
      setMessage(result.message)
    } catch (saveError) {
      setError(saveError.message || 'Could not save business configuration.')
    } finally {
      setIsSavingBusiness(false)
    }
  }

  if (isLoading) return <p className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500" role="status">Loading settings...</p>

  return (
    <div className="max-w-2xl space-y-5">
    {['owner', 'admin'].includes(user.role) && business && (
      <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <div className="mb-6">
          <h2 className="text-sm font-bold text-slate-900">Business configuration</h2>
          <p className="mt-1 text-xs text-slate-500">Set the active business type, display currency, and timezone.</p>
        </div>
        <form onSubmit={handleBusinessSubmit} className="space-y-4">
          <label className="block">
            <span className="mb-1.5 block text-xs font-semibold text-slate-700">Business name</span>
            <input required maxLength={120} value={business.name} onChange={(event) => setBusiness({ ...business, name: event.target.value })} className="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100" />
          </label>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="block">
              <span className="mb-1.5 block text-xs font-semibold text-slate-700">Industry</span>
              <select value={business.industry} onChange={(event) => { setBusiness({ ...business, industry: event.target.value }); setConfirmIndustryChange(false) }} className="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none focus:border-orange-500">
                {[
                  ['retail', 'Retail store'], ['grocery', 'Grocery store'], ['pharmacy', 'Pharmacy'], ['clinic', 'Clinic'],
                  ['bakery', 'Bakery'], ['hotel', 'Hotel'], ['restaurant', 'Restaurant'], ['rent_a_car', 'Rent-a-car'],
                  ['computer_store', 'Computer store'], ['coffee_shop', 'Coffee shop'],
                ].map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </label>
            <label className="block">
              <span className="mb-1.5 block text-xs font-semibold text-slate-700">Currency code</span>
              <input required maxLength={3} pattern="[A-Z]{3}" value={business.currency} onChange={(event) => setBusiness({ ...business, currency: event.target.value.toUpperCase() })} className="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm uppercase outline-none focus:border-orange-500" />
            </label>
          </div>
          <label className="block">
            <span className="mb-1.5 block text-xs font-semibold text-slate-700">Timezone</span>
            <input required value={business.timezone} onChange={(event) => setBusiness({ ...business, timezone: event.target.value })} placeholder="Asia/Manila" className="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-orange-500" />
          </label>
          {business.industry !== user.business?.industry && (
            <label className="flex items-start gap-2 text-xs leading-5 text-amber-900">
              <input type="checkbox" checked={confirmIndustryChange} onChange={(event) => setConfirmIndustryChange(event.target.checked)} className="mt-1 accent-orange-600" />
              Confirm the industry change. Existing business records will be retained; available workflows may change.
            </label>
          )}
          <div className="max-w-[220px]"><LoadingButton loading={isSavingBusiness} loadingText="Saving...">Save business settings</LoadingButton></div>
        </form>
      </section>
    )}
    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
      <div className="mb-6">
        <h2 className="text-sm font-bold text-slate-900">Preferences</h2>
        <p className="mt-1 text-xs text-slate-500">Manage your workspace experience.</p>
      </div>
      <form onSubmit={handleSubmit} className="space-y-6">
        <label className="block max-w-sm">
          <span className="mb-2 block text-xs font-semibold text-slate-700">Appearance</span>
          <select value={theme} onChange={(event) => setTheme(event.target.value)} className="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100">
            <option value="system">System default</option>
            <option value="light">Light</option>
            <option value="dark">Dark</option>
          </select>
        </label>

        <label className="flex cursor-pointer items-start gap-3 border-t border-slate-100 pt-5">
          <input type="checkbox" checked={emailNotifications} onChange={(event) => setEmailNotifications(event.target.checked)} className="mt-0.5 size-4 accent-orange-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600" />
          <span>
            <span className="block text-sm font-semibold text-slate-800">Email notifications</span>
            <span className="mt-0.5 block text-xs text-slate-500">Receive account and workspace updates by email.</span>
          </span>
        </label>

        {error && <p className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{error}</p>}
        {message && <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{message}</p>}
        <div className="max-w-[180px]"><LoadingButton loading={isSaving} loadingText="Saving...">Save settings</LoadingButton></div>
      </form>
    </section>
    </div>
  )
}

export default SettingsPage