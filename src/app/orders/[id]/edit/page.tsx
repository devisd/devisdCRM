import { Suspense } from "react";
import { OrderFormPage } from "@/components/orders/OrderFormPage";
import { LoadingState } from "@/components/ui/LoadingState";

type OrderParams = Promise<{ id: string }>;

async function EditOrderRoute({ params }: { params: OrderParams }) {
  const { id } = await params;
  return (
    <Suspense fallback={<LoadingState label="Загрузка формы заказа…" />}>
      <OrderFormPage mode="edit" orderId={id} />
    </Suspense>
  );
}

export default function EditOrderPage({ params }: { params: OrderParams }) {
  return (
    <Suspense fallback={<LoadingState label="Загрузка формы заказа…" />}>
      <EditOrderRoute params={params} />
    </Suspense>
  );
}
