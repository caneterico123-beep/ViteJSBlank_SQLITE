import { useState } from 'react'
import { Building2, Mail, UserRound } from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import AuthLayout from '../components/AuthLayout.jsx'
import InputField from '../components/InputField.jsx'
import LoadingButton from '../components/LoadingButton.jsx'
import PasswordInput from '../components/PasswordInput.jsx'
import { useAuth } from '../contexts/useAuth.js'
import { register } from '../services/api.js'
import { getPasswordStrength, isValidEmail } from '../utils/validation.js'

const industries = [
  ['retail', 'Retail store'],
  ['grocery', 'Grocery store'],
  ['pharmacy', 'Pharmacy'],
  ['clinic', 'Clinic'],
  ['bakery', 'Bakery'],
  ['hotel', 'Hotel'],
  ['restaurant', 'Restaurant'],
  ['rent_a_car', 'Rent-a-car'],
  ['computer_store', 'Computer store'],
  ['coffee_shop', 'Coffee shop'],
]

function RegisterPage() {
  const [fullName, setFullName] = useState('')
  const [businessName, setBusinessName] = useState('')
  const [industry, setIndustry] = useState('retail')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [acceptedTerms, setAcceptedTerms] = useState(false)
  const [errors, setErrors] = useState({})
  const [feedback, setFeedback] = useState(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const { setUser } = useAuth()
  const navigate = useNavigate()
  const strength = getPasswordStrength(password)

  function validate() {
    const nextErrors = {}
    if (!fullName.trim()) nextErrors.fullName = 'Enter your full name.'
    if (!businessName.trim()) nextErrors.businessName = 'Enter your business name.'
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
        businessName: businessName.trim(),
        industry,
        email: email.trim(),
        password,
        confirmPassword,
      })
      setUser(result.user)
      navigate('/dashboard', { replace: true })
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
      <form className="space-y-5" onSubmit={handleSubmit} noValidate>
        <section className="rounded-xl border border-slate-200/80 bg-slate-50/50 p-4 sm:p-5" aria-labelledby="register-business-heading">
          <div className="mb-4 flex items-center gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-orange-100 text-orange-700">
              <Building2 size={18} aria-hidden="true" />
            </span>
            <div>
              <h2 id="register-business-heading" className="text-sm font-bold text-slate-900">Set up your business</h2>
              <p className="mt-0.5 text-xs text-slate-500">Choose a name and business type to personalize your workspace.</p>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <InputField
              id="register-business-name"
              label="Business name"
              value={businessName}
              onChange={(event) => updateField('businessName', event.target.value, setBusinessName)}
              placeholder="Acme Retail"
              autoComplete="organization"
              icon={Building2}
              error={errors.business_name || errors.businessName}
            />
            <div>
              <label htmlFor="register-industry" className="mb-2 block text-[13px] font-semibold text-slate-700">Business type</label>
              <div className={`field-shell flex h-[48px] items-center gap-3 rounded-[9px] border bg-slate-50/70 px-3.5 ${errors.industry ? 'border-rose-300' : 'border-slate-200'}`}>
                <Building2 size={17} className="shrink-0 text-slate-400" aria-hidden="true" />
                <select
                  id="register-industry"
                  value={industry}
                  onChange={(event) => updateField('industry', event.target.value, setIndustry)}
                  aria-invalid={Boolean(errors.industry)}
                  aria-describedby={errors.industry ? 'register-industry-error' : undefined}
                  className="field-input h-full w-full bg-transparent text-sm text-slate-800"
                >
                  {industries.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
              </div>
              {errors.industry && <p id="register-industry-error" className="mt-1.5 text-xs font-medium text-rose-600" role="alert">{errors.industry}</p>}
            </div>
          </div>
        </section>

        <section className="rounded-xl border border-slate-200/80 bg-slate-50/50 p-4 sm:p-5" aria-labelledby="register-account-heading">
          <div className="mb-4 flex items-center gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-slate-200/70 text-slate-700">
              <UserRound size={18} aria-hidden="true" />
            </span>
            <div>
              <h2 id="register-account-heading" className="text-sm font-bold text-slate-900">Create your account</h2>
              <p className="mt-0.5 text-xs text-slate-500">Use your work email and set a secure password.</p>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
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
                <span className={`text-[11px] font-semibold ${strength.text}`}>Strength: {strength.label}</span>
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
          </div>
        </section>

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