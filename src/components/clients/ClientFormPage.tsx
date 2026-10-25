"use client";

import { PageHeader } from "@/components/ui/PageHeader";
import { LinkButton } from "@/components/ui/LinkButton";
import { LoadingState } from "@/components/ui/LoadingState";
import { Alert } from "@/components/ui/Alert";
import { EmptyState } from "@/components/ui/EmptyState";
import { ClientForm } from "@/components/clients/ClientForm";
import { useDbReady } from "@/lib/hooks/useDbReady";
import { useAsyncResource } from "@/lib/hooks/useAsyncResource";
import { getClientById } from "@/lib/repositories/clients";
import { routes } from "@/lib/routes";

type ClientFormPageProps = {
  mode: "create" | "edit";
  clientId?: string;
};

export function ClientFormPage({ mode, clientId }: ClientFormPageProps) {
  const db = useDbReady();
  const resource = useAsyncResource(
    async () => {
      if (!clientId) {
        return null;
      }
      return getClientById(clientId);
    },
    [clientId],
    { enabled: mode === "edit" && db.status === "ready" && Boolean(clientId) },
  );

  if (mode === "create") {
    if (db.status === "loading") {
      return <LoadingState label="Загрузка формы…" />;
    }
    if (db.status === "unavailable") {
      return <Alert tone="error" title="База недоступна">{db.message}</Alert>;
    }

    return (
      <div>
        <PageHeader
          title="Новый клиент"
          description="Укажите имя и при необходимости контакты и заметки."
          actions={
            <LinkButton href={routes.clients} variant="secondary">
              Назад
            </LinkButton>
          }
        />
        <ClientForm key="client-create" mode="create" />
      </div>
    );
  }

  if (db.status === "loading" || (db.status === "ready" && resource.status === "loading")) {
    return <LoadingState label="Загрузка формы…" />;
  }

  if (db.status === "unavailable") {
    return <Alert tone="error" title="База недоступна">{db.message}</Alert>;
  }

  if (resource.status === "error") {
    return <Alert tone="error" title="Ошибка">{resource.message}</Alert>;
  }

  if (resource.status === "ready" && !resource.data) {
    return (
      <EmptyState
        title="Клиент не найден"
        description="Нельзя редактировать отсутствующую запись."
        action={<LinkButton href={routes.clients}>К списку клиентов</LinkButton>}
      />
    );
  }

  const client = resource.status === "ready" ? resource.data : null;
  if (!client) {
    return <LoadingState label="Загрузка формы…" />;
  }

  return (
    <div>
      <PageHeader
        title="Редактирование клиента"
        description="Укажите имя и при необходимости контакты и заметки."
        actions={
          <LinkButton href={routes.clientDetail(client.id)} variant="secondary">
            Назад
          </LinkButton>
        }
      />
      <ClientForm key={`client-edit-${client.id}`} mode="edit" client={client} />
    </div>
  );
}
