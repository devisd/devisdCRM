"use client";

import { useState } from "react";
import { LogOut } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { useAuthState, useAuthStore } from "./AuthProvider";
import styles from "./LogoutButton.module.scss";

/** Ends the session. Local CRM data in IndexedDB is left untouched. */
export function LogoutButton({ showUser = true }: { showUser?: boolean }) {
  const store = useAuthStore();
  const state = useAuthState();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (state.status !== "authenticated") {
    return null;
  }

  async function handleLogout() {
    if (pending) {
      return;
    }
    setPending(true);
    setError(null);
    const outcome = await store.logout();
    if (!outcome.ok) {
      setError(outcome.message);
      setPending(false);
    }
  }

  return (
    <div className={styles.root}>
      {showUser ? (
        <p className={styles.user}>
          Вход: <span className={styles.login}>{state.user.login}</span>
        </p>
      ) : null}
      <Button variant="ghost" size="sm" onClick={handleLogout} disabled={pending}>
        <LogOut size={16} aria-hidden />
        {pending ? "Выход…" : "Выйти"}
      </Button>
      {error ? (
        <p className={styles.error} role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}
