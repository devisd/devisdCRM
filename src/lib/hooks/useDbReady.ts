"use client";

import { useEffect, useState } from "react";
import { isIndexedDbAvailable } from "@/lib/db/database";

export type DbReadyState =
  | { status: "loading" }
  | { status: "ready" }
  | { status: "unavailable"; message: string };

export function useDbReady(): DbReadyState {
  const [state, setState] = useState<DbReadyState>({ status: "loading" });

  useEffect(() => {
    const timer = window.setTimeout(() => {
      if (!isIndexedDbAvailable()) {
        setState({
          status: "unavailable",
          message:
            "IndexedDB недоступен в этом браузере. Локальные данные сохранить нельзя.",
        });
        return;
      }

      setState({ status: "ready" });
    }, 0);

    return () => window.clearTimeout(timer);
  }, []);

  return state;
}
