import { useEffect, useState } from 'react'
import { Mail, UserRound } from 'lucide-react'
import InputField from '../components/InputField.jsx'
import LoadingButton from '../components/LoadingButton.jsx'
import { useAuth } from '../contexts/useAuth.js'
import { getProfile, updateProfile } from '../services/api.js'

function ProfilePage() {
  const { user, setUser } = useAuth()
  const [name, setName] = useState(user.name)
  const [email, setEmail] = useState(user.email)
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)

  useEffect(() => {
    let active = true
    getProfile()
      .then(({ profile }) => {
        if (!active) return
        setName(profile.name)
        setEmail(profile.email)
        setUser(profile)
      })
      .catch((loadError) => {
        if (active) setError(loadError.message || 'Could not load your profile.')
      })
      .finally(() => {
        if (active) setIsLoading(false)
      })
    return () => {
      active = false
    }
  }, [setUser])

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setError('')
    setMessage('')
    setIsSaving(true)
    try {
      const result = await updateProfile({ name: name.trim(), email: email.trim() })
      setUser(result.profile)
      setMessage(result.message)
    } catch (saveError) {
      if (saveError.errors) setErrors(saveError.errors)
      setError(saveError.message || 'Could not update your profile.')
    } finally {
      setIsSaving(false)
    }
  }

  if (isLoading) return <p className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500" role="status">Loading profile...</p>

  return (
    <div className="max-w-2xl space-y-5">
      <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <div className="mb-6">
          <h2 className="text-sm font-bold text-slate-900">Profile information</h2>
          <p className="mt-1 text-xs text-slate-500">Update your name and email address.</p>
        </div>
        <form className="space-y-5" onSubmit={handleSubmit}>
          <InputField id="profile-name" label="Full name" value={name} onChange={(event) => setName(event.target.value)} autoComplete="name" icon={UserRound} error={errors.name} />
          <InputField id="profile-email" label="Email address" type="email" value={email} onChange={(event) => setEmail(event.target.value)} autoComplete="email" inputMode="email" icon={Mail} error={errors.email} />
          <div className="rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-600">
            Business role: <span className="font-semibold capitalize text-slate-800">{user.role.replaceAll('_', ' ')}</span>. Role changes are managed by a business owner.
          </div>
          {error && <p className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{error}</p>}
          {message && <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{message}</p>}
          <div className="max-w-[180px]"><LoadingButton loading={isSaving} loadingText="Saving...">Save profile</LoadingButton></div>
        </form>
      </section>
    </div>
  )
}

export default ProfilePage