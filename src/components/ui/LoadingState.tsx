import styles from "./LoadingState.module.scss";

export function LoadingState({ label = "Загрузка…" }: { label?: string }) {
  return (
    <div className={styles.root} role="status" aria-live="polite">
      {label}
    </div>
  );
}
