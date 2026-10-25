import type { InputHTMLAttributes } from "react";
import fieldStyles from "./Field.module.scss";

export type TextInputProps = InputHTMLAttributes<HTMLInputElement>;

export function TextInput({ className, ...rest }: TextInputProps) {
  const classes = [fieldStyles.control, className].filter(Boolean).join(" ");
  return <input className={classes} {...rest} />;
}
