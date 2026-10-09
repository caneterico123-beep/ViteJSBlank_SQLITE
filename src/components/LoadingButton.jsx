import { LoaderCircle } from 'lucide-react'

function LoadingButton({
  children,
  loading = false,
  loadingText = 'Please wait...',
  disabled = false,
  variant = 'primary',
  type = 'submit',
  onClick,
}) {
  const style = variant === 'secondary'
    ? 'google-button border border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50'
    : 'submit-button text-white'

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled || loading}
      className={`${style} flex h-[48px] w-full items-center justify-center gap-2 rounded-[9px] px-4 text-[14px] font-bold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600 disabled:cursor-not-allowed disabled:opacity-65`}
    >
      {loading ? (
        <>
          <LoaderCircle size={17} className="animate-spin" aria-hidden="true" />
          {loadingText}
        </>
      ) : children}
    </button>
  )
}

export default LoadingButton