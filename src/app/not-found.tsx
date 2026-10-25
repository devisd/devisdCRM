import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { LinkButton } from "@/components/ui/LinkButton";

export default function NotFoundPage() {
  return (
    <div>
      <PageHeader title="Страница не найдена" />
      <EmptyState
        title="Такой страницы нет"
        description="Проверьте адрес или вернитесь к обзору."
        action={<LinkButton href="/">На обзор</LinkButton>}
      />
    </div>
  );
}
