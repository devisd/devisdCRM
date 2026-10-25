import { Suspense } from "react";
import { OrderDetail } from "@/components/orders/OrderDetail";
import { LoadingState } from "@/components/ui/LoadingState";

type OrderParams = Promise<{ id: string }>;

async function OrderDetailRoute({ params }: { params: OrderParams }) {
  const { id } = await params;
  return <OrderDetail orderId={id} />;
}

export default function OrderDetailPage({ params }: { params: OrderParams }) {
  return (
    <Suspense fallback={<LoadingState label="Загрузка заказа…" />}>
      <OrderDetailRoute params={params} />
    </Suspense>
  );
}
