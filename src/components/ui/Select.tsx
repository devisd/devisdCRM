import type { SelectHTMLAttributes } from "react";
import fieldStyles from "./Field.module.scss";

export type SelectProps = SelectHTMLAttributes<HTMLSelectElement>;

export function Select({ className, children, ...rest }: SelectProps) {
  const classes = [fieldStyles.control, className].filter(Boolean).join(" ");
  return (
    <select className={classes} {...rest}>
      {children}
    </select>
  );
}
