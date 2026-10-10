import { useCallback, useEffect, useRef, useState } from 'react'
import { Plus, X } from 'lucide-react'
import { useAuth } from '../contexts/useAuth.js'
import { addBusinessMember, deactivateBusinessMember, getBusinessMembers, updateBusinessMember } from '../services/api.js'

function UsersPage() {
  const { user } = useAuth()
  const addUserButtonRef = useRef(null)
  const [members, setMembers] = useState([])
  const [roles, setRoles] = useState([])
  const [email, setEmail] = useState('')
  const [emailError, setEmailError] = useState('')
  const [role, setRole] = useState('')
  const [search, setSearch] = useState('')
  const [isAddOpen, setIsAddOpen] = useState(false)
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const loadMembers = useCallback(async () => {
    const result = await getBusinessMembers()
    setMembers(result.members)
    setRoles(result.assignable_roles)
    setRole((current) => current || result.assignable_roles[0] || '')
  }, [])

  useEffect(() => {
    let active = true
    getBusinessMembers()
      .then((result) => {
        if (!active) return
        setMembers(result.members)
        setRoles(result.assignable_roles)
        setRole(result.assignable_roles[0] || '')
      })
      .catch((loadError) => {
        if (active) setError(loadError.message || 'Could not load business members.')
      })
      .finally(() => {
        if (active) setIsLoading(false)
      })
    return () => { active = false }
  }, [])

  useEffect(() => {
    if (!isAddOpen) return undefined
    function handleKeyDown(event) {
      if (event.key === 'Escape' && !isSaving) closeAddForm()
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [isAddOpen, isSaving])

  function openAddForm(member = null) {
    setEmail(member?.email || '')
    setEmailError('')
    setRole(member?.role || roles[0] || '')
    setError('')
    setMessage('')
    setIsAddOpen(true)
  }

  function closeAddForm() {
    setIsAddOpen(false)
    setEmail('')
    setEmailError('')
    setError('')
    addUserButtonRef.current?.focus()
  }

  async function handleAdd(event) {
    event.preventDefault()
    const normalizedEmail = email.trim().toLowerCase()
    if (!normalizedEmail || normalizedEmail.length > 254 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalizedEmail)) {
      setEmailError('Enter a valid email address.')
      return
    }
    if (!role || !roles.includes(role)) {
      setError('Choose a role available for this business.')
      return
    }
    setIsSaving(true)
    setError('')
    setEmailError('')
    setMessage('')
    try {
      await addBusinessMember({ email: normalizedEmail, role })
      closeAddForm()
      setMessage(`Added ${normalizedEmail} to ${user.business?.name}.`)
      try {
        await loadMembers()
      } catch {
        setError('The member was added, but the list could not refresh. Reload this page to see the update.')
      }
    } catch (saveError) {
      setError(saveError.message || 'Could not add this business member.')
    } finally {
      setIsSaving(false)
    }
  }

  async function changeRole(member, nextRole) {
    setError('')
    setMessage('')
    try {
      const result = await updateBusinessMember(member.membership_id, nextRole)
      setMessage(result.message)
      await loadMembers()
    } catch (saveError) {
      setError(saveError.message || 'Could not update this member.')
    }
  }

  async function deactivate(member) {
    setError('')
    setMessage('')
    try {
      const result = await deactivateBusinessMember(member.membership_id)
      setMessage(result.message)
      await loadMembers()
    } catch (saveError) {
      setError(saveError.message || 'Could not deactivate this member.')
    }
  }

  const visibleMembers = members.filter((member) => `${member.name} ${member.email}`.toLowerCase().includes(search.toLowerCase()))

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-950">Business team</h1>
          <p className="mt-1 text-sm text-slate-500">Manage access and roles for {user.business?.name}.</p>
        </div>
        <button ref={addUserButtonRef} type="button" onClick={() => openAddForm()} disabled={isLoading || roles.length === 0} className="inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-lg bg-orange-600 px-4 text-sm font-semibold text-white hover:bg-orange-700 disabled:cursor-not-allowed disabled:opacity-50 sm:self-auto">
          <Plus size={16} aria-hidden="true" /> Add User
        </button>
      </header>

      <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <p className="text-sm text-slate-600">Add accounts that have already registered. Their name and credentials remain managed by the account holder.</p>
      </section>

      {error && !isAddOpen && <p className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{error}</p>}
      {message && <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{message}</p>}

      {isAddOpen && (
        <div className="fixed inset-0 z-[70] flex items-end justify-center bg-slate-950/45 p-0 backdrop-blur-sm sm:items-center sm:p-5" role="presentation">
          <section className="max-h-[90dvh] w-full max-w-lg overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-2xl sm:p-6" role="dialog" aria-modal="true" aria-labelledby="add-user-title" aria-describedby="add-user-description">
            <header className="mb-5 flex items-start justify-between gap-3">
              <div>
                <h2 id="add-user-title" className="text-lg font-bold text-slate-950">Add user to business</h2>
                <p id="add-user-description" className="mt-1 text-sm text-slate-500">Add an existing account to {user.business?.name}.</p>
              </div>
              <button type="button" onClick={closeAddForm} disabled={isSaving} className="flex size-11 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 disabled:opacity-50" aria-label="Close add user form"><X size={18} aria-hidden="true" /></button>
            </header>
            <form onSubmit={handleAdd} className="space-y-4" noValidate>
              <label className="block">
                <span className="mb-1.5 block text-xs font-semibold text-slate-700">Email address</span>
                <input autoFocus type="email" required maxLength={254} value={email} onChange={(event) => { setEmail(event.target.value); setEmailError('') }} autoComplete="email" aria-invalid={Boolean(emailError)} aria-describedby={emailError ? 'add-user-email-error' : undefined} placeholder="teammate@example.com" className={`h-11 w-full rounded-lg border px-3 text-sm outline-none focus:ring-2 ${emailError ? 'border-rose-300 focus:border-rose-500 focus:ring-rose-100' : 'border-slate-200 focus:border-orange-500 focus:ring-orange-100'}`} />
                {emailError && <span id="add-user-email-error" className="mt-1.5 block text-xs text-rose-700" role="alert">{emailError}</span>}
              </label>
              <label className="block">
                <span className="mb-1.5 block text-xs font-semibold text-slate-700">Business role</span>
                <select required value={role} onChange={(event) => setRole(event.target.value)} className="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm capitalize outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100">
                  {roles.map((option) => <option key={option} value={option}>{option.replaceAll('_', ' ')}</option>)}
                </select>
              </label>
              <p className="text-xs text-slate-500">Only existing BizFlow accounts can be added. A user's full name is taken from their account profile. Role and business access are verified by the server.</p>
              {error && <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800" role="alert">{error}</p>}
              <footer className="flex flex-col-reverse gap-2 pt-1 sm:flex-row sm:justify-end">
                <button type="button" onClick={closeAddForm} disabled={isSaving} className="min-h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50">Cancel</button>
                <button type="submit" disabled={isSaving || !role} className="min-h-11 rounded-lg bg-orange-600 px-4 text-sm font-semibold text-white hover:bg-orange-700 disabled:opacity-50">{isSaving ? 'Adding user…' : 'Add User'}</button>
              </footer>
            </form>
          </section>
        </div>
      )}

      <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="flex flex-col justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center">
          <div><h3 className="text-sm font-bold text-slate-900">Members</h3><p className="mt-0.5 text-xs text-slate-500">{members.length} account(s) attached to this business</p></div>
          <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search members..." className="h-10 rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-orange-500" />
        </div>
        {isLoading ? <p className="p-8 text-center text-sm text-slate-500" role="status">Loading members...</p> : visibleMembers.length === 0 ? <p className="p-8 text-center text-sm text-slate-500">No matching business members.</p> : (
          <div className="divide-y divide-slate-100">
            {visibleMembers.map((member) => (
              <div key={member.membership_id} className="flex flex-col justify-between gap-3 px-5 py-4 sm:flex-row sm:items-center">
                <div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-800">{member.name}</p><p className="break-all text-xs text-slate-500">{member.email}</p></div>
                <div className="flex flex-wrap items-center gap-2">
                  <select aria-label={`Role for ${member.name}`} disabled={!member.is_active || (user.role !== 'owner' && ['owner', 'admin'].includes(member.role))} value={member.role} onChange={(event) => changeRole(member, event.target.value)} className="h-9 rounded-lg border border-slate-200 bg-white px-2 text-xs capitalize text-slate-700 disabled:bg-slate-50">
                    {Array.from(new Set([...roles, member.role])).map((option) => <option key={option} value={option}>{option.replaceAll('_', ' ')}</option>)}
                  </select>
                  <span className={`rounded-full px-2.5 py-1 text-[10px] font-semibold ${member.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{member.is_active ? 'Active' : 'Inactive'}</span>
                  {member.is_active && member.user_id !== user.id && <button type="button" onClick={() => deactivate(member)} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">Deactivate</button>}
                  {!member.is_active && <button type="button" onClick={() => openAddForm(member)} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">Prepare reactivation</button>}
                </div>
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  )
}

export default UsersPage
