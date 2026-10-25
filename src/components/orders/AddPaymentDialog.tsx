"use client";

import { useEffect, useId, useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { TextInput } from "@/components/ui/TextInput";
import { TextArea } from "@/components/ui/TextArea";
import { Select } from "@/components/ui/Select";
import { Alert } from "@/components/ui/Alert";
import { formatRubFromMinor } from "@/lib/format/currency";
import { todayDateInputValue } from "@/lib/format/dates";
import { toUserDbMessage } from "@/lib/db/errors";
import { addPayment } from "@/lib/repositories/orders";
import { PAYMENT_METHOD_LABELS } from "@/lib/services/labels";
import {
  createEmptyPaymentFormValues,
  validatePaymentForm,
  type PaymentFormValues,
} from "@/lib/validation/paymentForm";
import type { PaymentMethod } from "@/types";
import styles from "./AddPaymentDialog.module.scss";

type AddPaymentDialogProps = {
  open: boolean;
  orderId: string;
  remainingMinor: number;
  onClose: () => void;
  onSaved: () => void;
};

export function AddPaymentDialog({
  open,
  orderId,
  remainingMinor,
  onClose,
  onSaved,
}: AddPaymentDialogProps) {
  const titleId = useId();
  const [values, setValues] = useState<PaymentFormValues>(() =>
    createEmptyPaymentFormValues(remainingMinor, todayDateInputValue()),
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) {
      return;
    }

    const timer = window.setTimeout(() => {
      setValues(
        createEmptyPaymentFormValues(remainingMinor, todayDateInputValue()),
      );
      setErrors({});
      setFormError(null);
      setSubmitting(false);
    }, 0);

    return () => window.clearTimeout(timer);
  }, [open, remainingMinor]);

  useEffect(() => {
    if (!open) {
      return;
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape" && !submitting) {
        onClose();
      }
    };

    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [open, submitting, onClose]);

  if (!open) {
    return null;
  }

  const setField = <K extends keyof PaymentFormValues>(
    key: K,
    value: PaymentFormValues[K],
  ) => {
    setValues((current) => ({ ...current, [key]: value }));
    setErrors((current) => {
      const next = { ...current };
      delete next[String(key)];
      return next;
    });
  };

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setFormError(null);

    const validated = validatePaymentForm(values, remainingMinor);
    if (!validated.ok) {
      setErrors(validated.errors);
      return;
    }

    setSubmitting(true);
    try {
      await addPayment({
        orderId,
        ...validated.input,
      });
      onSaved();
      onClose();
    } catch (error) {
      setFormError(toUserDbMessage(error));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className={styles.backdrop} role="presentation" onClick={onClose}>
      <div
        className={styles.dialog}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        onClick={(event) => event.stopPropagation()}
      >
        <h2 id={titleId} className={styles.title}>
          Добавить оплату
        </h2>
        <p className={styles.hint}>
          Остаток к оплате: <strong>{formatRubFromMinor(remainingMinor)}</strong>
        </p>

        <form className={styles.form} onSubmit={onSubmit} noValidate>
          {formError ? (
            <Alert tone="error" title="Не удалось сохранить">
              {formError}
            </Alert>
          ) : null}

          <Field label="Сумма, ₽" htmlFor="payment-amount" required error={errors.amount}>
            <TextInput
              id="payment-amount"
              inputMode="decimal"
              value={values.amount}
              onChange={(event) => setField("amount", event.target.value)}
              aria-invalid={Boolean(errors.amount)}
              autoFocus
              required
            />
          </Field>

          <Field
            label="Дата оплаты"
            htmlFor="payment-date"
            required
            error={errors.paymentDate}
          >
            <TextInput
              id="payment-date"
              type="date"
              value={values.paymentDate}
              onChange={(event) => setField("paymentDate", event.target.value)}
              required
            />
          </Field>

          <Field label="Способ оплаты" htmlFor="payment-method" error={errors.method}>
            <Select
              id="payment-method"
              value={values.method}
              onChange={(event) =>
                setField("method", event.target.value as PaymentMethod)
              }
            >
              {Object.entries(PAYMENT_METHOD_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Комментарий" htmlFor="payment-comment" error={errors.comment}>
            <TextArea
              id="payment-comment"
              value={values.comment}
              onChange={(event) => setField("comment", event.target.value)}
              rows={3}
            />
          </Field>

          <div className={styles.actions}>
            <Button
              type="button"
              variant="secondary"
              onClick={onClose}
              disabled={submitting}
            >
              Отмена
            </Button>
            <Button type="submit" disabled={submitting || remainingMinor <= 0}>
              {submitting ? "Сохранение…" : "Сохранить оплату"}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
