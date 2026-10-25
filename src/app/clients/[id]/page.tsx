import { Suspense } from "react";
import { ClientDetail } from "@/components/clients/ClientDetail";
import { LoadingState } from "@/components/ui/LoadingState";

type ClientParams = Promise<{ id: string }>;

async function ClientDetailRoute({ params }: { params: ClientParams }) {
  const { id } = await params;
  return <ClientDetail clientId={id} />;
}

export default function ClientDetailPage({ params }: { params: ClientParams }) {
  return (
    <Suspense fallback={<LoadingState label="Загрузка клиента…" />}>
      <ClientDetailRoute params={params} />
    </Suspense>
  );
}
