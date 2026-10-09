const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/+$/, '')
let csrfToken = ''

export class ApiError extends Error {
  constructor(message, status, errors = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

async function request(path, { method = 'GET', body, includeCsrf = true, retryCsrf = true } = {}) {
  if (method !== 'GET' && includeCsrf && !csrfToken) await getCsrfToken()

  const headers = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (method !== 'GET' && includeCsrf) headers['X-CSRF-Token'] = csrfToken

  let response
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      method,
      headers,
      credentials: 'include',
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError('Unable to reach the authentication server. Start the PHP API and try again.', 0)
  }

  const result = (await response.json().catch(() => ({}))) || {}
  if (result.csrfToken) csrfToken = result.csrfToken

  if (response.status === 419 && method !== 'GET' && includeCsrf && retryCsrf) {
    await getCsrfToken()
    return request(path, { method, body, includeCsrf, retryCsrf: false })
  }

  if (!response.ok) {
    throw new ApiError(
      typeof result.message === 'string' ? result.message : 'The request could not be completed. Please try again.',
      response.status,
      result.errors || {},
    )
  }

  return result
}

export async function getCsrfToken() {
  const result = await request('/api/csrf', { includeCsrf: false })
  if (!result.csrfToken) throw new ApiError('Could not start a secure session. Please refresh and try again.', 500)
  return result.csrfToken
}

export function getCurrentUser() {
  return request('/api/me')
}

export function login({ email, password, rememberMe }) {
  return request('/api/login', {
    method: 'POST',
    body: { email, password, remember_me: rememberMe },
  })
}

export function register({ fullName, email, password, confirmPassword }) {
  return request('/api/register', {
    method: 'POST',
    body: {
      name: fullName,
      email,
      password,
      password_confirmation: confirmPassword,
    },
  })
}

export async function logout() {
  const result = await request('/api/logout', { method: 'POST', body: {} })
  csrfToken = ''
  return result
}