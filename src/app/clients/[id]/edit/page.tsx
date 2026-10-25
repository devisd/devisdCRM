import { Suspense } from "react";
import { ClientFormPage } from "@/components/clients/ClientFormPage";
import { LoadingState } from "@/components/ui/LoadingState";

type ClientParams = Promise<{ id: string }>;

async function EditClientRoute({ params }: { params: ClientParams }) {
  const { id } = await params;
  return <ClientFormPage mode="edit" clientId={id} />;
}

export default function EditClientPage({ params }: { params: ClientParams }) {
  return (
    <Suspense fallback={<LoadingState label="Загрузка формы…" />}>
      <EditClientRoute params={params} />
    </Suspense>
  );
}
