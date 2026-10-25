import { Suspense } from "react";
import { LoadingState } from "@/components/ui/LoadingState";
import { ClientDetailScreen } from "./ClientDetailScreen";

export default function ClientDetailPage() {
  return (
    <Suspense fallback={<LoadingState label="Загрузка клиента…" />}>
      <ClientDetailScreen />
    </Suspense>
  );
}
