export function formatMoney(cents, currency = 'USD') {
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
  }).format((Number(cents) || 0) / 100)
}

export function calculateTotals(subtotalCents, discountBps, taxBps) {
  const discountCents = Math.floor((subtotalCents * discountBps + 5000) / 10000)
  const taxableCents = subtotalCents - discountCents
  const taxCents = Math.floor((taxableCents * taxBps + 5000) / 10000)
  return {
    subtotalCents,
    discountCents,
    taxCents,
    totalCents: taxableCents + taxCents,
  }
}