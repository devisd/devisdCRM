import { format, isValid, parseISO } from "date-fns";
import { ru } from "date-fns/locale";

export function formatDateLocal(iso: string): string {
  const date = parseISO(iso);
  if (!isValid(date)) {
    return "—";
  }
  return format(date, "d MMMM yyyy", { locale: ru });
}

export function formatDateTimeLocal(iso: string): string {
  const date = parseISO(iso);
  if (!isValid(date)) {
    return "—";
  }
  return format(date, "d MMMM yyyy, HH:mm", { locale: ru });
}

export function toIsoNow(): string {
  return new Date().toISOString();
}

export function toDateInputValue(iso: string): string {
  const date = parseISO(iso);
  if (!isValid(date)) {
    return "";
  }
  return format(date, "yyyy-MM-dd");
}

/** Convert `yyyy-MM-dd` local date input to ISO midnight UTC-safe local noon. */
export function dateInputToIso(dateInput: string): string | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(dateInput)) {
    return null;
  }
  const date = new Date(`${dateInput}T12:00:00`);
  if (!isValid(date)) {
    return null;
  }
  return date.toISOString();
}

export function todayDateInputValue(): string {
  return format(new Date(), "yyyy-MM-dd");
}
