import { useEffect, useState } from 'react'
import { getCsrfToken, getCurrentUser, logout as apiLogout, switchBusiness as apiSwitchBusiness } from '../services/api.js'
import { AuthContext } from './AuthContextValue.js'

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [authError, setAuthError] = useState('')

  useEffect(() => {
    let isMounted = true

    async function loadUser() {
      try {
        await getCsrfToken()
        const result = await getCurrentUser()
        if (isMounted) setUser(result.user)
      } catch (error) {
        if (isMounted && error.status !== 401) setAuthError(error.message)
        if (isMounted) setUser(null)
      } finally {
        if (isMounted) setIsLoading(false)
      }
    }

    loadUser()
    return () => {
      isMounted = false
    }
  }, [])

  async function signOut() {
    await apiLogout()
    setUser(null)
  }

  async function switchBusiness(businessId) {
    const result = await apiSwitchBusiness(businessId)
    setUser(result.user)
  }

  return (
    <AuthContext.Provider value={{ user, setUser, isLoading, authError, signOut, switchBusiness }}>
      {children}
    </AuthContext.Provider>
  )
}