import { Suspense } from "react";
import { LoadingState } from "@/components/ui/LoadingState";
import { OrderDetailScreen } from "./OrderDetailScreen";

export default function OrderDetailPage() {
  return (
    <Suspense fallback={<LoadingState label="Загрузка заказа…" />}>
      <OrderDetailScreen />
    </Suspense>
  );
}
