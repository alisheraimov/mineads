export function formatMicros(value: number | null) {
  return value === null
    ? "No cap"
    : new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: "USD",
        maximumFractionDigits: 2,
      }).format(value / 1_000_000);
}

export function formatNumber(value: number) {
  return new Intl.NumberFormat("en-US").format(value);
}

export function formatDate(value: string | null, time = false) {
  return value
    ? new Intl.DateTimeFormat("en-GB", {
        day: "numeric",
        month: "short",
        year: "numeric",
        timeZone: "UTC",
        ...(time ? { hour: "2-digit", minute: "2-digit" } : {}),
      }).format(new Date(value)) + (time ? " UTC" : "")
    : "Not set";
}

export function displayCountry(code: string) {
  return new Intl.DisplayNames(["en"], { type: "region" }).of(code) ?? code;
}

export function displayLanguage(code: string) {
  const name =
    new Intl.DisplayNames(["en"], { type: "language" }).of(code) ?? code;
  return `${name.charAt(0).toUpperCase()}${name.slice(1)} (${code})`;
}

export function dollarsToMicros(value: string): number | null {
  if (!/^\d{1,7}(\.\d{1,2})?$/.test(value)) return null;
  const [whole, fraction = ""] = value.split(".");
  return Number(whole) * 1_000_000 + Number(fraction.padEnd(2, "0")) * 10_000;
}
