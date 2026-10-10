export function formatMoney(cents, currency = 'USD') {
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
  }).format((Number(cents) || 0) / 100)
}

export function calculateTotals(subtotalCents, discountBps, taxBps) {
  const roundedPercentage = (amount, basisPoints) => (
    Math.floor(amount / 10000) * basisPoints
    + Math.floor(((amount % 10000) * basisPoints + 5000) / 10000)
  )
  const discountCents = roundedPercentage(subtotalCents, discountBps)
  const taxableCents = subtotalCents - discountCents
  const taxCents = roundedPercentage(taxableCents, taxBps)
  return {
    subtotalCents,
    discountCents,
    taxCents,
    totalCents: taxableCents + taxCents,
  }
}