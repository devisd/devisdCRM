import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createAuthStore, gateView, startSessionCheck } from "@/lib/auth/authStore";
import { isLocalDevAuthBypass } from "@/lib/auth/localDev";

const RULE = 'process.env.NODE_ENV === "development" && process.env.__NEXT_DEV_SERVER === "1"';

function source(relativePath: string): string {
  return readFileSync(resolve(__dirname, relativePath), "utf8").replace(/\s+/g, " ");
}

async function importBypass(env: Record<string, string | undefined>): Promise<boolean> {
  for (const [name, value] of Object.entries(env)) {
    vi.stubEnv(name, value);
  }
  vi.resetModules();
  const localDev = await import("@/lib/auth/localDev");
  return localDev.LOCAL_DEV_AUTH_BYPASS;
}

function fakeFetch(respond: () => Response | Promise<Response>) {
  const fetchMock = vi.fn(async () => respond());
  return { fetch: fetchMock as unknown as typeof fetch, fetchMock };
}

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.resetModules();
});

describe("local development auth bypass", () => {
  it("requires both the development NODE_ENV and the Next.js dev server", () => {
    expect(isLocalDevAuthBypass("development", "1")).toBe(true);
    expect(isLocalDevAuthBypass("development", "")).toBe(false);
    expect(isLocalDevAuthBypass("development", undefined)).toBe(false);
    expect(isLocalDevAuthBypass("production", "1")).toBe(false);
    expect(isLocalDevAuthBypass("production", undefined)).toBe(false);
    expect(isLocalDevAuthBypass("test", "1")).toBe(false);
    expect(isLocalDevAuthBypass(undefined, undefined)).toBe(false);
  });

  it("is off in production whatever NEXT_PUBLIC_* variables say", async () => {
    expect(
      await importBypass({
        NODE_ENV: "production",
        __NEXT_DEV_SERVER: "",
        NEXT_PUBLIC_AUTH_DISABLED: "1",
        NEXT_PUBLIC_DEV_AUTH_BYPASS: "true",
      }),
    ).toBe(false);
  });

  it("is off for a development-mode build without the dev server", async () => {
    expect(await importBypass({ NODE_ENV: "development", __NEXT_DEV_SERVER: "" })).toBe(false);
  });

  it("is off under the test runner", async () => {
    expect(await importBypass({})).toBe(false);
  });

  it("is on only for the Next.js dev server", async () => {
    expect(await importBypass({ NODE_ENV: "development", __NEXT_DEV_SERVER: "1" })).toBe(true);
  });

  it("uses one rule in localDev.ts and in AuthGate, with no NEXT_PUBLIC_* switch", () => {
    const lib = source("./localDev.ts");
    const gate = source("../../components/auth/AuthGate.tsx");
    expect(lib).toContain(RULE);
    expect(gate).toContain(RULE);
    expect(gate).toMatch(/if \(LOCAL_DEV_AUTH_BYPASS_INLINE\) \{ return \( <> \{children\} <LocalDevBadge \/>/);
    for (const text of [lib, gate, source("../../components/auth/AuthProvider.tsx")]) {
      expect(text).not.toContain("process.env.NEXT_PUBLIC");
    }
  });
});

describe("app behaviour with the bypass", () => {
  it("skips /api/auth/me and the login screen without inventing a session", () => {
    const local = { setItem: vi.fn(), getItem: vi.fn(), removeItem: vi.fn(), clear: vi.fn() };
    vi.stubGlobal("localStorage", local);
    vi.stubGlobal("sessionStorage", local);
    const { fetch, fetchMock } = fakeFetch(() => new Response(null, { status: 500 }));
    const store = createAuthStore({ fetch });

    startSessionCheck(store, true);
    expect(fetchMock).not.toHaveBeenCalled();
    expect(store.getState()).toEqual({ status: "checking" });
    expect(local.setItem).not.toHaveBeenCalled();
  });

  it("checks the session normally without the bypass", async () => {
    const { fetch, fetchMock } = fakeFetch(
      () => new Response(JSON.stringify({ ok: false, error: { code: "unauthorized", message: "x" } }), {
        status: 401,
        headers: { "Content-Type": "application/json" },
      }),
    );
    const store = createAuthStore({ fetch });

    startSessionCheck(store, false);
    await vi.waitFor(() => expect(store.getState().status).toBe("unauthenticated"));
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(gateView(store.getState())).toBe("login");
  });

  it("leaves the API client reporting a missing server as usual", async () => {
    const offline = createAuthStore({ fetch: fakeFetch(() => Promise.reject(new TypeError("Failed to fetch"))).fetch });
    startSessionCheck(offline, true);
    await expect(offline.api.request("GET", "/health")).rejects.toMatchObject({ kind: "network" });

    const devServer404 = createAuthStore({
      fetch: fakeFetch(() => new Response("<html>404</html>", { status: 404, headers: { "Content-Type": "text/html" } })).fetch,
    });
    startSessionCheck(devServer404, true);
    await expect(devServer404.api.request("POST", "/clients", { body: {} })).rejects.toMatchObject({
      kind: "http",
      status: 404,
    });
    expect(devServer404.getState()).toEqual({ status: "checking" });
  });
});
