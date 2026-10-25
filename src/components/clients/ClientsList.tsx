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
import {
  filterClientSummaries,
  listClientSummaries,
  sortClientSummaries,
  type ClientSortKey,
  type ClientSummary,
} from "@/lib/services/clientSummaries";
import styles from "./ClientsList.module.scss";

type ArchiveFilter = "active" | "archived" | "all";

function primaryContact(client: ClientSummary["client"]): string {
  return (
    [client.phone, client.email, client.telegram, client.maxContact, client.otherContact]
      .find((value) => Boolean(value)) ?? "Контакты не указаны"
  );
}

export function ClientsList() {
  const db = useDbReady();
  const resource = useAsyncResource(
    () => listClientSummaries({ includeArchived: true }),
    [],
    { enabled: db.status === "ready" },
  );
  const [query, setQuery] = useState("");
  const [archiveFilter, setArchiveFilter] = useState<ArchiveFilter>("active");
  const [sortKey, setSortKey] = useState<ClientSortKey>("name");

  const summaries = useMemo(
    () => (resource.status === "ready" ? resource.data : []),
    [resource],
  );

  const filtered = useMemo(() => {
    const byArchive = summaries.filter(({ client }) => {
      if (archiveFilter === "active") return !client.isArchived;
      if (archiveFilter === "archived") return client.isArchived;
      return true;
    });
    return sortClientSummaries(filterClientSummaries(byArchive, query), sortKey);
  }, [summaries, archiveFilter, query, sortKey]);

  if (db.status === "loading" || (db.status === "ready" && resource.status === "loading")) {
    return <LoadingState label="Загрузка клиентов…" />;
  }

  if (db.status === "unavailable") {
    return <Alert tone="error" title="База недоступна">{db.message}</Alert>;
  }

  if (resource.status === "error") {
    return (
      <Alert tone="error" title="Не удалось загрузить клиентов">
        <p>{resource.message}</p>
        <Button type="button" variant="secondary" onClick={resource.reload}>
          Повторить
        </Button>
      </Alert>
    );
  }

  const hasAny = summaries.length > 0;
  const hasFiltered = filtered.length > 0;

  return (
    <div>
      <PageHeader
        title="Клиенты"
        description="Поиск по имени и контактам, фильтр архива и сортировка."
        actions={<LinkButton href="/clients/new">Добавить клиента</LinkButton>}
      />

      {hasAny ? (
        <div className={styles.toolbar}>
          <Field label="Поиск" htmlFor="clients-search">
            <TextInput
              id="clients-search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Имя, телефон, email, Telegram…"
            />
          </Field>
          <Field label="Архив" htmlFor="clients-archive">
            <Select
              id="clients-archive"
              value={archiveFilter}
              onChange={(event) =>
                setArchiveFilter(event.target.value as ArchiveFilter)
              }
            >
              <option value="active">Только активные</option>
              <option value="archived">Только архивные</option>
              <option value="all">Все</option>
            </Select>
          </Field>
          <Field label="Сортировка" htmlFor="clients-sort">
            <Select
              id="clients-sort"
              value={sortKey}
              onChange={(event) => setSortKey(event.target.value as ClientSortKey)}
            >
              <option value="name">По имени</option>
              <option value="lastOrder">По последнему заказу</option>
              <option value="orders">По числу заказов</option>
              <option value="paid">По сумме оплат</option>
            </Select>
          </Field>
          {(query || archiveFilter !== "active" || sortKey !== "name") && (
            <div className={styles.resetWrap}>
              <Button
                type="button"
                variant="ghost"
                onClick={() => {
                  setQuery("");
                  setArchiveFilter("active");
                  setSortKey("name");
                }}
              >
                Сбросить фильтры
              </Button>
            </div>
          )}
        </div>
      ) : null}

      {!hasAny ? (
        <EmptyState
          title="Клиентов пока нет"
          description="Добавьте клиента, чтобы вести историю заказов и оплат."
          action={<LinkButton href="/clients/new">Добавить клиента</LinkButton>}
        />
      ) : !hasFiltered ? (
        <EmptyState
          title="Ничего не найдено"
          description="Измените поисковый запрос или сбросьте фильтры."
          action={
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setQuery("");
                setArchiveFilter("active");
              }}
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
                  <th>Клиент</th>
                  <th>Контакты</th>
                  <th>Заказы</th>
                  <th>Последний заказ</th>
                  <th>Оплачено</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map(({ client, orderCount, lastOrderDate, paidMinor }) => (
                  <tr key={client.id}>
                    <td>
                      <div className={styles.nameCell}>
                        <Link href={`/clients/${client.id}`} className={styles.nameLink}>
                          {client.name}
                        </Link>
                        {client.isArchived ? (
                          <Badge tone="cancelled">В архиве</Badge>
                        ) : null}
                      </div>
                    </td>
                    <td>{primaryContact(client)}</td>
                    <td>{orderCount}</td>
                    <td>{lastOrderDate ? formatDateLocal(lastOrderDate) : "—"}</td>
                    <td>{formatRubFromMinor(paidMinor)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <ul className={styles.cards}>
            {filtered.map(({ client, orderCount, lastOrderDate, paidMinor }) => (
              <li key={client.id} className={styles.card}>
                <div className={styles.cardHeader}>
                  <Link href={`/clients/${client.id}`} className={styles.nameLink}>
                    {client.name}
                  </Link>
                  {client.isArchived ? <Badge tone="cancelled">В архиве</Badge> : null}
                </div>
                <p className={styles.cardMeta}>{primaryContact(client)}</p>
                <dl className={styles.cardStats}>
                  <div>
                    <dt>Заказы</dt>
                    <dd>{orderCount}</dd>
                  </div>
                  <div>
                    <dt>Последний</dt>
                    <dd>{lastOrderDate ? formatDateLocal(lastOrderDate) : "—"}</dd>
                  </div>
                  <div>
                    <dt>Оплачено</dt>
                    <dd>{formatRubFromMinor(paidMinor)}</dd>
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
