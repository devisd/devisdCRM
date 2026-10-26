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
import { ConfirmDialog } from "@/components/ui/ConfirmDialog";
import { useDbReady } from "@/lib/hooks/useDbReady";
import { useAsyncResource } from "@/lib/hooks/useAsyncResource";
import { toUserDbMessage } from "@/lib/db/errors";
import {
  archiveClient,
  getClientById,
  unarchiveClient,
} from "@/lib/repositories/clients";
import { getOrdersByClientId } from "@/lib/repositories/orders";
import { formatRubFromMinor } from "@/lib/format/currency";
import { formatDateLocal } from "@/lib/format/dates";
import { ORDER_STATUS_LABELS } from "@/lib/services/labels";
import { calcPaymentStatus, PAYMENT_STATUS_LABELS } from "@/lib/services/paymentStatus";
import { calcRemainingMinor, isOrderCountableForRevenue } from "@/lib/services/money";
import { routes } from "@/lib/routes";
import type { Client } from "@/types";
import styles from "./ClientDetail.module.scss";

type ClientDetailProps = {
  clientId: string;
};

function contactRows(client: Client) {
  return [
    { label: "Контактное лицо", value: client.contactName },
    { label: "Телефон", value: client.phone },
    { label: "Email", value: client.email },
    { label: "Telegram", value: client.telegram },
    { label: "MAX", value: client.maxContact },
    { label: "Другой контакт", value: client.otherContact },
    { label: "Источник", value: client.source },
  ].filter((row) => Boolean(row.value));
}

export function ClientDetail({ clientId }: ClientDetailProps) {
  const db = useDbReady();
  const resource = useAsyncResource(
    async () => {
      const [client, orders] = await Promise.all([
        getClientById(clientId),
        getOrdersByClientId(clientId),
      ]);
      return { client, orders };
    },
    [clientId],
    { enabled: db.status === "ready" },
  );
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [archivedOverride, setArchivedOverride] = useState<Client | null>(null);

  const onToggleArchive = async () => {
    const current =
      archivedOverride ??
      (resource.status === "ready" ? resource.data.client : null);
    if (!current) return;

    setBusy(true);
    setActionError(null);
    try {
      const updated = current.isArchived
        ? await unarchiveClient(current.id)
        : await archiveClient(current.id);
      setArchivedOverride(updated);
      setArchiveOpen(false);
      resource.reload();
    } catch (err) {
      setActionError(toUserDbMessage(err));
    } finally {
      setBusy(false);
    }
  };

  if (db.status === "loading" || (db.status === "ready" && resource.status === "loading")) {
    return <LoadingState label="Загрузка клиента…" />;
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

  if (resource.status !== "ready") {
    return <LoadingState label="Загрузка клиента…" />;
  }

  const loadedClient = resource.data.client;
  if (!loadedClient) {
    return (
      <EmptyState
        title="Клиент не найден"
        description="Возможно, запись была удалена или идентификатор указан неверно."
        action={<LinkButton href={routes.clients}>К списку клиентов</LinkButton>}
      />
    );
  }

  const client = archivedOverride ?? loadedClient;
  const orders = resource.data.orders;
  const contacts = contactRows(client);
  const countableOrders = orders.filter((order) =>
    isOrderCountableForRevenue(order.status),
  );
  const ordersTotalMinor = countableOrders.reduce(
    (sum, order) => sum + order.totalMinor,
    0,
  );
  const paidMinor = orders.reduce((sum, order) => sum + order.paidMinor, 0);
  const outstandingMinor = countableOrders.reduce(
    (sum, order) => sum + calcRemainingMinor(order.totalMinor, order.paidMinor),
    0,
  );

  return (
    <div>
      <PageHeader
        title={client.name}
        description="Карточка клиента и история заказов."
        actions={
          <>
            <LinkButton href={routes.clientEdit(client.id)} variant="secondary">
              Редактировать
            </LinkButton>
            <LinkButton href={routes.orderNew(client.id)}>
              Создать заказ
            </LinkButton>
            <Button
              type="button"
              variant="ghost"
              onClick={() => setArchiveOpen(true)}
            >
              {client.isArchived ? "Вернуть из архива" : "В архив"}
            </Button>
            <LinkButton href={routes.clients} variant="ghost">
              К списку
            </LinkButton>
          </>
        }
      />

      {client.isArchived ? (
        <div className={styles.banner}>
          <Badge tone="cancelled">В архиве</Badge>
          <span>Клиент скрыт из активного списка, история сохранена.</span>
        </div>
      ) : null}

      {actionError ? (
        <Alert tone="error" title="Не удалось изменить статус">
          {actionError}
        </Alert>
      ) : null}

      <section className={styles.panel} aria-labelledby="client-contacts-heading">
        <h2 id="client-contacts-heading" className={styles.panelTitle}>
          Контакты и заметки
        </h2>
        {contacts.length === 0 && !client.notes ? (
          <p className={styles.muted}>Контакты и заметки не указаны.</p>
        ) : (
          <dl className={styles.details}>
            {contacts.map((row) => (
              <div key={row.label} className={styles.detailRow}>
                <dt>{row.label}</dt>
                <dd>{row.value}</dd>
              </div>
            ))}
            {client.notes ? (
              <div className={styles.detailRow}>
                <dt>Заметки</dt>
                <dd className={styles.notes}>{client.notes}</dd>
              </div>
            ) : null}
          </dl>
        )}
      </section>

      <section className={styles.stats} aria-label="Сводка по клиенту">
        <article className={styles.statCard}>
          <p className={styles.statLabel}>Заказов</p>
          <p className={styles.statValue}>{orders.length}</p>
        </article>
        <article className={styles.statCard}>
          <p className={styles.statLabel}>Сумма заказов</p>
          <p className={styles.statValue}>{formatRubFromMinor(ordersTotalMinor)}</p>
          <p className={styles.statHint}>Без отменённых</p>
        </article>
        <article className={styles.statCard}>
          <p className={styles.statLabel}>Получено оплат</p>
          <p className={styles.statValue}>{formatRubFromMinor(paidMinor)}</p>
        </article>
        <article className={styles.statCard}>
          <p className={styles.statLabel}>Остаток</p>
          <p className={styles.statValue}>{formatRubFromMinor(outstandingMinor)}</p>
          <p className={styles.statHint}>По неоплаченным, без отменённых</p>
        </article>
      </section>

      <section className={styles.panel} aria-labelledby="client-orders-heading">
        <div className={styles.panelHeader}>
          <h2 id="client-orders-heading" className={styles.panelTitle}>
            История заказов
          </h2>
          <LinkButton href={routes.orderNew(client.id)} size="sm">
            Новый заказ
          </LinkButton>
        </div>

        {orders.length === 0 ? (
          <EmptyState
            title="Заказов пока нет"
            description="Создайте первый заказ для этого клиента."
            action={
              <LinkButton href={routes.orderNew(client.id)}>
                Создать заказ
              </LinkButton>
            }
          />
        ) : (
          <ul className={styles.orderList}>
            {orders.map((order) => {
              const paymentStatus = calcPaymentStatus(
                order.totalMinor,
                order.paidMinor,
              );
              const remaining = calcRemainingMinor(
                order.totalMinor,
                order.paidMinor,
              );

              return (
                <li key={order.id} className={styles.orderItem}>
                  <div className={styles.orderTop}>
                    <Link href={routes.orderDetail(order.id)} className={styles.orderTitle}>
                      №{order.number} · {order.title}
                    </Link>
                    <div className={styles.orderBadges}>
                      <Badge
                        tone={
                          order.status === "completed"
                            ? "completed"
                            : order.status === "cancelled"
                              ? "cancelled"
                              : order.status === "in_progress"
                                ? "inProgress"
                                : order.status === "waiting"
                                  ? "waiting"
                                  : "new"
                        }
                      >
                        {ORDER_STATUS_LABELS[order.status]}
                      </Badge>
                      <Badge
                        tone={
                          paymentStatus === "paid"
                            ? "paid"
                            : paymentStatus === "partially_paid"
                              ? "partial"
                              : paymentStatus === "not_required"
                                ? "neutral"
                                : "unpaid"
                        }
                      >
                        {PAYMENT_STATUS_LABELS[paymentStatus]}
                      </Badge>
                    </div>
                  </div>
                  <p className={styles.orderMeta}>
                    {formatDateLocal(order.orderDate)} · итог{" "}
                    {formatRubFromMinor(order.totalMinor)} · оплачено{" "}
                    {formatRubFromMinor(order.paidMinor)} · остаток{" "}
                    {formatRubFromMinor(remaining)}
                  </p>
                </li>
              );
            })}
          </ul>
        )}
      </section>

      <ConfirmDialog
        open={archiveOpen}
        title={client.isArchived ? "Вернуть из архива?" : "Отправить в архив?"}
        description={
          client.isArchived
            ? "Клиент снова появится в активном списке."
            : "Клиент скрывается из активного списка. История заказов сохранится; физическое удаление не выполняется."
        }
        confirmLabel={client.isArchived ? "Вернуть" : "В архив"}
        danger={!client.isArchived}
        busy={busy}
        onCancel={() => setArchiveOpen(false)}
        onConfirm={() => void onToggleArchive()}
      />
    </div>
  );
}
