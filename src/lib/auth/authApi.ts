import { ApiError, type ApiClient } from "@/lib/api/client";

export type AuthUser = {
  login: string;
};

/** What /auth/login and /auth/me return. The CSRF token lives in memory only. */
export type SessionInfo = {
  user: AuthUser;
  csrfToken: string;
};

function toSessionInfo(data: unknown): SessionInfo {
  if (typeof data === "object" && data !== null) {
    const { user, csrfToken } = data as { user?: unknown; csrfToken?: unknown };
    if (
      typeof user === "object" &&
      user !== null &&
      typeof (user as { login?: unknown }).login === "string" &&
      typeof csrfToken === "string" &&
      csrfToken !== ""
    ) {
      return { user: { login: (user as { login: string }).login }, csrfToken };
    }
  }
  throw new ApiError("invalid_response", "Unexpected session data");
}

/** Current session, or null when the server answers 401. */
export async function fetchSession(client: ApiClient): Promise<SessionInfo | null> {
  try {
    return toSessionInfo(await client.request<unknown>("GET", "/auth/me", { public: true }));
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      return null;
    }
    throw error;
  }
}

export async function loginRequest(client: ApiClient, login: string, password: string): Promise<SessionInfo> {
  return toSessionInfo(await client.request<unknown>("POST", "/auth/login", { body: { login, password }, public: true }));
}

/** Ends the session. A 401 means it had already ended; the caller treats both as logged out. */
export async function logoutRequest(client: ApiClient): Promise<void> {
  await client.request<unknown>("POST", "/auth/logout", { public: true });
}
