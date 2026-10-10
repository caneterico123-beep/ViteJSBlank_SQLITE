import { useState } from 'react'
import { UserRound } from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import AuthLayout from '../components/AuthLayout.jsx'
import InputField from '../components/InputField.jsx'
import LoadingButton from '../components/LoadingButton.jsx'
import PasswordInput from '../components/PasswordInput.jsx'
import { useAuth } from '../contexts/useAuth.js'
import { login } from '../services/api.js'
import { isValidLoginIdentifier } from '../utils/validation.js'

function LoginPage() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [rememberMe, setRememberMe] = useState(false)
  const [errors, setErrors] = useState({})
  const [feedback, setFeedback] = useState(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const { setUser } = useAuth()
  const navigate = useNavigate()

  function validate() {
    const nextErrors = {}
    if (!email.trim()) nextErrors.email = 'Enter your email address or username.'
    else if (!isValidLoginIdentifier(email)) nextErrors.email = 'Enter a valid email address or username.'
    if (!password) nextErrors.password = 'Enter your password.'
    return nextErrors
  }

  async function handleSubmit(event) {
    event.preventDefault()
    const nextErrors = validate()
    setErrors(nextErrors)
    setFeedback(null)
    if (Object.keys(nextErrors).length) return

    setIsSubmitting(true)
    try {
      const result = await login({ email: email.trim(), password, rememberMe })
      setUser(result.user)
      navigate('/dashboard', { replace: true })
    } catch (error) {
      if (error.status === 422) setErrors(error.errors)
      setFeedback({ type: 'error', message: error.message || 'Unable to sign in. Please try again.' })
    } finally {
      setIsSubmitting(false)
    }
  }

  function clearFieldError(field, value, setter) {
    setter(value)
    setErrors((current) => ({ ...current, [field]: '' }))
    setFeedback(null)
  }

  return (
    <AuthLayout
      eyebrow="Your workspace awaits"
      title="Welcome Back"
      subtitle="Sign in to your account to continue."
    >
      <form className="space-y-5" onSubmit={handleSubmit} noValidate>
        <div className="space-y-4">
          <InputField
            id="login-email"
            label="Email or username"
            type="text"
            value={email}
            onChange={(event) => clearFieldError('email', event.target.value, setEmail)}
            placeholder="you@company.com or admin"
            autoComplete="username"
            icon={UserRound}
            error={errors.email}
          />
          <PasswordInput
            id="login-password"
            value={password}
            onChange={(event) => clearFieldError('password', event.target.value, setPassword)}
            error={errors.password}
          />
        </div>

        <div className="flex items-center justify-between gap-3 text-[12px]">
          <label className="inline-flex cursor-pointer items-center gap-2 text-slate-600">
            <input
              type="checkbox"
              checked={rememberMe}
              onChange={(event) => setRememberMe(event.target.checked)}
              className="size-4 cursor-pointer accent-orange-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600"
            />
            Remember me
          </label>
          <button
            type="button"
            className="form-link rounded-sm font-semibold text-orange-700 hover:text-orange-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600"
            onClick={() => setFeedback({ type: 'error', message: 'Password recovery requires a configured authentication backend.' })}
          >
            Forgot password?
          </button>
        </div>

        <LoadingButton loading={isSubmitting} loadingText="Signing in...">Sign In</LoadingButton>

        {feedback && (
          <p className={`rounded-lg border px-3.5 py-3 text-[12px] leading-5 ${feedback.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-orange-200 bg-orange-50 text-orange-900'}`} role={feedback.type === 'error' ? 'alert' : 'status'} aria-live="polite">
            {feedback.message}
          </p>
        )}

        <p className="pt-1 text-center text-[12px] text-slate-500">
          Don&apos;t have an account?{' '}
          <Link className="font-semibold text-slate-800 transition-colors hover:text-orange-700" to="/register">Sign up</Link>
        </p>
      </form>
    </AuthLayout>
  )
}

export default LoginPage