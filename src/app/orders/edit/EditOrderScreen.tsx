"use client";

import { OrderFormPage } from "@/components/orders/OrderFormPage";
import { RequireQueryId } from "@/components/routing/RequireQueryId";
import { routes } from "@/lib/routes";

export function EditOrderScreen() {
  return (
    <RequireQueryId
      missingTitle="Заказ не указан"
      missingDescription="Нельзя редактировать без параметра id в адресе."
      backHref={routes.orders}
      backLabel="К списку заказов"
    >
      {(id) => <OrderFormPage mode="edit" orderId={id} />}
    </RequireQueryId>
  );
}
