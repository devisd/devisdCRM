"use client";

import { useMemo, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { TextInput } from "@/components/ui/TextInput";
import { TextArea } from "@/components/ui/TextArea";
import { Alert } from "@/components/ui/Alert";
import { LinkButton } from "@/components/ui/LinkButton";
import { useUnsavedWarning } from "@/lib/hooks/useUnsavedWarning";
import { toUserDbMessage } from "@/lib/db/errors";
import { createClient, updateClient } from "@/lib/repositories/clients";
import {
  clientFormSchema,
  clientToFormValues,
  emptyClientFormValues,
  formValuesToClientInput,
  type ClientFormValues,
} from "@/lib/validation/clientForm";
import { routes } from "@/lib/routes";
import type { Client } from "@/types";
import styles from "./ClientForm.module.scss";

type ClientFormProps = {
  mode: "create" | "edit";
  client?: Client;
};

export function ClientForm({ mode, client }: ClientFormProps) {
  const router = useRouter();
  const initialValues = useMemo(
    () => (client ? clientToFormValues(client) : emptyClientFormValues),
    [client],
  );
  const [values, setValues] = useState<ClientFormValues>(initialValues);
  const [errors, setErrors] = useState<Partial<Record<keyof ClientFormValues, string>>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [isDirty, setIsDirty] = useState(false);

  useUnsavedWarning(isDirty && !submitting);

  const setField = <K extends keyof ClientFormValues>(
    key: K,
    value: ClientFormValues[K],
  ) => {
    setValues((current) => ({ ...current, [key]: value }));
    setIsDirty(true);
    setErrors((current) => ({ ...current, [key]: undefined }));
  };

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setFormError(null);

    const parsed = clientFormSchema.safeParse(values);
    if (!parsed.success) {
      const nextErrors: Partial<Record<keyof ClientFormValues, string>> = {};
      for (const issue of parsed.error.issues) {
        const key = issue.path[0];
        if (typeof key === "string" && !(key in nextErrors)) {
          nextErrors[key as keyof ClientFormValues] = issue.message;
        }
      }
      setErrors(nextErrors);
      return;
    }

    setSubmitting(true);
    try {
      const input = formValuesToClientInput(parsed.data);
      if (mode === "create") {
        const created = await createClient(input);
        setIsDirty(false);
        router.push(routes.clientDetail(created.id));
        return;
      }

      if (!client) {
        throw new Error("Клиент не найден");
      }

      const updated = await updateClient(client.id, input);
      setIsDirty(false);
      router.push(routes.clientDetail(updated.id));
    } catch (error) {
      setFormError(toUserDbMessage(error));
    } finally {
      setSubmitting(false);
    }
  };

  const cancelHref = client ? routes.clientDetail(client.id) : routes.clients;

  return (
    <form className={styles.form} onSubmit={onSubmit} noValidate>
      {formError ? <Alert tone="error" title="Не удалось сохранить">{formError}</Alert> : null}

      <div className={styles.grid}>
        <Field label="Имя / название" htmlFor="client-name" required error={errors.name}>
          <TextInput
            id="client-name"
            name="name"
            value={values.name}
            onChange={(event) => setField("name", event.target.value)}
            aria-invalid={Boolean(errors.name)}
            autoComplete="organization"
            required
          />
        </Field>

        <Field label="Контактное лицо" htmlFor="client-contactName" error={errors.contactName}>
          <TextInput
            id="client-contactName"
            name="contactName"
            value={values.contactName}
            onChange={(event) => setField("contactName", event.target.value)}
          />
        </Field>

        <Field label="Телефон" htmlFor="client-phone" error={errors.phone}>
          <TextInput
            id="client-phone"
            name="phone"
            type="tel"
            value={values.phone}
            onChange={(event) => setField("phone", event.target.value)}
            autoComplete="tel"
          />
        </Field>

        <Field label="Email" htmlFor="client-email" error={errors.email}>
          <TextInput
            id="client-email"
            name="email"
            type="email"
            value={values.email}
            onChange={(event) => setField("email", event.target.value)}
            aria-invalid={Boolean(errors.email)}
            autoComplete="email"
          />
        </Field>

        <Field label="Telegram" htmlFor="client-telegram" error={errors.telegram}>
          <TextInput
            id="client-telegram"
            name="telegram"
            value={values.telegram}
            onChange={(event) => setField("telegram", event.target.value)}
          />
        </Field>

        <Field label="MAX" htmlFor="client-maxContact" error={errors.maxContact}>
          <TextInput
            id="client-maxContact"
            name="maxContact"
            value={values.maxContact}
            onChange={(event) => setField("maxContact", event.target.value)}
          />
        </Field>

        <Field label="Другой контакт" htmlFor="client-otherContact" error={errors.otherContact}>
          <TextInput
            id="client-otherContact"
            name="otherContact"
            value={values.otherContact}
            onChange={(event) => setField("otherContact", event.target.value)}
          />
        </Field>

        <Field label="Источник" htmlFor="client-source" error={errors.source}>
          <TextInput
            id="client-source"
            name="source"
            value={values.source}
            onChange={(event) => setField("source", event.target.value)}
            placeholder="Рекомендация, реклама…"
          />
        </Field>
      </div>

      <Field label="Заметки" htmlFor="client-notes" error={errors.notes}>
        <TextArea
          id="client-notes"
          name="notes"
          value={values.notes}
          onChange={(event) => setField("notes", event.target.value)}
          rows={4}
        />
      </Field>

      <div className={styles.actions}>
        <Button type="submit" disabled={submitting}>
          {submitting ? "Сохранение…" : mode === "create" ? "Создать клиента" : "Сохранить"}
        </Button>
        <LinkButton href={cancelHref} variant="secondary">
          Отмена
        </LinkButton>
      </div>
    </form>
  );
}
