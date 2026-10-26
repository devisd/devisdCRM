"use client";

import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { LinkButton } from "@/components/ui/LinkButton";
import { LoadingState } from "@/components/ui/LoadingState";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { useDbReady } from "@/lib/hooks/useDbReady";
import { useAsyncResource } from "@/lib/hooks/useAsyncResource";
import { formatRubFromMinor } from "@/lib/format/currency";
import { formatDateLocal } from "@/lib/format/dates";
import { ORDER_STATUS_LABELS } from "@/lib/services/labels";
import { PAYMENT_STATUS_LABELS } from "@/lib/services/paymentStatus";
import { loadOverviewStats } from "@/lib/services/overview";
import {
  orderStatusTone,
  paymentStatusTone,
} from "@/lib/services/orderStatusTone";
import { routes } from "@/lib/routes";
import styles from "./OverviewDashboard.module.scss";

export function OverviewDashboard() {
  const db = useDbReady();
  const resource = useAsyncResource(loadOverviewStats, [], {
    enabled: db.status === "ready",
  });

  if (db.status === "loading" || (db.status === "ready" && resource.status === "loading")) {
    return <LoadingState label="Загрузка обзора…" />;
  }

  if (db.status === "unavailable") {
    return <Alert tone="error" title="База недоступна">{db.message}</Alert>;
  }

  if (resource.status === "error") {
    return (
      <Alert tone="error" title="Не удалось загрузить обзор">
        <p>{resource.message}</p>
        <Button type="button" variant="secondary" onClick={resource.reload}>
          Повторить
        </Button>
      </Alert>
    );
  }

  if (resource.status !== "ready") {
    return <LoadingState label="Загрузка обзора…" />;
  }

  const stats = resource.data;

  return (
    <div>
      <PageHeader
        title="Обзор"
        description="Сводка по активным заказам и фактически полученным оплатам."
        actions={
          <>
            <LinkButton href={routes.orderNew()}>Новый заказ</LinkButton>
            <LinkButton href={routes.clientNew} variant="secondary">
              Добавить клиента
            </LinkButton>
          </>
        }
      />

      {stats.isEmpty ? (
        <EmptyState
          title="Начните работу"
          description="Создайте клиента и первый заказ — здесь появятся показатели, последние заказы и ближайшие сроки. Пока данных нет, статистика не показывается."
          action={<LinkButton href={routes.clientNew}>Добавить клиента</LinkButton>}
        />
      ) : (
        <>
          <section className={styles.metrics} aria-label="Показатели">
            <article className={styles.metricCard}>
              <p className={styles.metricLabel}>В работе</p>
              <p className={styles.metricValue}>{stats.inProgressCount}</p>
            </article>
            <article className={styles.metricCard}>
              <p className={styles.metricLabel}>Новые</p>
              <p className={styles.metricValue}>{stats.newCount}</p>
            </article>
            <article className={styles.metricCard}>
              <p className={styles.metricLabel}>Оплаты за месяц</p>
              <p className={styles.metricValue}>
                {formatRubFromMinor(stats.paidThisMonthMinor)}
              </p>
            </article>
            <article className={styles.metricCard}>
              <p className={styles.metricLabel}>Ожидается оплата</p>
              <p className={styles.metricValue}>
                {formatRubFromMinor(stats.outstandingMinor)}
              </p>
            </article>
            <article className={styles.metricCard}>
              <p className={styles.metricLabel}>Завершено за месяц</p>
              <p className={styles.metricValue}>{stats.completedThisMonthCount}</p>
            </article>
          </section>

          <div className={styles.columns}>
            <section className={styles.panel} aria-labelledby="recent-orders-heading">
              <h2 id="recent-orders-heading" className={styles.panelTitle}>
                Последние заказы
              </h2>
              {stats.recentOrders.length === 0 ? (
                <p className={styles.muted}>Активных заказов пока нет.</p>
              ) : (
                <ul className={styles.list}>
                  {stats.recentOrders.map((order) => (
                    <li key={order.id} className={styles.item}>
                      <div className={styles.itemTop}>
                        <Link href={routes.orderDetail(order.id)} className={styles.itemTitle}>
                          №{order.number} · {order.title}
                        </Link>
                        <Badge tone={orderStatusTone(order.status)}>
                          {ORDER_STATUS_LABELS[order.status]}
                        </Badge>
                      </div>
                      <p className={styles.itemMeta}>
                        {order.clientName} · {formatDateLocal(order.orderDate)} ·{" "}
                        {formatRubFromMinor(order.totalMinor)} ·{" "}
                        {PAYMENT_STATUS_LABELS[order.paymentStatus]}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section className={styles.panel} aria-labelledby="due-soon-heading">
              <h2 id="due-soon-heading" className={styles.panelTitle}>
                Ближайшие сроки
              </h2>
              {stats.dueSoonOrders.length === 0 ? (
                <p className={styles.muted}>Сроков с датой пока нет.</p>
              ) : (
                <ul className={styles.list}>
                  {stats.dueSoonOrders.map((order) => (
                    <li key={order.id} className={styles.item}>
                      <div className={styles.itemTop}>
                        <Link href={routes.orderDetail(order.id)} className={styles.itemTitle}>
                          №{order.number} · {order.title}
                        </Link>
                        <Badge tone={paymentStatusTone(order.paymentStatus)}>
                          остаток {formatRubFromMinor(order.remainingMinor)}
                        </Badge>
                      </div>
                      <p className={styles.itemMeta}>
                        Срок {order.dueDate ? formatDateLocal(order.dueDate) : "—"} ·{" "}
                        {order.clientName}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </div>
        </>
      )}
    </div>
  );
}
