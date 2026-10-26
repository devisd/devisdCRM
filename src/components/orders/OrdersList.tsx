"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { LinkButton } from "@/components/ui/LinkButton";
import { LoadingState } from "@/components/ui/LoadingState";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Field } from "@/components/ui/Field";
import { TextInput } from "@/components/ui/TextInput";
import { Select } from "@/components/ui/Select";
import { Button } from "@/components/ui/Button";
import { useDbReady } from "@/lib/hooks/useDbReady";
import { useAsyncResource } from "@/lib/hooks/useAsyncResource";
import { formatRubFromMinor } from "@/lib/format/currency";
import { formatDateLocal } from "@/lib/format/dates";
import { ORDER_STATUS_LABELS } from "@/lib/services/labels";
import { PAYMENT_STATUS_LABELS } from "@/lib/services/paymentStatus";
import {
  defaultOrderListFilters,
  filterAndSortOrderRows,
  listOrderRows,
  type OrderListFilters,
  type OrderSortKey,
} from "@/lib/services/orderList";
import { routes } from "@/lib/routes";
import {
  orderStatusTone,
  paymentStatusTone,
} from "@/lib/services/orderStatusTone";
import type { Order, PaymentStatus } from "@/types";
import styles from "./OrdersList.module.scss";

export function OrdersList() {
  const db = useDbReady();
  const resource = useAsyncResource(
    () => listOrderRows({ includeArchived: true }),
    [],
    { enabled: db.status === "ready" },
  );
  const [filters, setFilters] = useState<OrderListFilters>(defaultOrderListFilters);

  const rows = useMemo(
    () => (resource.status === "ready" ? resource.data : []),
    [resource],
  );

  const filtered = useMemo(
    () => filterAndSortOrderRows(rows, filters),
    [rows, filters],
  );

  const filtersDirty =
    filters.query !== "" ||
    filters.status !== "all" ||
    filters.paymentStatus !== "all" ||
    filters.archiveFilter !== "active" ||
    filters.periodFrom !== "" ||
    filters.periodTo !== "" ||
    filters.sortKey !== "date";

  if (db.status === "loading" || (db.status === "ready" && resource.status === "loading")) {
    return <LoadingState label="Загрузка заказов…" />;
  }

  if (db.status === "unavailable") {
    return <Alert tone="error" title="База недоступна">{db.message}</Alert>;
  }

  if (resource.status === "error") {
    return (
      <Alert tone="error" title="Не удалось загрузить заказы">
        <p>{resource.message}</p>
        <Button type="button" variant="secondary" onClick={resource.reload}>
          Повторить
        </Button>
      </Alert>
    );
  }

  const hasAny = rows.length > 0;
  const hasFiltered = filtered.length > 0;

  return (
    <div>
      <PageHeader
        title="Заказы"
        description="Поиск, фильтры по статусу и оплате, сортировка."
        actions={<LinkButton href={routes.orderNew()}>Новый заказ</LinkButton>}
      />

      {hasAny ? (
        <div className={styles.toolbar}>
          <Field label="Поиск" htmlFor="orders-search">
            <TextInput
              id="orders-search"
              value={filters.query}
              onChange={(event) =>
                setFilters((current) => ({ ...current, query: event.target.value }))
              }
              placeholder="Номер, название, клиент…"
            />
          </Field>
          <Field label="Статус заказа" htmlFor="orders-status">
            <Select
              id="orders-status"
              value={filters.status}
              onChange={(event) =>
                setFilters((current) => ({
                  ...current,
                  status: event.target.value as Order["status"] | "all",
                }))
              }
            >
              <option value="all">Все статусы</option>
              {Object.entries(ORDER_STATUS_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Статус оплаты" htmlFor="orders-payment">
            <Select
              id="orders-payment"
              value={filters.paymentStatus}
              onChange={(event) =>
                setFilters((current) => ({
                  ...current,
                  paymentStatus: event.target.value as PaymentStatus | "all",
                }))
              }
            >
              <option value="all">Все оплаты</option>
              {Object.entries(PAYMENT_STATUS_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Архив" htmlFor="orders-archive">
            <Select
              id="orders-archive"
              value={filters.archiveFilter}
              onChange={(event) =>
                setFilters((current) => ({
                  ...current,
                  archiveFilter: event.target.value as OrderListFilters["archiveFilter"],
                }))
              }
            >
              <option value="active">Только активные</option>
              <option value="archived">Только архивные</option>
              <option value="all">Все</option>
            </Select>
          </Field>
          <Field label="Период с" htmlFor="orders-from">
            <TextInput
              id="orders-from"
              type="date"
              value={filters.periodFrom}
              onChange={(event) =>
                setFilters((current) => ({
                  ...current,
                  periodFrom: event.target.value,
                }))
              }
            />
          </Field>
          <Field label="Период по" htmlFor="orders-to">
            <TextInput
              id="orders-to"
              type="date"
              value={filters.periodTo}
              onChange={(event) =>
                setFilters((current) => ({
                  ...current,
                  periodTo: event.target.value,
                }))
              }
            />
          </Field>
          <Field label="Сортировка" htmlFor="orders-sort">
            <Select
              id="orders-sort"
              value={filters.sortKey}
              onChange={(event) =>
                setFilters((current) => ({
                  ...current,
                  sortKey: event.target.value as OrderSortKey,
                }))
              }
            >
              <option value="date">По дате</option>
              <option value="number">По номеру</option>
              <option value="total">По сумме</option>
              <option value="dueDate">По сроку</option>
            </Select>
          </Field>
          {filtersDirty ? (
            <div className={styles.resetWrap}>
              <Button
                type="button"
                variant="ghost"
                onClick={() => setFilters(defaultOrderListFilters)}
              >
                Сбросить фильтры
              </Button>
            </div>
          ) : null}
        </div>
      ) : null}

      {!hasAny ? (
        <EmptyState
          title="Заказов пока нет"
          description="Создайте заказ с позициями работ, сроками и статусом."
          action={<LinkButton href={routes.orderNew()}>Создать заказ</LinkButton>}
        />
      ) : !hasFiltered ? (
        <EmptyState
          title="Ничего не найдено"
          description="Измените поиск или сбросьте фильтры."
          action={
            <Button
              type="button"
              variant="secondary"
              onClick={() => setFilters(defaultOrderListFilters)}
            >
              Сбросить фильтры
            </Button>
          }
        />
      ) : (
        <>
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <thead>
                <tr>
                  <th>№</th>
                  <th>Название</th>
                  <th>Клиент</th>
                  <th>Дата</th>
                  <th>Срок</th>
                  <th>Статус</th>
                  <th>Итог</th>
                  <th>Оплачено</th>
                  <th>Остаток</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map(({ order, clientName, paymentStatus, remainingMinor }) => (
                  <tr key={order.id}>
                    <td>{order.number}</td>
                    <td>
                      <Link href={routes.orderDetail(order.id)} className={styles.nameLink}>
                        {order.title}
                      </Link>
                      {order.isArchived ? (
                        <Badge tone="cancelled">Архив</Badge>
                      ) : null}
                    </td>
                    <td>
                      <Link href={routes.clientDetail(order.clientId)}>{clientName}</Link>
                    </td>
                    <td>{formatDateLocal(order.orderDate)}</td>
                    <td>{order.dueDate ? formatDateLocal(order.dueDate) : "—"}</td>
                    <td>
                      <div className={styles.badges}>
                        <Badge tone={orderStatusTone(order.status)}>
                          {ORDER_STATUS_LABELS[order.status]}
                        </Badge>
                        <Badge tone={paymentStatusTone(paymentStatus)}>
                          {PAYMENT_STATUS_LABELS[paymentStatus]}
                        </Badge>
                      </div>
                    </td>
                    <td>{formatRubFromMinor(order.totalMinor)}</td>
                    <td>{formatRubFromMinor(order.paidMinor)}</td>
                    <td>{formatRubFromMinor(remainingMinor)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <ul className={styles.cards}>
            {filtered.map(({ order, clientName, paymentStatus, remainingMinor }) => (
              <li key={order.id} className={styles.card}>
                <div className={styles.cardHeader}>
                  <Link href={routes.orderDetail(order.id)} className={styles.nameLink}>
                    №{order.number} · {order.title}
                  </Link>
                  {order.isArchived ? <Badge tone="cancelled">Архив</Badge> : null}
                </div>
                <p className={styles.cardMeta}>
                  <Link href={routes.clientDetail(order.clientId)}>{clientName}</Link>
                  {" · "}
                  {formatDateLocal(order.orderDate)}
                  {order.dueDate ? ` · срок ${formatDateLocal(order.dueDate)}` : ""}
                </p>
                <div className={styles.badges}>
                  <Badge tone={orderStatusTone(order.status)}>
                    {ORDER_STATUS_LABELS[order.status]}
                  </Badge>
                  <Badge tone={paymentStatusTone(paymentStatus)}>
                    {PAYMENT_STATUS_LABELS[paymentStatus]}
                  </Badge>
                </div>
                <dl className={styles.cardStats}>
                  <div>
                    <dt>Итог</dt>
                    <dd>{formatRubFromMinor(order.totalMinor)}</dd>
                  </div>
                  <div>
                    <dt>Оплачено</dt>
                    <dd>{formatRubFromMinor(order.paidMinor)}</dd>
                  </div>
                  <div>
                    <dt>Остаток</dt>
                    <dd>{formatRubFromMinor(remainingMinor)}</dd>
                  </div>
                </dl>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}
