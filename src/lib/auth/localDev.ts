/**
 * Local development without the PHP API: active only under `next dev`.
 *
 * Next.js inlines both values at compile time (next/dist/build/define-env.js):
 * NODE_ENV is "production" for `next build` unless experimental.allowDevelopmentBuild is
 * set, and __NEXT_DEV_SERVER is "1" only for the dev server. Requiring both keeps a
 * development-mode build from enabling the bypass; in the static export the condition is
 * the literal `false` and the branch is dropped. If a future Next.js stops defining
 * __NEXT_DEV_SERVER, the bypass is simply off. There is no NEXT_PUBLIC_* switch.
 */
export const LOCAL_DEV_AUTH_BYPASS: boolean =
  process.env.NODE_ENV === "development" && process.env.__NEXT_DEV_SERVER === "1";

/** The same rule as LOCAL_DEV_AUTH_BYPASS, for tests with explicit values. */
export function isLocalDevAuthBypass(nodeEnv: string | undefined, devServer: string | undefined): boolean {
  return nodeEnv === "development" && devServer === "1";
}
