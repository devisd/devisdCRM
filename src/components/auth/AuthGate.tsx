"use client";

import type { ReactNode } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { LoadingState } from "@/components/ui/LoadingState";
import { gateView } from "@/lib/auth/authStore";
import { AuthBrand } from "./AuthBrand";
import { useAuthState, useAuthStore } from "./AuthProvider";
import { LoginScreen } from "./LoginScreen";
import styles from "./AuthScreens.module.scss";

/** Shows the CRM only for an authenticated owner; nothing protected renders before that. */
export function AuthGate({ children }: { children: ReactNode }) {
  const store = useAuthStore();
  const state = useAuthState();

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
