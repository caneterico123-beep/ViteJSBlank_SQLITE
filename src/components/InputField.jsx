function InputField({
  id,
  label,
  type = 'text',
  value,
  onChange,
  placeholder,
  autoComplete,
  icon: Icon,
  error,
  trailingAction,
  inputMode,
  onBlur,
}) {
  return (
    <div>
      <label htmlFor={id} className="mb-2 block text-[13px] font-semibold text-slate-700">
        {label}
      </label>
      <div className={`field-shell flex h-[48px] items-center gap-3 rounded-[9px] border bg-slate-50/70 px-3.5 ${error ? 'border-rose-300' : 'border-slate-200'}`}>
        <Icon size={17} className="shrink-0 text-slate-400" aria-hidden="true" />
        <input
          id={id}
          name={id}
          type={type}
          value={value}
          onChange={onChange}
          onBlur={onBlur}
          placeholder={placeholder}
          autoComplete={autoComplete}
          inputMode={inputMode}
          aria-invalid={Boolean(error)}
          aria-describedby={error ? `${id}-error` : undefined}
          className="field-input h-full w-full bg-transparent text-[14px] text-slate-900"
        />
        {trailingAction}
      </div>
      {error && (
        <p id={`${id}-error`} className="mt-1.5 text-xs font-medium text-rose-600" role="alert">
          {error}
        </p>
      )}
    </div>
  )
}

export default InputField