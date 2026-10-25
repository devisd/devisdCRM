"use client";

import { ClientFormPage } from "@/components/clients/ClientFormPage";
import { RequireQueryId } from "@/components/routing/RequireQueryId";
import { routes } from "@/lib/routes";

export function EditClientScreen() {
  return (
    <RequireQueryId
      missingTitle="Клиент не указан"
      missingDescription="Нельзя редактировать без параметра id в адресе."
      backHref={routes.clients}
      backLabel="К списку клиентов"
    >
      {(id) => <ClientFormPage mode="edit" clientId={id} />}
    </RequireQueryId>
  );
}
