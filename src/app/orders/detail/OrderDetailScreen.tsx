"use client";

import { OrderDetail } from "@/components/orders/OrderDetail";
import { RequireQueryId } from "@/components/routing/RequireQueryId";
import { routes } from "@/lib/routes";

export function OrderDetailScreen() {
  return (
    <RequireQueryId
      missingTitle="Заказ не указан"
      missingDescription="Откройте заказ из списка или укажите параметр id в адресе."
      backHref={routes.orders}
      backLabel="К списку заказов"
    >
      {(id) => <OrderDetail orderId={id} />}
    </RequireQueryId>
  );
}
