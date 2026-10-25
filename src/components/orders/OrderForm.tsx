"use client";

import { useMemo, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { TextInput } from "@/components/ui/TextInput";
import { TextArea } from "@/components/ui/TextArea";
import { Select } from "@/components/ui/Select";
import { Alert } from "@/components/ui/Alert";
import { LinkButton } from "@/components/ui/LinkButton";
import { useUnsavedWarning } from "@/lib/hooks/useUnsavedWarning";
import { toUserDbMessage } from "@/lib/db/errors";
import { createOrder, updateOrder } from "@/lib/repositories/orders";
import { formatRubFromMinor, parseRubToMinor } from "@/lib/format/currency";
import {
  formatRubInputFromMinor,
} from "@/lib/format/currency";
import {
  todayDateInputValue,
  toDateInputValue,
} from "@/lib/format/dates";
import { calcLineTotalMinor, calcOrderTotalMinor } from "@/lib/services/money";
import { DEFAULT_UNITS, ORDER_STATUS_LABELS } from "@/lib/services/labels";
import {
  createEmptyOrderFormValues,
  createEmptyOrderItem,
  orderFormSchema,
  parseOrderFormToInput,
  type OrderFormValues,
  type OrderItemFormValues,
} from "@/lib/validation/orderForm";
import type { Client, Order, OrderItem } from "@/types";
import styles from "./OrderForm.module.scss";

type OrderFormProps = {
  mode: "create" | "edit";
  clients: Client[];
  initialClientId?: string;
  order?: Order;
  items?: OrderItem[];
};

function orderToFormValues(
  order: Order,
  items: OrderItem[],
): OrderFormValues {
  return {
    clientId: order.clientId,
    title: order.title,
    description: order.description ?? "",
    status: order.status,
    orderDate: toDateInputValue(order.orderDate),
    dueDate: order.dueDate ? toDateInputValue(order.dueDate) : "",
    discount: formatRubInputFromMinor(order.discountMinor),
    notes: order.notes ?? "",
    items: items.map((item) => ({
      key: item.id,
      id: item.id,
      name: item.name,
      description: item.description ?? "",
      quantity: String(item.quantity),
      unit: item.unit,
      unitPrice: formatRubInputFromMinor(item.unitPriceMinor),
    })),
  };
}

function liveTotals(values: OrderFormValues) {
  try {
    const lineTotals = values.items.map((item) => {
      const quantity = Number(item.quantity.trim().replace(",", "."));
      const unitPriceMinor = parseRubToMinor(item.unitPrice);
      if (!Number.isFinite(quantity) || quantity <= 0 || unitPriceMinor === null) {
        return null;
      }
      return calcLineTotalMinor(quantity, unitPriceMinor);
    });

    if (lineTotals.some((value) => value === null)) {
      return null;
    }

    const subtotal = (lineTotals as number[]).reduce((sum, value) => sum + value, 0);
    const discountRaw = values.discount.trim() === "" ? "0" : values.discount;
    const discountMinor = parseRubToMinor(discountRaw);
    if (discountMinor === null) {
      return null;
    }

    const totalMinor = calcOrderTotalMinor(subtotal, discountMinor);
    return { lineTotals: lineTotals as number[], subtotal, discountMinor, totalMinor };
  } catch {
    return null;
  }
}

export function OrderForm({
  mode,
  clients,
  initialClientId,
  order,
  items = [],
}: OrderFormProps) {
  const router = useRouter();
  const initialValues = useMemo(() => {
    if (order) {
      return orderToFormValues(order, items);
    }
    const values = createEmptyOrderFormValues(initialClientId ?? "");
    values.orderDate = todayDateInputValue();
    return values;
  }, [order, items, initialClientId]);

  const [values, setValues] = useState<OrderFormValues>(initialValues);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [isDirty, setIsDirty] = useState(false);

  useUnsavedWarning(isDirty && !submitting);

  const totals = liveTotals(values);
  const paidConflict =
    mode === "edit" &&
    order &&
    totals !== null &&
    order.paidMinor > totals.totalMinor;
  const activeClients = clients.filter((client) => !client.isArchived || client.id === values.clientId);

  const setField = <K extends keyof OrderFormValues>(key: K, value: OrderFormValues[K]) => {
    setValues((current) => ({ ...current, [key]: value }));
    setIsDirty(true);
    setFieldErrors((current) => {
      const next = { ...current };
      delete next[String(key)];
      return next;
    });
  };

  const setItemField = (
    index: number,
    key: keyof OrderItemFormValues,
    value: string,
  ) => {
    setValues((current) => {
      const itemsCopy = current.items.map((item, itemIndex) =>
        itemIndex === index ? { ...item, [key]: value } : item,
      );
      return { ...current, items: itemsCopy };
    });
    setIsDirty(true);
    setFieldErrors((current) => {
      const next = { ...current };
      delete next[`items.${index}.${key}`];
      return next;
    });
  };

  const addItem = () => {
    setValues((current) => ({
      ...current,
      items: [...current.items, createEmptyOrderItem()],
    }));
    setIsDirty(true);
  };

  const removeItem = (index: number) => {
    setValues((current) => ({
      ...current,
      items:
        current.items.length === 1
          ? current.items
          : current.items.filter((_, itemIndex) => itemIndex !== index),
    }));
    setIsDirty(true);
  };

  const moveItem = (index: number, direction: -1 | 1) => {
    const target = index + direction;
    setValues((current) => {
      if (target < 0 || target >= current.items.length) {
        return current;
      }
      const itemsCopy = [...current.items];
      const [removed] = itemsCopy.splice(index, 1);
      itemsCopy.splice(target, 0, removed);
      return { ...current, items: itemsCopy };
    });
    setIsDirty(true);
  };

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setFormError(null);

    const parsed = orderFormSchema.safeParse(values);
    if (!parsed.success) {
      const nextErrors: Record<string, string> = {};
      for (const issue of parsed.error.issues) {
        const path = issue.path.join(".");
        if (path && !nextErrors[path]) {
          nextErrors[path] = issue.message;
        }
      }
      setFieldErrors(nextErrors);
      return;
    }

    setSubmitting(true);
    try {
      const { clearCompletedAt, ...payload } = parseOrderFormToInput(parsed.data);
      if (mode === "create") {
        const created = await createOrder(payload);
        setIsDirty(false);
        router.push(`/orders/${created.order.id}`);
        return;
      }

      if (!order) {
        throw new Error("Заказ не найден");
      }

      const updated = await updateOrder(order.id, {
        ...payload,
        completedAt: clearCompletedAt ? null : payload.completedAt,
      });
      setIsDirty(false);
      router.push(`/orders/${updated.order.id}`);
    } catch (error) {
      setFormError(toUserDbMessage(error));
    } finally {
      setSubmitting(false);
    }
  };

  if (clients.length === 0) {
    return (
      <Alert tone="error" title="Нет клиентов">
        Сначала создайте клиента, затем оформляйте заказ.{" "}
        <LinkButton href="/clients/new" size="sm">
          Добавить клиента
        </LinkButton>
      </Alert>
    );
  }

  const cancelHref = order ? `/orders/${order.id}` : "/orders";

  return (
    <form className={styles.form} onSubmit={onSubmit} noValidate>
      {formError ? (
        <Alert tone="error" title="Не удалось сохранить">
          {formError}
        </Alert>
      ) : null}

      {paidConflict && order ? (
        <Alert tone="error" title="Сумма оплат превышает новый итог">
          Уже оплачено {formatRubFromMinor(order.paidMinor)}, а новый итог —{" "}
          {formatRubFromMinor(totals.totalMinor)}. Сначала скорректируйте позиции,
          скидку или платежи, затем сохраните заказ.
        </Alert>
      ) : null}

      <section className={styles.section} aria-labelledby="order-main-heading">
        <h2 id="order-main-heading" className={styles.sectionTitle}>
          Клиент и основные данные
        </h2>
        <div className={styles.grid}>
          <Field label="Клиент" htmlFor="order-client" required error={fieldErrors.clientId}>
            <Select
              id="order-client"
              value={values.clientId}
              onChange={(event) => setField("clientId", event.target.value)}
              aria-invalid={Boolean(fieldErrors.clientId)}
              required
            >
              <option value="">Выберите клиента</option>
              {activeClients.map((client) => (
                <option key={client.id} value={client.id}>
                  {client.name}
                  {client.isArchived ? " (архив)" : ""}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Название" htmlFor="order-title" required error={fieldErrors.title}>
            <TextInput
              id="order-title"
              value={values.title}
              onChange={(event) => setField("title", event.target.value)}
              placeholder="Например: Карточки товара для Wildberries"
              aria-invalid={Boolean(fieldErrors.title)}
              required
            />
          </Field>

          <Field label="Дата заказа" htmlFor="order-date" required error={fieldErrors.orderDate}>
            <TextInput
              id="order-date"
              type="date"
              value={values.orderDate}
              onChange={(event) => setField("orderDate", event.target.value)}
              required
            />
          </Field>

          <Field label="Статус" htmlFor="order-status" error={fieldErrors.status}>
            <Select
              id="order-status"
              value={values.status}
              onChange={(event) =>
                setField("status", event.target.value as OrderFormValues["status"])
              }
            >
              {Object.entries(ORDER_STATUS_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </Field>
        </div>
      </section>

      <section className={styles.section} aria-labelledby="order-items-heading">
        <div className={styles.sectionHeader}>
          <h2 id="order-items-heading" className={styles.sectionTitle}>
            Позиции работ
          </h2>
          <Button type="button" variant="secondary" size="sm" onClick={addItem}>
            Добавить позицию
          </Button>
        </div>
        {fieldErrors.items ? (
          <p className={styles.inlineError} role="alert">
            {fieldErrors.items}
          </p>
        ) : null}

        <ul className={styles.items}>
          {values.items.map((item, index) => {
            const lineTotal = totals?.lineTotals[index];
            return (
              <li key={item.key} className={styles.item}>
                <div className={styles.itemHeader}>
                  <span className={styles.itemIndex}>Позиция {index + 1}</span>
                  <div className={styles.itemActions}>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => moveItem(index, -1)}
                      disabled={index === 0}
                      aria-label="Переместить выше"
                    >
                      ↑
                    </Button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => moveItem(index, 1)}
                      disabled={index === values.items.length - 1}
                      aria-label="Переместить ниже"
                    >
                      ↓
                    </Button>
                    <Button
                      type="button"
                      variant="danger"
                      size="sm"
                      onClick={() => removeItem(index)}
                      disabled={values.items.length === 1}
                    >
                      Удалить
                    </Button>
                  </div>
                </div>

                <div className={styles.itemGrid}>
                  <Field
                    label="Название"
                    htmlFor={`item-name-${index}`}
                    required
                    error={fieldErrors[`items.${index}.name`]}
                  >
                    <TextInput
                      id={`item-name-${index}`}
                      value={item.name}
                      onChange={(event) =>
                        setItemField(index, "name", event.target.value)
                      }
                    />
                  </Field>
                  <Field
                    label="Количество"
                    htmlFor={`item-qty-${index}`}
                    required
                    error={fieldErrors[`items.${index}.quantity`]}
                  >
                    <TextInput
                      id={`item-qty-${index}`}
                      inputMode="decimal"
                      value={item.quantity}
                      onChange={(event) =>
                        setItemField(index, "quantity", event.target.value)
                      }
                    />
                  </Field>
                  <Field
                    label="Единица"
                    htmlFor={`item-unit-${index}`}
                    required
                    error={fieldErrors[`items.${index}.unit`]}
                  >
                    <Select
                      id={`item-unit-${index}`}
                      value={
                        DEFAULT_UNITS.includes(
                          item.unit as (typeof DEFAULT_UNITS)[number],
                        )
                          ? item.unit
                          : "__custom__"
                      }
                      onChange={(event) => {
                        const next = event.target.value;
                        setItemField(
                          index,
                          "unit",
                          next === "__custom__" ? item.unit || "другое" : next,
                        );
                      }}
                    >
                      {DEFAULT_UNITS.map((unit) => (
                        <option key={unit} value={unit}>
                          {unit}
                        </option>
                      ))}
                      <option value="__custom__">Другое…</option>
                    </Select>
                  </Field>
                  {!DEFAULT_UNITS.includes(item.unit as (typeof DEFAULT_UNITS)[number]) ? (
                    <Field label="Своя единица" htmlFor={`item-unit-custom-${index}`}>
                      <TextInput
                        id={`item-unit-custom-${index}`}
                        value={item.unit}
                        onChange={(event) =>
                          setItemField(index, "unit", event.target.value)
                        }
                      />
                    </Field>
                  ) : null}
                  <Field
                    label="Цена за единицу, ₽"
                    htmlFor={`item-price-${index}`}
                    required
                    error={fieldErrors[`items.${index}.unitPrice`]}
                  >
                    <TextInput
                      id={`item-price-${index}`}
                      inputMode="decimal"
                      value={item.unitPrice}
                      onChange={(event) =>
                        setItemField(index, "unitPrice", event.target.value)
                      }
                      placeholder="0"
                    />
                  </Field>
                  <div className={styles.lineTotal}>
                    <span className={styles.lineTotalLabel}>Сумма позиции</span>
                    <strong>
                      {lineTotal !== undefined
                        ? formatRubFromMinor(lineTotal)
                        : "—"}
                    </strong>
                  </div>
                  <Field
                    label="Описание позиции"
                    htmlFor={`item-desc-${index}`}
                    error={fieldErrors[`items.${index}.description`]}
                  >
                    <TextInput
                      id={`item-desc-${index}`}
                      value={item.description}
                      onChange={(event) =>
                        setItemField(index, "description", event.target.value)
                      }
                    />
                  </Field>
                </div>
              </li>
            );
          })}
        </ul>
      </section>

      <section className={styles.section} aria-labelledby="order-totals-heading">
        <h2 id="order-totals-heading" className={styles.sectionTitle}>
          Скидка и итог
        </h2>
        <div className={styles.grid}>
          <Field
            label="Скидка, ₽"
            htmlFor="order-discount"
            error={fieldErrors.discount}
          >
            <TextInput
              id="order-discount"
              inputMode="decimal"
              value={values.discount}
              onChange={(event) => setField("discount", event.target.value)}
            />
          </Field>
          <div className={styles.totalsBox}>
            <div>
              <span>Сумма позиций</span>
              <strong>
                {totals ? formatRubFromMinor(totals.subtotal) : "—"}
              </strong>
            </div>
            <div>
              <span>Итого</span>
              <strong className={styles.grandTotal}>
                {totals ? formatRubFromMinor(totals.totalMinor) : "—"}
              </strong>
            </div>
          </div>
        </div>
      </section>

      <section className={styles.section} aria-labelledby="order-dates-heading">
        <h2 id="order-dates-heading" className={styles.sectionTitle}>
          Сроки
        </h2>
        <div className={styles.grid}>
          <Field label="Срок выполнения" htmlFor="order-due" error={fieldErrors.dueDate}>
            <TextInput
              id="order-due"
              type="date"
              value={values.dueDate}
              onChange={(event) => setField("dueDate", event.target.value)}
            />
          </Field>
        </div>
      </section>

      <section className={styles.section} aria-labelledby="order-notes-heading">
        <h2 id="order-notes-heading" className={styles.sectionTitle}>
          Описание и заметки
        </h2>
        <div className={styles.stack}>
          <Field label="Описание" htmlFor="order-description" error={fieldErrors.description}>
            <TextArea
              id="order-description"
              value={values.description}
              onChange={(event) => setField("description", event.target.value)}
              rows={3}
            />
          </Field>
          <Field label="Заметки" htmlFor="order-notes" error={fieldErrors.notes}>
            <TextArea
              id="order-notes"
              value={values.notes}
              onChange={(event) => setField("notes", event.target.value)}
              rows={3}
            />
          </Field>
        </div>
      </section>

      <div className={styles.actions}>
        <Button type="submit" disabled={submitting || Boolean(paidConflict)}>
          {submitting
            ? "Сохранение…"
            : mode === "create"
              ? "Создать заказ"
              : "Сохранить"}
        </Button>
        <LinkButton href={cancelHref} variant="secondary">
          Отмена
        </LinkButton>
      </div>
    </form>
  );
}
