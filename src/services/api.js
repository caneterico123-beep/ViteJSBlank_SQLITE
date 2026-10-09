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

async function request(path, { method = 'GET', body, headers: extraHeaders = {}, includeCsrf = true, retryCsrf = true } = {}) {
  if (method !== 'GET' && includeCsrf && !csrfToken) await getCsrfToken()

  const headers = { Accept: 'application/json', ...extraHeaders }
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

export function getDashboardStats() {
  return request('/api/dashboard/stats')
}

export function getRegistrationAnalytics() {
  return request('/api/dashboard/registrations')
}

export function getUsers({ page = 1, perPage = 10, search = '' } = {}) {
  const params = new URLSearchParams({ page: String(page), per_page: String(perPage), search })
  return request(`/api/users?${params.toString()}`)
}

export function getProfile() {
  return request('/api/profile')
}

export function updateProfile(profile) {
  return request('/api/profile', { method: 'PUT', body: profile })
}

export function getSettings() {
  return request('/api/settings')
}

export function updateSettings(settings) {
  return request('/api/settings', { method: 'PUT', body: settings })
}

export function getPosConfig() {
  return request('/api/pos/config')
}

export function getProducts({ search = '', categoryId = '' } = {}) {
  const params = new URLSearchParams()
  if (search) params.set('search', search)
  if (categoryId) params.set('category_id', String(categoryId))
  return request(`/api/products${params.size ? `?${params.toString()}` : ''}`)
}

export function getCategories({ includeInactive = false } = {}) {
  return request(`/api/categories${includeInactive ? '?include_inactive=1' : ''}`)
}

export function createCategory(name) {
  return request('/api/categories', { method: 'POST', body: { name } })
}

export function updateCategory(id, name) {
  return request(`/api/categories/${id}`, { method: 'PUT', body: { name } })
}

export function deactivateCategory(id) {
  return request(`/api/categories/${id}`, { method: 'DELETE' })
}

export function createProduct(product) {
  return request('/api/products', { method: 'POST', body: product })
}

export function updateProduct(id, product) {
  return request(`/api/products/${id}`, { method: 'PUT', body: product })
}

export function deactivateProduct(id) {
  return request(`/api/products/${id}`, { method: 'DELETE' })
}

export function getInventory(search = '') {
  const query = search ? `?search=${encodeURIComponent(search)}` : ''
  return request(`/api/inventory${query}`)
}

export function adjustInventory(adjustment) {
  return request('/api/inventory/adjustments', { method: 'POST', body: adjustment })
}

export function checkoutSale(payload, idempotencyKey) {
  return request('/api/pos/checkout', {
    method: 'POST',
    headers: { 'Idempotency-Key': idempotencyKey },
    body: payload,
  })
}

export function getTransactions({ page = 1, perPage = 20, search = '', dateFrom = '', dateTo = '', status = '', paymentMethod = '' } = {}) {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
    search,
    date_from: dateFrom,
    date_to: dateTo,
    status,
    payment_method: paymentMethod,
  })
  return request(`/api/pos/transactions?${params.toString()}`)
}

export function getTransaction(id) {
  return request(`/api/pos/transactions/${id}`)
}

export function cancelTransaction(id, reason) {
  return request(`/api/pos/transactions/${id}/cancel`, { method: 'POST', body: { reason } })
}