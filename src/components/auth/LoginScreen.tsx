"use client";

import { useState, type FormEvent } from "react";
import { Eye, EyeOff } from "lucide-react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { TextInput } from "@/components/ui/TextInput";
import { loginFailureMessage } from "@/lib/auth/messages";
import { useAuthStore } from "./AuthProvider";
import { AuthBrand } from "./AuthBrand";
import styles from "./AuthScreens.module.scss";

export function LoginScreen({ sessionExpired }: { sessionExpired: boolean }) {
  const store = useAuthStore();
  const [login, setLogin] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting) {
      return;
    }
    if (login.trim() === "" || password === "") {
      setError(loginFailureMessage({ kind: "validation" }));
      return;
    }

    setSubmitting(true);
    setError(null);
    const outcome = await store.login(login.trim(), password);
    // On success the auth gate replaces this screen with the CRM.
    if (!outcome.ok) {
      setError(loginFailureMessage(outcome.failure));
      setSubmitting(false);
    }
  }

  return (
    <div className={styles.page}>
      <div className={styles.card}>
        <AuthBrand />
        <h1 className={styles.title}>Вход</h1>

        {sessionExpired ? (
          <Alert tone="info">Сессия завершена. Войдите снова.</Alert>
        ) : null}

        <form className={styles.form} onSubmit={handleSubmit} noValidate aria-busy={submitting}>
          <Field label="Логин" htmlFor="auth-login" required>
            <TextInput
              id="auth-login"
              name="username"
              autoComplete="username"
              autoCapitalize="none"
              spellCheck={false}
              maxLength={64}
              value={login}
              onChange={(event) => setLogin(event.target.value)}
              disabled={submitting}
              required
            />
          </Field>

          <Field label="Пароль" htmlFor="auth-password" required>
            <div className={styles.passwordRow}>
              <TextInput
                id="auth-password"
                name="password"
                type={showPassword ? "text" : "password"}
                autoComplete="current-password"
                maxLength={1024}
                className={styles.passwordInput}
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                disabled={submitting}
                required
              />
              <button
                type="button"
                className={styles.toggle}
                onClick={() => setShowPassword((value) => !value)}
                aria-label={showPassword ? "Скрыть пароль" : "Показать пароль"}
                aria-pressed={showPassword}
                aria-controls="auth-password"
                disabled={submitting}
              >
                {showPassword ? <EyeOff size={18} aria-hidden /> : <Eye size={18} aria-hidden />}
              </button>
            </div>
          </Field>

          {error ? <Alert tone="error">{error}</Alert> : null}

          <Button type="submit" className={styles.submit} disabled={submitting}>
            {submitting ? "Вход…" : "Войти"}
          </Button>
        </form>
      </div>
    </div>
  );
}
