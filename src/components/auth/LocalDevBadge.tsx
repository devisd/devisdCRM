import styles from "./LocalDevBadge.module.scss";

/** Marks `next dev` sessions where the login check is skipped (see lib/auth/localDev.ts). */
export function LocalDevBadge() {
  return (
    <p className={styles.badge} role="note">
      Локальная разработка · авторизация отключена
    </p>
  );
}
