function StatCard({ label, value, helper, icon: Icon, tone = 'orange' }) {
  const tones = {
    orange: 'bg-orange-50 text-orange-700',
    emerald: 'bg-emerald-50 text-emerald-700',
    sky: 'bg-sky-50 text-sky-700',
    slate: 'bg-slate-100 text-slate-700',
  }

  return (
    <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs font-medium text-slate-500">{label}</p>
          <p className="mt-2 text-[26px] font-bold leading-none tracking-[-0.5px] text-slate-950">{value}</p>
          <p className="mt-2 text-[11px] text-slate-500">{helper}</p>
        </div>
        <span className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${tones[tone]}`}>
          <Icon size={19} aria-hidden="true" />
        </span>
      </div>
    </article>
  )
}

export default StatCard