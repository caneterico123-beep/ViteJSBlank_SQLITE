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
  const selectedBusinessId = window.localStorage.getItem('bizflow.businessId')
  if (selectedBusinessId && !headers['X-Business-Id']) headers['X-Business-Id'] = selectedBusinessId
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
  if (result.user?.business_id) window.localStorage.setItem('bizflow.businessId', String(result.user.business_id))

  if (response.status === 419 && method !== 'GET' && includeCsrf && retryCsrf) {
    await getCsrfToken()
    return request(path, { method, body, headers: extraHeaders, includeCsrf, retryCsrf: false })
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

export function register({ fullName, email, password, confirmPassword, businessName, industry }) {
  return request('/api/register', {
    method: 'POST',
    body: {
      name: fullName,
      email,
      password,
      password_confirmation: confirmPassword,
      business_name: businessName,
      industry,
    },
  })
}

export async function logout() {
  const result = await request('/api/logout', { method: 'POST', body: {} })
  csrfToken = ''
  window.localStorage.removeItem('bizflow.businessId')
  return result
}

export async function switchBusiness(businessId) {
  window.localStorage.setItem('bizflow.businessId', String(businessId))
  try {
    return await getCurrentUser()
  } catch (error) {
    window.localStorage.removeItem('bizflow.businessId')
    throw error
  }
}

export function updateBusiness(business) {
  return request('/api/business', { method: 'PUT', body: business })
}

export function getBusiness() {
  return request('/api/business')
}

export function getBusinessMembers() {
  return request('/api/business/members')
}

export function addBusinessMember(member) {
  return request('/api/business/members', { method: 'POST', body: member })
}

export function updateBusinessMember(id, role) {
  return request(`/api/business/members/${id}`, { method: 'PUT', body: { role } })
}

export function deactivateBusinessMember(id) {
  return request(`/api/business/members/${id}`, { method: 'DELETE', body: {} })
}

export function getDashboardStats() {
  return request('/api/dashboard/stats')
}

export function getRetailDashboard({ dateFrom = '', dateTo = '', groupBy = 'day' } = {}) {
  const params = new URLSearchParams({ group_by: groupBy })
  if (dateFrom) params.set('date_from', dateFrom)
  if (dateTo) params.set('date_to', dateTo)
  return request(`/api/retail/dashboard?${params.toString()}`)
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

export function getPharmacyDashboard({ dateFrom = '', dateTo = '' } = {}) {
  const params = new URLSearchParams()
  if (dateFrom) params.set('date_from', dateFrom)
  if (dateTo) params.set('date_to', dateTo)
  return request(`/api/pharmacy/dashboard${params.size ? `?${params.toString()}` : ''}`)
}

export function getPharmacyMedicines(search = '') {
  const params = new URLSearchParams()
  if (search) params.set('search', search)
  return request(`/api/pharmacy/medicines${params.size ? `?${params.toString()}` : ''}`)
}

export function savePharmacyMedicineDetails(details, id = null) {
  return request(id ? `/api/pharmacy/medicines/${id}` : '/api/pharmacy/medicines', {
    method: id ? 'PUT' : 'POST',
    body: details,
  })
}

export function getPharmacyBatches({ productId = '', status = '' } = {}) {
  const params = new URLSearchParams()
  if (productId) params.set('product_id', String(productId))
  if (status) params.set('status', status)
  return request(`/api/pharmacy/batches${params.size ? `?${params.toString()}` : ''}`)
}

export function receivePharmacyBatch(batch) {
  return request('/api/pharmacy/batches', { method: 'POST', body: batch })
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

export function restoreCategory(id) {
  return request(`/api/categories/${id}/restore`, { method: 'POST', body: {} })
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

export function restoreProduct(id) {
  return request(`/api/products/${id}/restore`, { method: 'POST', body: {} })
}

export function getInventory(search = '') {
  const query = search ? `?search=${encodeURIComponent(search)}` : ''
  return request(`/api/inventory${query}`)
}

export function adjustInventory(adjustment) {
  return request('/api/inventory/adjustments', { method: 'POST', body: adjustment })
}

export function getInventoryProducts({ page = 1, perPage = 20, search = '', stockStatus = '', categoryId = '', supplierId = '', sort = 'name', includeInactive = false } = {}) {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
    search,
    stock_status: stockStatus,
    category_id: String(categoryId),
    supplier_id: String(supplierId),
    sort,
    include_inactive: includeInactive ? '1' : '0',
  })
  return request(`/api/inventory/products?${params.toString()}`)
}

export function getInventorySummary() {
  return request('/api/inventory/summary')
}

export function createInventoryOperation(operation) {
  return request('/api/inventory/operations', { method: 'POST', body: operation })
}

export function getInventoryHistory({ page = 1, perPage = 20, search = '', type = '', dateFrom = '', dateTo = '' } = {}) {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
    search,
    type,
    date_from: dateFrom,
    date_to: dateTo,
  })
  return request(`/api/inventory/history?${params.toString()}`)
}

export function getSuppliers({ includeInactive = false } = {}) {
  return request(`/api/suppliers${includeInactive ? '?include_inactive=1' : ''}`)
}

export function saveSupplier(supplier, id = null) {
  return request(id ? `/api/suppliers/${id}` : '/api/suppliers', {
    method: id ? 'PUT' : 'POST',
    body: supplier,
  })
}

export function deactivateSupplier(id) {
  return request(`/api/suppliers/${id}`, { method: 'DELETE' })
}

export function restoreSupplier(id) {
  return request(`/api/suppliers/${id}/restore`, { method: 'POST', body: {} })
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