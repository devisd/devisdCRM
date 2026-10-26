import { ApiError, createApiClient, type ApiClient } from "@/lib/api/client";
import { fetchSession, loginRequest, logoutRequest, type AuthUser } from "@/lib/auth/authApi";
import { classifyLoginError, type LoginFailure } from "@/lib/auth/messages";

export type AuthState =
  | { status: "checking" }
  | { status: "unauthenticated"; reason: "initial" | "expired" | "logged_out" }
  | { status: "authenticated"; user: AuthUser }
  | { status: "error"; message: string };

export type LoginOutcome = { ok: true } | { ok: false; failure: LoginFailure };
export type LogoutOutcome = { ok: true } | { ok: false; message: string };

export type AuthStore = {
  getState(): AuthState;
  subscribe(listener: () => void): () => void;
  /** Asks the server for the current session (initial load, "retry"). */
  checkSession(): Promise<void>;
  login(login: string, password: string): Promise<LoginOutcome>;
  logout(): Promise<LogoutOutcome>;
  /** The API client every future request must use (CSRF header, 401 handling). */
  api: ApiClient;
};

export const CHECKING_STATE: AuthState = { status: "checking" };

const SESSION_CHECK_FAILED = "Не удалось проверить вход: сервер временно недоступен.";
const LOGOUT_FAILED = "Не удалось выйти: сервер временно недоступен. Попробуйте ещё раз.";

/**
 * Authentication state for the whole app. The session token stays in the HttpOnly cookie;
 * the CSRF token is kept only in this closure (never in Web Storage or IndexedDB).
 */
export function createAuthStore(options: { fetch?: typeof fetch; baseUrl?: string } = {}): AuthStore {
  let state: AuthState = CHECKING_STATE;
  let csrfToken: string | null = null;
  let generation = 0;
  const listeners = new Set<() => void>();

  const setState = (next: AuthState): void => {
    state = next;
    listeners.forEach((listener) => listener());
  };

  const endSession = (reason: "expired" | "logged_out"): void => {
    generation++;
    csrfToken = null;
    setState({ status: "unauthenticated", reason });
  };

  const api = createApiClient({
    fetch: options.fetch,
    baseUrl: options.baseUrl,
    getCsrfToken: () => csrfToken,
    onUnauthorized: () => {
      if (state.status === "authenticated") {
        endSession("expired");
      }
    },
  });

  return {
    api,
    getState: () => state,
    subscribe(listener) {
      listeners.add(listener);

      return () => {
        listeners.delete(listener);
      };
    },

    async checkSession() {
      const current = ++generation;
      setState(CHECKING_STATE);
      try {
        const session = await fetchSession(api);
        if (current !== generation) {
          return;
        }
        if (session === null) {
          csrfToken = null;
          setState({ status: "unauthenticated", reason: "initial" });
        } else {
          csrfToken = session.csrfToken;
          setState({ status: "authenticated", user: session.user });
        }
      } catch {
        if (current === generation) {
          setState({ status: "error", message: SESSION_CHECK_FAILED });
        }
      }
    },

    async login(login, password) {
      const current = ++generation;
      try {
        const session = await loginRequest(api, login, password);
        if (current === generation) {
          csrfToken = session.csrfToken;
          setState({ status: "authenticated", user: session.user });
        }

        return { ok: true };
      } catch (error) {
        return { ok: false, failure: classifyLoginError(error) };
      }
    },

    async logout() {
      try {
        await logoutRequest(api);
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          endSession("expired");

          return { ok: true };
        }

        return { ok: false, message: LOGOUT_FAILED };
      }
      endSession("logged_out");

      return { ok: true };
    },
  };
}

export type GateView = "checking" | "login" | "error" | "app";

/** Which screen the app shows for an auth state; CRM screens only when authenticated. */
export function gateView(state: AuthState): GateView {
  switch (state.status) {
    case "checking":
      return "checking";
    case "unauthenticated":
      return "login";
    case "error":
      return "error";
    case "authenticated":
      return "app";
  }
}
