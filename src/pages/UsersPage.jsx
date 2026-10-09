import { useEffect, useState } from 'react'
import { Search } from 'lucide-react'
import { useOutletContext } from 'react-router-dom'
import UsersTable from '../components/UsersTable.jsx'
import { getUsers } from '../services/api.js'

function UsersPage() {
  const { search, setSearch } = useOutletContext()
  const [pageState, setPageState] = useState({ search, page: 1 })
  const page = pageState.search === search ? pageState.page : 1
  const requestKey = `${search}\u0000${page}`
  const [loadedData, setLoadedData] = useState({ key: '', users: [], pagination: null, error: '' })
  const isLoading = loadedData.key !== requestKey
  const users = isLoading ? [] : loadedData.users
  const pagination = isLoading ? null : loadedData.pagination
  const error = isLoading ? '' : loadedData.error

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      getUsers({ page, perPage: 10, search })
        .then((result) => {
          if (!active) return
          setLoadedData({ key: requestKey, users: result.users, pagination: result.pagination, error: '' })
        })
        .catch((loadError) => {
          if (active) setLoadedData({ key: requestKey, users: [], pagination: null, error: loadError.message || 'Could not load users.' })
        })
    }, 180)
    return () => {
      active = false
      window.clearTimeout(timer)
    }
  }, [page, search, requestKey])

  return (
    <div className="space-y-5">
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div>
          <h2 className="text-xl font-bold text-slate-950">User directory</h2>
          <p className="mt-1 text-sm text-slate-500">Browse registered accounts and recent activity.</p>
        </div>
        <label className="flex h-10 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-slate-400 sm:hidden">
          <Search size={16} aria-hidden="true" />
          <span className="sr-only">Search users</span>
          <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search users..." className="min-w-0 flex-1 bg-transparent text-sm text-slate-800 outline-none placeholder:text-slate-400" />
        </label>
      </div>
      <UsersTable
        users={users}
        loading={isLoading}
        error={error}
        pagination={pagination}
        onPageChange={(nextPage) => setPageState({ search, page: nextPage })}
        title="All users"
        subtitle="Searchable account directory"
      />
    </div>
  )
}

export default UsersPage