"use client";

import { ClientDetail } from "@/components/clients/ClientDetail";
import { RequireQueryId } from "@/components/routing/RequireQueryId";
import { routes } from "@/lib/routes";

export function ClientDetailScreen() {
  return (
    <RequireQueryId
      missingTitle="Клиент не указан"
      missingDescription="Откройте клиента из списка или укажите параметр id в адресе."
      backHref={routes.clients}
      backLabel="К списку клиентов"
    >
      {(id) => <ClientDetail clientId={id} />}
    </RequireQueryId>
  );
}
