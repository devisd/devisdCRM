import { Suspense } from "react";
import { LoadingState } from "@/components/ui/LoadingState";
import { EditClientScreen } from "./EditClientScreen";

export default function EditClientPage() {
  return (
    <Suspense fallback={<LoadingState label="Загрузка формы…" />}>
      <EditClientScreen />
    </Suspense>
  );
}
