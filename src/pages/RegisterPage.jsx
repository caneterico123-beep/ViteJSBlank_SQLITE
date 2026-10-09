import { useState } from 'react'
import { Mail, UserRound } from 'lucide-react'
import { Link } from 'react-router-dom'
import AuthLayout from '../components/AuthLayout.jsx'
import InputField from '../components/InputField.jsx'
import LoadingButton from '../components/LoadingButton.jsx'
import PasswordInput from '../components/PasswordInput.jsx'
import { register } from '../services/api.js'
import { getPasswordStrength, isValidEmail } from '../utils/validation.js'

function RegisterPage() {
  const [fullName, setFullName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [acceptedTerms, setAcceptedTerms] = useState(false)
  const [errors, setErrors] = useState({})
  const [feedback, setFeedback] = useState(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const strength = getPasswordStrength(password)

  function validate() {
    const nextErrors = {}
    if (!fullName.trim()) nextErrors.fullName = 'Enter your full name.'
    if (!email.trim()) nextErrors.email = 'Enter your email address.'
    else if (!isValidEmail(email)) nextErrors.email = 'Enter a valid email address.'
    if (!password) nextErrors.password = 'Create a password.'
    else if (password.length < 8) nextErrors.password = 'Use at least 8 characters.'
    if (!confirmPassword) nextErrors.confirmPassword = 'Confirm your password.'
    else if (password !== confirmPassword) nextErrors.confirmPassword = 'Passwords do not match.'
    if (!acceptedTerms) nextErrors.terms = 'Please accept the Terms of Service and Privacy Policy.'
    return nextErrors
  }

  function updateField(field, value, setter) {
    setter(value)
    setErrors((current) => ({ ...current, [field]: '' }))
    setFeedback(null)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    const nextErrors = validate()
    setErrors(nextErrors)
    setFeedback(null)
    if (Object.keys(nextErrors).length) return

    setIsSubmitting(true)
    try {
      const result = await register({
        fullName: fullName.trim(),
        email: email.trim(),
        password,
        confirmPassword,
      })
      setFeedback({ type: 'success', message: result.message || 'Your account has been created.' })
    } catch (error) {
      if (error.status === 422) {
        setErrors({
          ...error.errors,
          fullName: error.errors.name || error.errors.fullName,
          confirmPassword: error.errors.password_confirmation || error.errors.confirmPassword,
        })
      }
      setFeedback({ type: 'error', message: error.message || 'Unable to create your account. Please try again.' })
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <AuthLayout
      eyebrow="A better way to work"
      title="Create your account"
      subtitle="Get your team set up in just a few steps."
      wide
    >
      <form className="space-y-4" onSubmit={handleSubmit} noValidate>
        <InputField
          id="register-name"
          label="Full name"
          value={fullName}
          onChange={(event) => updateField('fullName', event.target.value, setFullName)}
          placeholder="Alex Morgan"
          autoComplete="name"
          icon={UserRound}
          error={errors.fullName}
        />
        <InputField
          id="register-email"
          label="Email address"
          type="email"
          value={email}
          onChange={(event) => updateField('email', event.target.value, setEmail)}
          placeholder="you@company.com"
          autoComplete="email"
          inputMode="email"
          icon={Mail}
          error={errors.email}
        />
        <div>
          <PasswordInput
            id="register-password"
            value={password}
            onChange={(event) => updateField('password', event.target.value, setPassword)}
            error={errors.password}
            autoComplete="new-password"
          />
          <div className="mt-2 flex items-center gap-2" aria-live="polite">
            <div className="flex flex-1 gap-1" aria-hidden="true">
              {[1, 2, 3].map((segment) => (
                <span key={segment} className={`h-1 flex-1 rounded-full ${segment <= strength.score ? strength.color : 'bg-slate-200'}`} />
              ))}
            </div>
            <span className={`text-[11px] font-semibold ${strength.text}`}>Password strength: {strength.label}</span>
          </div>
        </div>
        <PasswordInput
          id="register-confirm-password"
          label="Confirm password"
          value={confirmPassword}
          onChange={(event) => updateField('confirmPassword', event.target.value, setConfirmPassword)}
          error={errors.confirmPassword}
          autoComplete="new-password"
        />

        <div>
          <label className="flex cursor-pointer items-start gap-2.5 text-[12px] leading-5 text-slate-600">
            <input
              type="checkbox"
              checked={acceptedTerms}
              onChange={(event) => {
                setAcceptedTerms(event.target.checked)
                setErrors((current) => ({ ...current, terms: '' }))
              }}
              className="mt-0.5 size-4 shrink-0 cursor-pointer accent-orange-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600"
              aria-invalid={Boolean(errors.terms)}
              aria-describedby={errors.terms ? 'register-terms-error' : undefined}
            />
            <span>I agree to the Terms of Service and Privacy Policy.</span>
          </label>
          {errors.terms && <p id="register-terms-error" className="mt-1.5 text-xs font-medium text-rose-600" role="alert">{errors.terms}</p>}
        </div>

        <LoadingButton loading={isSubmitting} loadingText="Creating account...">Create Account</LoadingButton>

        {feedback && (
          <p className={`rounded-lg border px-3.5 py-3 text-[12px] leading-5 ${feedback.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-orange-200 bg-orange-50 text-orange-900'}`} role={feedback.type === 'error' ? 'alert' : 'status'} aria-live="polite">
            {feedback.message}
          </p>
        )}

        <p className="pt-1 text-center text-[12px] text-slate-500">
          Already have an account?{' '}
          <Link className="font-semibold text-slate-800 transition-colors hover:text-orange-700" to="/login">Sign in</Link>
        </p>
      </form>
    </AuthLayout>
  )
}

export default RegisterPage