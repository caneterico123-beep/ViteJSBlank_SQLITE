import { Command } from 'lucide-react'
import { Link } from 'react-router-dom'

function AuthLayout({ eyebrow, title, subtitle, children, wide = false }) {
  return (
    <main className="login-background relative flex min-h-screen flex-col items-center justify-center overflow-hidden px-5 py-10 sm:px-8">
      <div className="background-grid pointer-events-none absolute inset-0" aria-hidden="true" />
      <div className="background-glow pointer-events-none absolute" aria-hidden="true" />

      <div className={`relative z-10 w-full ${wide ? 'max-w-[480px]' : 'max-w-[440px]'} animate-enter`}>
        <Link to="/login" className="mb-8 flex items-center justify-center gap-3 text-slate-900 no-underline">
          <span className="brand-mark flex size-10 items-center justify-center rounded-[13px] text-white shadow-lg shadow-orange-900/15">
            <Command size={21} strokeWidth={2.4} aria-hidden="true" />
          </span>
          <span className="text-[20px] font-extrabold tracking-[-0.7px]">Northstar</span>
        </Link>

        <section id="main-content" className="login-card rounded-[22px] border border-white/80 bg-white/90 p-6 shadow-2xl shadow-slate-900/[0.08] backdrop-blur-xl sm:p-9">
          <header className="mb-7 text-center">
            <p className="mb-3 inline-flex items-center gap-2 rounded-full border border-orange-100 bg-orange-50/80 px-3 py-1 text-[11px] font-bold uppercase tracking-[1.1px] text-orange-800">
              <span className="size-1.5 rounded-full bg-orange-500" aria-hidden="true" />
              {eyebrow}
            </p>
            <h1 className="mb-2 text-[30px] font-bold leading-tight tracking-[-1.1px] text-slate-950 sm:text-[32px]">
              {title}
            </h1>
            <p className="text-[14px] leading-6 text-slate-500">{subtitle}</p>
          </header>

          {children}
        </section>

        <p className="mt-6 text-center text-xs text-slate-500">
          Authentication requires a connected provider.
        </p>
      </div>
    </main>
  )
}

export default AuthLayout