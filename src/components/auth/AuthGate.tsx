"use client";

import type { ReactNode } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { LoadingState } from "@/components/ui/LoadingState";
import { gateView } from "@/lib/auth/authStore";
import { AuthBrand } from "./AuthBrand";
import { useAuthState, useAuthStore } from "./AuthProvider";
import { LocalDevBadge } from "./LocalDevBadge";
import { LoginScreen } from "./LoginScreen";
import styles from "./AuthScreens.module.scss";

// The rule of LOCAL_DEV_AUTH_BYPASS (lib/auth/localDev.ts), written out here: the bundler
// folds a module-local condition to `false` in the production build and drops the local-dev
// branch with its badge, which it does not do for an imported constant.
const LOCAL_DEV_AUTH_BYPASS_INLINE =
  process.env.NODE_ENV === "development" && process.env.__NEXT_DEV_SERVER === "1";

/**
 * Shows the CRM only for an authenticated owner; nothing protected renders before that.
 * Under `next dev` only, the CRM is shown without a session (no login, no logout button).
 */
export function AuthGate({ children }: { children: ReactNode }) {
  const store = useAuthStore();
  const state = useAuthState();

  if (LOCAL_DEV_AUTH_BYPASS_INLINE) {
    return (
      <>
        {children}
        <LocalDevBadge />
      </>
    );
  }

  switch (gateView(state)) {
    case "checking":
      return (
        <div className={styles.page}>
          <div className={styles.card}>
            <AuthBrand />
            <LoadingState label="Проверка входа…" />
          </div>
        </div>
      );
    case "error":
      return (
        <div className={styles.page}>
          <div className={styles.card}>
            <AuthBrand />
            <Alert tone="error" title="Нет связи с сервером">
              {state.status === "error" ? state.message : null}
            </Alert>
            <Button onClick={() => void store.checkSession()}>Повторить</Button>
          </div>
        </div>
      );
    case "login":
      return (
        <LoginScreen
          sessionExpired={state.status === "unauthenticated" && state.reason === "expired"}
        />
      );
    case "app":
      return <>{children}</>;
  }
}
