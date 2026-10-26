import { ApiError } from "@/lib/api/client";

export type LoginFailure =
  | { kind: "invalid_credentials" }
  | { kind: "validation" }
  | { kind: "rate_limited"; retryAfterSeconds: number | null }
  | { kind: "unavailable" };

export function classifyLoginError(error: unknown): LoginFailure {
  if (error instanceof ApiError && error.kind === "http") {
    if (error.status === 401) {
      return { kind: "invalid_credentials" };
    }
    if (error.status === 429) {
      return { kind: "rate_limited", retryAfterSeconds: error.retryAfterSeconds };
    }
    if (error.status === 400) {
      return { kind: "validation" };
    }
  }

  return { kind: "unavailable" };
}

/** "45 сек" / "3 мин". */
export function formatWait(seconds: number): string {
  if (seconds < 60) {
    return `${Math.max(1, Math.ceil(seconds))} сек`;
  }

  return `${Math.ceil(seconds / 60)} мин`;
}

export function loginFailureMessage(failure: LoginFailure): string {
  switch (failure.kind) {
    case "invalid_credentials":
      return "Неверный логин или пароль";
    case "validation":
      return "Введите логин и пароль";
    case "rate_limited":
      return failure.retryAfterSeconds !== null && failure.retryAfterSeconds > 0
        ? `Слишком много попыток входа. Повторите через ${formatWait(failure.retryAfterSeconds)}.`
        : "Слишком много попыток входа. Повторите позже.";
    case "unavailable":
      return "Сервер временно недоступен. Попробуйте ещё раз позже.";
  }
}
