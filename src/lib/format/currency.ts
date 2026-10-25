/** Format kopecks as rubles for UI, e.g. 150050 → "1 500,50 ₽". */
export function formatRubFromMinor(amountMinor: number): string {
  if (!Number.isFinite(amountMinor) || !Number.isInteger(amountMinor)) {
    return "—";
  }

  const sign = amountMinor < 0 ? "-" : "";
  const absolute = Math.abs(amountMinor);
  const rubles = Math.floor(absolute / 100);
  const kopecks = absolute % 100;
  const rublesFormatted = rubles
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, "\u00a0");

  return `${sign}${rublesFormatted},${kopecks.toString().padStart(2, "0")}\u00a0₽`;
}

/** Parse user rubles input ("1500,50" / "1500.50") to kopecks. */
export function parseRubToMinor(input: string): number | null {
  const normalized = input.trim().replace(/\s/g, "").replace(",", ".");
  if (!normalized) {
    return null;
  }

  if (!/^-?\d+(\.\d{1,2})?$/.test(normalized)) {
    return null;
  }

  const value = Number(normalized);
  if (!Number.isFinite(value)) {
    return null;
  }

  return Math.round(value * 100);
}

/** Editable ruble string from kopecks, e.g. 150050 → "1500,50". */
export function formatRubInputFromMinor(amountMinor: number): string {
  if (!Number.isFinite(amountMinor) || !Number.isInteger(amountMinor)) {
    return "";
  }
  const rubles = amountMinor / 100;
  return Number.isInteger(rubles)
    ? String(rubles)
    : rubles.toFixed(2).replace(".", ",");
}
