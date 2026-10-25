import { Suspense } from "react";
import { LoadingState } from "@/components/ui/LoadingState";
import { EditOrderScreen } from "./EditOrderScreen";

export default function EditOrderPage() {
  return (
    <Suspense fallback={<LoadingState label="Загрузка формы заказа…" />}>
      <EditOrderScreen />
    </Suspense>
  );
}
