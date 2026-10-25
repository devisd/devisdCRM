import type { TextareaHTMLAttributes } from "react";
import fieldStyles from "./Field.module.scss";

export type TextAreaProps = TextareaHTMLAttributes<HTMLTextAreaElement>;

export function TextArea({ className, ...rest }: TextAreaProps) {
  const classes = [fieldStyles.textarea, className].filter(Boolean).join(" ");
  return <textarea className={classes} {...rest} />;
}
