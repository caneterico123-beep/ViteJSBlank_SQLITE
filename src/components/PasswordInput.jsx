import { useState } from 'react'
import { Eye, EyeOff, LockKeyhole } from 'lucide-react'
import InputField from './InputField.jsx'

function PasswordInput({ id, label = 'Password', value, onChange, error, autoComplete = 'current-password' }) {
  const [isVisible, setIsVisible] = useState(false)

  return (
    <InputField
      id={id}
      label={label}
      type={isVisible ? 'text' : 'password'}
      value={value}
      onChange={onChange}
      placeholder={label === 'Confirm password' ? 'Re-enter your password' : 'Enter your password'}
      autoComplete={autoComplete}
      icon={LockKeyhole}
      error={error}
      trailingAction={(
        <button
          type="button"
          className="shrink-0 rounded p-1 text-slate-400 transition-colors hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600"
          onClick={() => setIsVisible((visible) => !visible)}
          aria-label={isVisible ? 'Hide password' : 'Show password'}
          aria-pressed={isVisible}
        >
          {isVisible ? <EyeOff size={17} aria-hidden="true" /> : <Eye size={17} aria-hidden="true" />}
        </button>
      )}
    />
  )
}

export default PasswordInput