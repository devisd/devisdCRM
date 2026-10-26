import { describe, expect, it, vi } from "vitest";
import { ApiError, createApiClient, parseRetryAfter } from "@/lib/api/client";

function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json; charset=utf-8", ...headers },
  });
}

function fakeFetch(respond: () => Response | Promise<Response>) {
  const calls: { url: string; init: RequestInit }[] = [];
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    calls.push({ url: String(input), init: init ?? {} });
    return respond();
  });
  return { fetch: fetchMock as unknown as typeof fetch, calls, fetchMock };
}

function headersOf(init: RequestInit): Record<string, string> {
  return (init.headers ?? {}) as Record<string, string>;
}

describe("parseRetryAfter", () => {
  it("reads delta-seconds", () => {
    expect(parseRetryAfter("120")).toBe(120);
  });

  it("reads an HTTP date relative to now", () => {
    const now = Date.parse("2026-10-26T10:00:00Z");
    expect(parseRetryAfter("Mon, 26 Oct 2026 10:01:30 GMT", now)).toBe(90);
  });

  it("ignores missing or invalid values", () => {
    expect(parseRetryAfter(null)).toBeNull();
    expect(parseRetryAfter("")).toBeNull();
    expect(parseRetryAfter("soon")).toBeNull();
  });
});

describe("createApiClient", () => {
  it("sends cookies, JSON and no CSRF header or Origin on GET", async () => {
    const { fetch, calls } = fakeFetch(() => jsonResponse(200, { ok: true, data: { status: "ok" } }));
    const client = createApiClient({ fetch, getCsrfToken: () => "csrf-token" });

    await expect(client.request("GET", "/health")).resolves.toEqual({ status: "ok" });
    expect(calls[0].url).toBe("/api/health");
    expect(calls[0].init.credentials).toBe("include");
    expect(headersOf(calls[0].init)["X-CSRF-Token"]).toBeUndefined();
    expect(Object.keys(headersOf(calls[0].init)).map((h) => h.toLowerCase())).not.toContain("origin");
  });

  it("adds the CSRF token and JSON body to state-changing requests", async () => {
    const { fetch, calls } = fakeFetch(() => jsonResponse(200, { ok: true, data: {} }));
    const client = createApiClient({ fetch, getCsrfToken: () => "csrf-token" });

    await client.request("POST", "/something", { body: { a: 1 } });
    expect(headersOf(calls[0].init)["X-CSRF-Token"]).toBe("csrf-token");
    expect(headersOf(calls[0].init)["Content-Type"]).toBe("application/json");
    expect(calls[0].init.body).toBe('{"a":1}');
    expect(calls[0].init.credentials).toBe("include");
  });

  it("reports HTTP errors with status, code and Retry-After", async () => {
    const { fetch } = fakeFetch(() =>
      jsonResponse(429, { ok: false, error: { code: "too_many_attempts", message: "x" } }, { "Retry-After": "300" }),
    );
    const error = await createApiClient({ fetch }).request("POST", "/auth/login", { public: true }).catch((e) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ kind: "http", status: 429, code: "too_many_attempts", retryAfterSeconds: 300 });
  });

  it("reports network failures and non-JSON success responses", async () => {
    const offline = fakeFetch(() => Promise.reject(new TypeError("Failed to fetch")));
    await expect(createApiClient({ fetch: offline.fetch }).request("GET", "/health")).rejects.toMatchObject({
      kind: "network",
    });

    const html = fakeFetch(() => new Response("<html></html>", { status: 200, headers: { "Content-Type": "text/html" } }));
    await expect(createApiClient({ fetch: html.fetch }).request("GET", "/health")).rejects.toMatchObject({
      kind: "invalid_response",
    });
  });

  it("calls onUnauthorized for a protected 401, not for a public one, and never retries", async () => {
    const onUnauthorized = vi.fn();
    const { fetch, fetchMock } = fakeFetch(() =>
      jsonResponse(401, { ok: false, error: { code: "unauthorized", message: "x" } }),
    );
    const client = createApiClient({ fetch, onUnauthorized });

    await expect(client.request("POST", "/auth/login", { public: true })).rejects.toMatchObject({ status: 401 });
    expect(onUnauthorized).not.toHaveBeenCalled();

    await expect(client.request("POST", "/clients", { body: {} })).rejects.toMatchObject({ status: 401 });
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });
});
