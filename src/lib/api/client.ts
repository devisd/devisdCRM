/**
 * Single HTTP client for the PHP API (same origin, /api).
 *
 * - Always sends cookies (`credentials: "include"`); the session cookie is HttpOnly and is
 *   never read or stored by JavaScript.
 * - Adds X-CSRF-Token to state-changing requests when a token is known (kept in memory).
 * - Never sets Origin: the browser does.
 * - Never retries: a failed request is reported once to the caller.
 * - Never logs request bodies, cookies or tokens.
 */

export const API_BASE_URL = "/api";

export type ApiErrorKind = "http" | "network" | "invalid_response";

export class ApiError extends Error {
  readonly kind: ApiErrorKind;
  readonly status: number | null;
  readonly code: string | null;
  readonly retryAfterSeconds: number | null;

  constructor(
    kind: ApiErrorKind,
    message: string,
    options: { status?: number | null; code?: string | null; retryAfterSeconds?: number | null } = {},
  ) {
    super(message);
    this.name = "ApiError";
    this.kind = kind;
    this.status = options.status ?? null;
    this.code = options.code ?? null;
    this.retryAfterSeconds = options.retryAfterSeconds ?? null;
  }
}

export type HttpMethod = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";

export type ApiRequestOptions = {
  /** JSON-serialisable body. */
  body?: unknown;
  /**
   * Public requests (login, session check) report 401 to the caller only. Protected
   * requests additionally call onUnauthorized so that the app shows the login screen.
   */
  public?: boolean;
};

export type ApiClientOptions = {
  fetch?: typeof fetch;
  baseUrl?: string;
  getCsrfToken?: () => string | null;
  onUnauthorized?: () => void;
};

export type ApiClient = {
  request<T>(method: HttpMethod, path: string, options?: ApiRequestOptions): Promise<T>;
};

/** Seconds from a Retry-After header (delta-seconds or HTTP-date), or null. */
export function parseRetryAfter(value: string | null, now: number = Date.now()): number | null {
  if (value === null || value.trim() === "") {
    return null;
  }
  const trimmed = value.trim();
  if (/^\d+$/.test(trimmed)) {
    return Number(trimmed);
  }
  const date = Date.parse(trimmed);
  if (Number.isNaN(date)) {
    return null;
  }

  return Math.max(0, Math.ceil((date - now) / 1000));
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

export function createApiClient(options: ApiClientOptions = {}): ApiClient {
  const baseUrl = options.baseUrl ?? API_BASE_URL;

  return {
    async request<T>(method: HttpMethod, path: string, requestOptions: ApiRequestOptions = {}): Promise<T> {
      const doFetch = options.fetch ?? globalThis.fetch.bind(globalThis);
      const headers: Record<string, string> = { Accept: "application/json" };
      let body: string | undefined;
      if (requestOptions.body !== undefined) {
        headers["Content-Type"] = "application/json";
        body = JSON.stringify(requestOptions.body);
      }
      if (method !== "GET") {
        const csrfToken = options.getCsrfToken?.() ?? null;
        if (csrfToken !== null) {
          headers["X-CSRF-Token"] = csrfToken;
        }
      }

      let response: Response;
      try {
        response = await doFetch(baseUrl + path, {
          method,
          headers,
          body,
          credentials: "include",
          cache: "no-store",
        });
      } catch {
        throw new ApiError("network", "Network request failed");
      }

      const isJson = (response.headers.get("Content-Type") ?? "").toLowerCase().includes("application/json");
      let payload: unknown = null;
      if (isJson) {
        try {
          payload = await response.json();
        } catch {
          payload = null;
        }
      }

      if (!response.ok) {
        const error = isRecord(payload) && isRecord(payload.error) ? payload.error : null;
        const code = error !== null && typeof error.code === "string" ? error.code : null;
        if (response.status === 401 && !requestOptions.public) {
          options.onUnauthorized?.();
        }
        throw new ApiError("http", `Request failed with status ${response.status}`, {
          status: response.status,
          code,
          retryAfterSeconds: parseRetryAfter(response.headers.get("Retry-After")),
        });
      }

      if (!isRecord(payload) || payload.ok !== true || !("data" in payload)) {
        throw new ApiError("invalid_response", "Unexpected response from the server", { status: response.status });
      }

      return payload.data as T;
    },
  };
}
