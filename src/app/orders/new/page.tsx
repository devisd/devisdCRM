import { Suspense } from "react";
import { OrderFormPage } from "@/components/orders/OrderFormPage";
import { LoadingState } from "@/components/ui/LoadingState";

export default function NewOrderPage() {
  return (
    <Suspense fallback={<LoadingState label="Загрузка формы заказа…" />}>
      <OrderFormPage mode="create" />
    </Suspense>
  );
}
