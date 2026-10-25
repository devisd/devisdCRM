"use client";

import { useState } from "react";
import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { LinkButton } from "@/components/ui/LinkButton";
import { LoadingState } from "@/components/ui/LoadingState";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Select } from "@/components/ui/Select";
import { Field } from "@/components/ui/Field";
import { ConfirmDialog } from "@/components/ui/ConfirmDialog";
import { useDbReady } from "@/lib/hooks/useDbReady";
import { useAsyncResource } from "@/lib/hooks/useAsyncResource";
import { toUserDbMessage } from "@/lib/db/errors";
import { getClientById } from "@/lib/repositories/clients";
import {
  archiveOrder,
  getOrderById,
  getOrderItems,
  getOrderPayments,
  unarchiveOrder,
  updateOrderStatus,
} from "@/lib/repositories/orders";
import { formatRubFromMinor } from "@/lib/format/currency";
import { formatDateLocal } from "@/lib/format/dates";
import {
  ORDER_STATUS_LABELS,
  PAYMENT_METHOD_LABELS,
} from "@/lib/services/labels";
import {
  calcPaymentStatus,
  PAYMENT_STATUS_LABELS,
} from "@/lib/services/paymentStatus";
import { calcRemainingMinor } from "@/lib/services/money";
import {
  orderStatusTone,
  paymentStatusTone,
} from "@/lib/services/orderStatusTone";
import { AddPaymentDialog } from "@/components/orders/AddPaymentDialog";
import { routes } from "@/lib/routes";
import type { Order } from "@/types";
import styles from "./OrderDetail.module.scss";

type OrderDetailProps = {
  orderId: string;
};

export function OrderDetail({ orderId }: OrderDetailProps) {
  const db = useDbReady();
  const resource = useAsyncResource(
    async () => {
      const order = await getOrderById(orderId);
      if (!order) {
        return null;
      }
      const [items, payments, client] = await Promise.all([
        getOrderItems(orderId),
        getOrderPayments(orderId),
        getClientById(order.clientId),
      ]);
      return { order, items, payments, client };
    },
    [orderId],
    { enabled: db.status === "ready" },
  );

  const [archiveOpen, setArchiveOpen] = useState(false);
  const [paymentOpen, setPaymentOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [statusBusy, setStatusBusy] = useState(false);

  const currentOrder =
    resource.status === "ready" ? resource.data?.order : undefined;

  const onToggleArchive = async () => {
    if (!currentOrder) return;
    setBusy(true);
    setActionError(null);
    try {
      if (currentOrder.isArchived) {
        await unarchiveOrder(currentOrder.id);
      } else {
        await archiveOrder(currentOrder.id);
      }
      setArchiveOpen(false);
      resource.reload();
    } catch (error) {
      setActionError(toUserDbMessage(error));
    } finally {
      setBusy(false);
    }
  };

  const onStatusChange = async (status: Order["status"]) => {
    if (!currentOrder || status === currentOrder.status) return;
    setStatusBusy(true);
    setActionError(null);
    try {
      await updateOrderStatus(currentOrder.id, status);
      resource.reload();
    } catch (error) {
      setActionError(toUserDbMessage(error));
    } finally {
      setStatusBusy(false);
    }
  };

  if (db.status === "loading" || (db.status === "ready" && resource.status === "loading")) {
    return <LoadingState label="Загрузка заказа…" />;
  }

  if (db.status === "unavailable") {
    return <Alert tone="error" title="База недоступна">{db.message}</Alert>;
  }

  if (resource.status === "error") {
    return (
      <Alert tone="error" title="Ошибка загрузки">
        <p>{resource.message}</p>
        <Button type="button" variant="secondary" onClick={resource.reload}>
          Повторить
        </Button>
      </Alert>
    );
  }

  if (resource.status !== "ready" || !resource.data) {
    return (
      <EmptyState
        title="Заказ не найден"
        description="Возможно, запись была удалена или идентификатор указан неверно."
        action={<LinkButton href={routes.orders}>К списку заказов</LinkButton>}
      />
    );
  }

  const { order, items, payments, client } = resource.data;
  const paymentStatus = calcPaymentStatus(order.totalMinor, order.paidMinor);
  const remainingMinor = calcRemainingMinor(order.totalMinor, order.paidMinor);
  const itemsSubtotal = items.reduce((sum, item) => sum + item.lineTotalMinor, 0);

  return (
    <div>
      <PageHeader
        title={`Заказ №${order.number}`}
        description={order.title}
        actions={
          <>
            <Button
              type="button"
              onClick={() => setPaymentOpen(true)}
              disabled={remainingMinor <= 0}
            >
              Добавить оплату
            </Button>
            <LinkButton href={routes.orderEdit(order.id)} variant="secondary">
              Редактировать
            </LinkButton>
            <Button type="button" variant="ghost" onClick={() => setArchiveOpen(true)}>
              {order.isArchived ? "Вернуть из архива" : "В архив"}
            </Button>
            <LinkButton href={routes.orders} variant="ghost">
              К списку
            </LinkButton>
          </>
        }
      />

      {order.isArchived ? (
        <div className={styles.banner}>
          <Badge tone="cancelled">В архиве</Badge>
          <span>Заказ скрыт из активного списка.</span>
        </div>
      ) : null}

      {actionError ? (
        <Alert tone="error" title="Не удалось выполнить действие">
          {actionError}
        </Alert>
      ) : null}

      <section className={styles.panel}>
        <div className={styles.metaGrid}>
          <div>
            <p className={styles.label}>Клиент</p>
            <p className={styles.value}>
              {client ? (
                <Link href={routes.clientDetail(client.id)}>{client.name}</Link>
              ) : (
                "Клиент не найден"
              )}
            </p>
          </div>
          <div>
            <p className={styles.label}>Дата</p>
            <p className={styles.value}>{formatDateLocal(order.orderDate)}</p>
          </div>
          <div>
            <p className={styles.label}>Срок</p>
            <p className={styles.value}>
              {order.dueDate ? formatDateLocal(order.dueDate) : "—"}
            </p>
          </div>
          <div>
            <p className={styles.label}>Статус оплаты</p>
            <Badge tone={paymentStatusTone(paymentStatus)}>
              {PAYMENT_STATUS_LABELS[paymentStatus]}
            </Badge>
          </div>
        </div>

        <Field label="Статус заказа" htmlFor="order-status-change">
          <div className={styles.statusRow}>
            <Select
              id="order-status-change"
              value={order.status}
              disabled={statusBusy}
              onChange={(event) =>
                void onStatusChange(event.target.value as Order["status"])
              }
            >
              {Object.entries(ORDER_STATUS_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
            <Badge tone={orderStatusTone(order.status)}>
              {ORDER_STATUS_LABELS[order.status]}
            </Badge>
          </div>
        </Field>
      </section>

      <section className={styles.panel} aria-labelledby="order-items-heading">
        <h2 id="order-items-heading" className={styles.panelTitle}>
          Позиции
        </h2>
        <div className={styles.tableWrap}>
          <table className={styles.table}>
            <thead>
              <tr>
                <th>Название</th>
                <th>Кол-во</th>
                <th>Цена</th>
                <th>Сумма</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td>
                    <strong>{item.name}</strong>
                    {item.description ? (
                      <p className={styles.itemDesc}>{item.description}</p>
                    ) : null}
                  </td>
                  <td>
                    {item.quantity} {item.unit}
                  </td>
                  <td>{formatRubFromMinor(item.unitPriceMinor)}</td>
                  <td>{formatRubFromMinor(item.lineTotalMinor)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className={styles.totals} aria-label="Суммы">
        <div>
          <span>Сумма позиций</span>
          <strong>{formatRubFromMinor(itemsSubtotal)}</strong>
        </div>
        <div>
          <span>Скидка</span>
          <strong>{formatRubFromMinor(order.discountMinor)}</strong>
        </div>
        <div>
          <span>Итого</span>
          <strong>{formatRubFromMinor(order.totalMinor)}</strong>
        </div>
        <div>
          <span>Оплачено</span>
          <strong>{formatRubFromMinor(order.paidMinor)}</strong>
        </div>
        <div>
          <span>Остаток</span>
          <strong>{formatRubFromMinor(remainingMinor)}</strong>
        </div>
      </section>

      <section className={styles.panel} aria-labelledby="order-payments-heading">
        <div className={styles.panelHeader}>
          <h2 id="order-payments-heading" className={styles.panelTitle}>
            История платежей
          </h2>
          <Button
            type="button"
            size="sm"
            onClick={() => setPaymentOpen(true)}
            disabled={remainingMinor <= 0}
          >
            Добавить оплату
          </Button>
        </div>
        {remainingMinor <= 0 && order.totalMinor > 0 ? (
          <p className={styles.muted}>Заказ полностью оплачен.</p>
        ) : null}
        {order.totalMinor === 0 ? (
          <p className={styles.muted}>Оплата не требуется: итог заказа равен нулю.</p>
        ) : null}
        {payments.length === 0 ? (
          <p className={styles.muted}>Платежей пока нет.</p>
        ) : (
          <ul className={styles.paymentList}>
            {payments.map((payment) => (
              <li key={payment.id} className={styles.paymentItem}>
                <div>
                  <strong>{formatRubFromMinor(payment.amountMinor)}</strong>
                  <span className={styles.muted}>
                    {" "}
                    · {formatDateLocal(payment.paymentDate)} ·{" "}
                    {PAYMENT_METHOD_LABELS[payment.method]}
                  </span>
                </div>
                {payment.comment ? (
                  <p className={styles.itemDesc}>{payment.comment}</p>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </section>

      {(order.description || order.notes) && (
        <section className={styles.panel}>
          {order.description ? (
            <div className={styles.notesBlock}>
              <h2 className={styles.panelTitle}>Описание</h2>
              <p className={styles.notes}>{order.description}</p>
            </div>
          ) : null}
          {order.notes ? (
            <div className={styles.notesBlock}>
              <h2 className={styles.panelTitle}>Заметки</h2>
              <p className={styles.notes}>{order.notes}</p>
            </div>
          ) : null}
        </section>
      )}

      <ConfirmDialog
        open={archiveOpen}
        title={order.isArchived ? "Вернуть из архива?" : "Отправить в архив?"}
        description={
          order.isArchived
            ? "Заказ снова появится в активном списке."
            : "Заказ будет скрыт из активного списка. История сохранится."
        }
        confirmLabel={order.isArchived ? "Вернуть" : "В архив"}
        danger={!order.isArchived}
        busy={busy}
        onCancel={() => setArchiveOpen(false)}
        onConfirm={() => void onToggleArchive()}
      />

      <AddPaymentDialog
        open={paymentOpen}
        orderId={order.id}
        remainingMinor={remainingMinor}
        onClose={() => setPaymentOpen(false)}
        onSaved={() => resource.reload()}
      />
    </div>
  );
}
