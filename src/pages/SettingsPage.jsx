import { useEffect, useState } from 'react'
import LoadingButton from '../components/LoadingButton.jsx'
import { getSettings, updateSettings } from '../services/api.js'

function SettingsPage() {
  const [theme, setTheme] = useState('system')
  const [emailNotifications, setEmailNotifications] = useState(true)
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  useEffect(() => {
    let active = true
    getSettings()
      .then(({ settings }) => {
        if (!active) return
        setTheme(settings.theme)
        setEmailNotifications(settings.email_notifications)
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

  if (isLoading) return <p className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500" role="status">Loading settings...</p>

  return (
    <section className="max-w-2xl rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
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
  )
}

export default SettingsPage