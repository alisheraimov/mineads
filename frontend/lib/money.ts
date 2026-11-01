/** All monetary amounts are integer USD micros: 1 USD = 1,000,000 micros. */
export function formatUsd(micros: number, options: { maximumFractionDigits?: number } = {}) {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    minimumFractionDigits: 2,
    maximumFractionDigits: options.maximumFractionDigits ?? 2,
  }).format(micros / 1_000_000);
}
