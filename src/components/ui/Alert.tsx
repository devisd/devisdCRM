import type { ReactNode } from "react";
import styles from "./Alert.module.scss";

type AlertTone = "info" | "error" | "success";

export type AlertProps = {
  tone?: AlertTone;
  title?: string;
  children: ReactNode;
};

export function Alert({ tone = "info", title, children }: AlertProps) {
  return (
    <div
      className={`${styles.alert} ${styles[tone]}`}
      role={tone === "error" ? "alert" : "status"}
    >
      {title ? <p className={styles.title}>{title}</p> : null}
      <div className={styles.body}>{children}</div>
    </div>
  );
}
