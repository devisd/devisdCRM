"use client";

import { useSearchParams } from "next/navigation";
import { PageHeader } from "@/components/ui/PageHeader";
import { LinkButton } from "@/components/ui/LinkButton";
import { LoadingState } from "@/components/ui/LoadingState";
import { Alert } from "@/components/ui/Alert";
import { EmptyState } from "@/components/ui/EmptyState";
import { OrderForm } from "@/components/orders/OrderForm";
import { useDbReady } from "@/lib/hooks/useDbReady";
import { useAsyncResource } from "@/lib/hooks/useAsyncResource";
import { listClients } from "@/lib/repositories/clients";
import {
  getOrderById,
  getOrderItems,
} from "@/lib/repositories/orders";

type OrderFormPageProps = {
  mode: "create" | "edit";
  orderId?: string;
};

export function OrderFormPage({ mode, orderId }: OrderFormPageProps) {
  const db = useDbReady();
  const searchParams = useSearchParams();
  const presetClientId = searchParams.get("clientId") ?? undefined;

  const resource = useAsyncResource(
    async () => {
      const clients = await listClients({ includeArchived: true });
      if (mode === "create") {
        return { clients, order: null, items: [] };
      }
      if (!orderId) {
        return { clients, order: null, items: [] };
      }
      const order = await getOrderById(orderId);
      const items = order ? await getOrderItems(orderId) : [];
      return { clients, order: order ?? null, items };
    },
    [mode, orderId],
    { enabled: db.status === "ready" },
  );

  if (db.status === "loading" || (db.status === "ready" && resource.status === "loading")) {
    return <LoadingState label="Загрузка формы заказа…" />;
  }

  if (db.status === "unavailable") {
    return <Alert tone="error" title="База недоступна">{db.message}</Alert>;
  }

  if (resource.status === "error") {
    return <Alert tone="error" title="Ошибка">{resource.message}</Alert>;
  }

  if (resource.status !== "ready") {
    return <LoadingState label="Загрузка формы заказа…" />;
  }

  if (mode === "edit" && !resource.data.order) {
    return (
      <EmptyState
        title="Заказ не найден"
        description="Нельзя редактировать отсутствующую запись."
        action={<LinkButton href="/orders">К списку заказов</LinkButton>}
      />
    );
  }

  const { clients, order, items } = resource.data;

  return (
    <div>
      <PageHeader
        title={mode === "create" ? "Новый заказ" : "Редактирование заказа"}
        description="Клиент, позиции работ, скидка, сроки и статус."
        actions={
          <LinkButton
            href={order ? `/orders/${order.id}` : "/orders"}
            variant="secondary"
          >
            Назад
          </LinkButton>
        }
      />
      <OrderForm
        key={mode === "create" ? "order-create" : `order-edit-${order?.id ?? "unknown"}`}
        mode={mode}
        clients={clients}
        initialClientId={presetClientId}
        order={order ?? undefined}
        items={items}
      />
    </div>
  );
}
