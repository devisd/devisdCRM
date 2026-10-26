import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createAuthStore, gateView } from "@/lib/auth/authStore";
import { loginFailureMessage } from "@/lib/auth/messages";

const CSRF = "c".repeat(64);
const PASSWORD = "test-password-not-real";
const SESSION = { user: { login: "owner" }, csrfToken: CSRF };

type Call = { url: string; method: string; headers: Record<string, string>; body: string | undefined; credentials: string | undefined };

function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json; charset=utf-8", ...headers },
  });
}

const ok = (data: unknown) => jsonResponse(200, { ok: true, data });
const fail = (status: number, code: string, headers: Record<string, string> = {}) =>
  jsonResponse(status, { ok: false, error: { code, message: "x" } }, headers);

/** Routes "METHOD /path" to queued responses (or a factory); records every call. */
function fakeServer(routes: Record<string, Array<() => Response | Promise<Response>>>) {
  const calls: Call[] = [];
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const method = init?.method ?? "GET";
    const url = String(input);
    calls.push({
      url,
      method,
      headers: (init?.headers ?? {}) as Record<string, string>,
      body: init?.body as string | undefined,
      credentials: init?.credentials,
    });
    const queue = routes[`${method} ${url}`];
    const next = queue?.shift();
    if (!next) {
      throw new Error(`Unexpected request ${method} ${url}`);
    }
    return next();
  });
  return { fetch: fetchMock as unknown as typeof fetch, calls, fetchMock };
}

function storageSpy() {
  return { getItem: vi.fn(), setItem: vi.fn(), removeItem: vi.fn(), clear: vi.fn(), key: vi.fn(), length: 0 };
}

let local: ReturnType<typeof storageSpy>;
let session: ReturnType<typeof storageSpy>;
let indexedDbSpy: { open: ReturnType<typeof vi.fn>; deleteDatabase: ReturnType<typeof vi.fn> };
let consoleSpies: ReturnType<typeof vi.spyOn>[];

beforeEach(() => {
  local = storageSpy();
  session = storageSpy();
  indexedDbSpy = { open: vi.fn(), deleteDatabase: vi.fn() };
  vi.stubGlobal("localStorage", local);
  vi.stubGlobal("sessionStorage", session);
  vi.stubGlobal("indexedDB", indexedDbSpy);
  consoleSpies = (["log", "info", "warn", "error", "debug"] as const).map((name) =>
    vi.spyOn(console, name).mockImplementation(() => undefined),
  );
});

afterEach(() => {
  // Nothing auth-related is ever persisted, logged or touches the CRM database.
  for (const storage of [local, session]) {
    expect(storage.setItem).not.toHaveBeenCalled();
    expect(storage.removeItem).not.toHaveBeenCalled();
    expect(storage.clear).not.toHaveBeenCalled();
  }
  expect(indexedDbSpy.open).not.toHaveBeenCalled();
  expect(indexedDbSpy.deleteDatabase).not.toHaveBeenCalled();
  for (const spy of consoleSpies) {
    expect(spy).not.toHaveBeenCalled();
  }
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

async function loggedInStore(extraRoutes: Record<string, Array<() => Response | Promise<Response>>> = {}) {
  const server = fakeServer({
    ...extraRoutes,
    "POST /api/auth/login": [() => ok(SESSION), ...(extraRoutes["POST /api/auth/login"] ?? [])],
  });
  const store = createAuthStore({ fetch: server.fetch });
  await store.login("owner", PASSWORD);
  expect(store.getState()).toEqual({ status: "authenticated", user: { login: "owner" } });
  return { store, server };
}

describe("initial session check", () => {
  it("starts as checking and shows no CRM screen until the server answers", async () => {
    let release: (response: Response) => void = () => undefined;
    const server = fakeServer({ "GET /api/auth/me": [() => new Promise<Response>((resolve) => (release = resolve))] });
    const store = createAuthStore({ fetch: server.fetch });

    expect(gateView(store.getState())).toBe("checking");
    const pending = store.checkSession();
    expect(store.getState()).toEqual({ status: "checking" });

    release(ok(SESSION));
    await pending;
    expect(store.getState()).toEqual({ status: "authenticated", user: { login: "owner" } });
    expect(gateView(store.getState())).toBe("app");
    expect(server.calls[0]).toMatchObject({ method: "GET", url: "/api/auth/me", credentials: "include" });
  });

  it("shows the login form on 401", async () => {
    const server = fakeServer({ "GET /api/auth/me": [() => fail(401, "unauthorized")] });
    const store = createAuthStore({ fetch: server.fetch });

    await store.checkSession();
    expect(store.getState()).toEqual({ status: "unauthenticated", reason: "initial" });
    expect(gateView(store.getState())).toBe("login");
  });

  it.each([
    ["a network error", () => Promise.reject(new TypeError("Failed to fetch"))],
    ["a 5xx response", () => fail(503, "db_unavailable")],
    ["a non-API 404 page", () => new Response("<html>Not Found</html>", { status: 404, headers: { "Content-Type": "text/html" } })],
  ])("does not log the owner out on %s and allows a retry", async (_label, failure) => {
    const server = fakeServer({ "GET /api/auth/me": [failure, () => ok(SESSION)] });
    const store = createAuthStore({ fetch: server.fetch });

    await store.checkSession();
    expect(store.getState().status).toBe("error");
    expect(gateView(store.getState())).toBe("error");

    await store.checkSession();
    expect(store.getState()).toEqual({ status: "authenticated", user: { login: "owner" } });
  });

  it("ignores an older session check that finishes last", async () => {
    let releaseFirst: (response: Response) => void = () => undefined;
    const server = fakeServer({
      "GET /api/auth/me": [() => new Promise<Response>((resolve) => (releaseFirst = resolve)), () => fail(401, "unauthorized")],
    });
    const store = createAuthStore({ fetch: server.fetch });

    const first = store.checkSession();
    await store.checkSession();
    releaseFirst(ok(SESSION));
    await first;
    expect(store.getState()).toEqual({ status: "unauthenticated", reason: "initial" });
  });
});

describe("login", () => {
  it("posts JSON with cookies and opens the CRM with the returned user", async () => {
    const server = fakeServer({ "POST /api/auth/login": [() => ok(SESSION)] });
    const store = createAuthStore({ fetch: server.fetch });

    await expect(store.login("owner", PASSWORD)).resolves.toEqual({ ok: true });
    expect(store.getState()).toEqual({ status: "authenticated", user: { login: "owner" } });
    expect(server.calls[0]).toMatchObject({ method: "POST", url: "/api/auth/login", credentials: "include" });
    expect(server.calls[0].headers["Content-Type"]).toBe("application/json");
    expect(JSON.parse(server.calls[0].body ?? "")).toEqual({ login: "owner", password: PASSWORD });
    expect(JSON.stringify(store.getState())).not.toContain(CSRF);
  });

  it("shows one neutral message for wrong credentials and does not retry", async () => {
    const server = fakeServer({ "POST /api/auth/login": [() => fail(401, "invalid_credentials")] });
    const store = createAuthStore({ fetch: server.fetch });
    await store.checkSession().catch(() => undefined);

    const outcome = await store.login("owner", "wrong");
    expect(outcome).toEqual({ ok: false, failure: { kind: "invalid_credentials" } });
    expect(outcome.ok ? "" : loginFailureMessage(outcome.failure)).toBe("Неверный логин или пароль");
    expect(server.fetchMock).toHaveBeenCalledTimes(2);
  });

  it("reports the rate limit with the Retry-After wait", async () => {
    const server = fakeServer({
      "POST /api/auth/login": [() => fail(429, "too_many_attempts", { "Retry-After": "120" }), () => fail(429, "too_many_attempts")],
    });
    const store = createAuthStore({ fetch: server.fetch });

    const limited = await store.login("owner", "wrong");
    expect(limited).toEqual({ ok: false, failure: { kind: "rate_limited", retryAfterSeconds: 120 } });
    expect(limited.ok ? "" : loginFailureMessage(limited.failure)).toBe(
      "Слишком много попыток входа. Повторите через 2 мин.",
    );

    const withoutHeader = await store.login("owner", "wrong");
    expect(withoutHeader.ok ? "" : loginFailureMessage(withoutHeader.failure)).toBe(
      "Слишком много попыток входа. Повторите позже.",
    );
  });

  it.each([
    ["5xx", () => fail(500, "internal_error")],
    ["network error", () => Promise.reject(new TypeError("Failed to fetch"))],
  ])("reports the server as unavailable on %s", async (_label, failure) => {
    const server = fakeServer({ "POST /api/auth/login": [failure] });
    const store = createAuthStore({ fetch: server.fetch });

    const outcome = await store.login("owner", PASSWORD);
    expect(outcome).toEqual({ ok: false, failure: { kind: "unavailable" } });
    expect(outcome.ok ? "" : loginFailureMessage(outcome.failure)).toBe(
      "Сервер временно недоступен. Попробуйте ещё раз позже.",
    );
    expect(store.getState().status).not.toBe("authenticated");
  });
});

describe("logout", () => {
  it("sends the CSRF token with cookies and clears the in-memory session", async () => {
    const { store, server } = await loggedInStore({ "POST /api/auth/logout": [() => ok({})] });

    await expect(store.logout()).resolves.toEqual({ ok: true });
    const call = server.calls.find((c) => c.url === "/api/auth/logout");
    expect(call).toMatchObject({ method: "POST", credentials: "include" });
    expect(call?.headers["X-CSRF-Token"]).toBe(CSRF);
    expect(store.getState()).toEqual({ status: "unauthenticated", reason: "logged_out" });
  });

  it("treats an already expired session as logged out", async () => {
    const { store } = await loggedInStore({ "POST /api/auth/logout": [() => fail(401, "unauthorized")] });

    await expect(store.logout()).resolves.toEqual({ ok: true });
    expect(store.getState()).toEqual({ status: "unauthenticated", reason: "expired" });
  });

  it("stays logged in when the server cannot be reached, without retrying", async () => {
    const { store, server } = await loggedInStore({
      "POST /api/auth/logout": [() => Promise.reject(new TypeError("Failed to fetch"))],
    });

    const outcome = await store.logout();
    expect(outcome.ok).toBe(false);
    expect(store.getState()).toEqual({ status: "authenticated", user: { login: "owner" } });
    expect(server.calls.filter((c) => c.url === "/api/auth/logout")).toHaveLength(1);
  });
});

describe("protected requests", () => {
  it("send the CSRF token and return to the login screen on 401", async () => {
    const { store, server } = await loggedInStore({ "POST /api/clients": [() => fail(401, "unauthorized")] });

    await expect(store.api.request("POST", "/clients", { body: { name: "x" } })).rejects.toMatchObject({ status: 401 });
    const call = server.calls.find((c) => c.url === "/api/clients");
    expect(call?.headers["X-CSRF-Token"]).toBe(CSRF);
    expect(call?.credentials).toBe("include");
    expect(server.calls.filter((c) => c.url === "/api/clients")).toHaveLength(1);
    expect(store.getState()).toEqual({ status: "unauthenticated", reason: "expired" });
    expect(gateView(store.getState())).toBe("login");
  });

  it("no longer send a CSRF token after logout", async () => {
    const { store, server } = await loggedInStore({
      "POST /api/auth/logout": [() => ok({})],
      "POST /api/auth/login": [() => fail(401, "invalid_credentials")],
    });
    await store.logout();
    await store.login("owner", "wrong");

    const lastLogin = server.calls.filter((c) => c.url === "/api/auth/login").at(-1);
    expect(lastLogin?.headers["X-CSRF-Token"]).toBeUndefined();
  });
});
