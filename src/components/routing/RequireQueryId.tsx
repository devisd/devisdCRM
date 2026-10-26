"use client";

import type { ReactNode } from "react";
import { useSearchParams } from "next/navigation";
import { EmptyState } from "@/components/ui/EmptyState";
import { LinkButton } from "@/components/ui/LinkButton";

type RequireQueryIdProps = {
  children: (id: string) => ReactNode;
  missingTitle: string;
  missingDescription: string;
  backHref: string;
  backLabel: string;
  paramName?: string;
};

export function RequireQueryId({
  children,
  missingTitle,
  missingDescription,
  backHref,
  backLabel,
  paramName = "id",
}: RequireQueryIdProps) {
  const searchParams = useSearchParams();
  const id = searchParams.get(paramName)?.trim() ?? "";

  if (!id) {
    return (
      <EmptyState
        title={missingTitle}
        description={missingDescription}
        action={<LinkButton href={backHref}>{backLabel}</LinkButton>}
      />
    );
  }

  return <>{children(id)}</>;
}
