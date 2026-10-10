export function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())
}

export function isValidLoginIdentifier(value) {
  const identifier = value.trim()
  return isValidEmail(identifier) || /^[a-zA-Z0-9_.-]{3,32}$/.test(identifier)
}

export function getPasswordStrength(password) {
  const checks = [
    password.length >= 8,
    /[a-z]/.test(password) && /[A-Z]/.test(password),
    /\d|[^A-Za-z0-9]/.test(password),
  ]
  const score = checks.filter(Boolean).length

  if (score <= 1) return { label: 'Weak', score: 1, color: 'bg-rose-500', text: 'text-rose-600' }
  if (score === 2) return { label: 'Medium', score: 2, color: 'bg-amber-500', text: 'text-amber-600' }
  return { label: 'Strong', score: 3, color: 'bg-emerald-500', text: 'text-emerald-600' }
}