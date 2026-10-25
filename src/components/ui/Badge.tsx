import type { ReactNode } from "react";
import styles from "./Badge.module.scss";

type BadgeTone =
  | "neutral"
  | "new"
  | "inProgress"
  | "waiting"
  | "completed"
  | "cancelled"
  | "unpaid"
  | "partial"
  | "paid";

export type BadgeProps = {
  tone?: BadgeTone;
  children: ReactNode;
};

export function Badge({ tone = "neutral", children }: BadgeProps) {
  return (
    <span className={`${styles.badge} ${styles[tone]}`}>{children}</span>
  );
}
