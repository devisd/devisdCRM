"use client";

import { useCallback, useEffect, useState } from "react";

export type AsyncResourceResult<T> =
  | { status: "loading"; reload: () => void }
  | { status: "ready"; data: T; reload: () => void }
  | { status: "error"; message: string; reload: () => void };

type UseAsyncResourceOptions = {
  enabled?: boolean;
};

/**
 * Loads data after mount / dependency change.
 * Defers the start so setState is not synchronous inside useEffect
 * (React Compiler / eslint react-hooks/set-state-in-effect).
 */
export function useAsyncResource<T>(
  loader: () => Promise<T>,
  deps: unknown[],
  options?: UseAsyncResourceOptions,
): AsyncResourceResult<T> {
  const enabled = options?.enabled ?? true;
  const [status, setStatus] = useState<"loading" | "ready" | "error">("loading");
  const [data, setData] = useState<T | undefined>(undefined);
  const [message, setMessage] = useState<string | undefined>(undefined);
  const [reloadKey, setReloadKey] = useState(0);

  const reload = useCallback(() => {
    setReloadKey((value) => value + 1);
  }, []);

  useEffect(() => {
    if (!enabled) {
      return;
    }

    let cancelled = false;
    const timer = window.setTimeout(() => {
      setStatus("loading");
      void (async () => {
        try {
          const next = await loader();
          if (!cancelled) {
            setData(next);
            setMessage(undefined);
            setStatus("ready");
          }
        } catch (error) {
          if (!cancelled) {
            setMessage(
              error instanceof Error
                ? error.message
                : "Не удалось загрузить данные",
            );
            setStatus("error");
          }
        }
      })();
    }, 0);

    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- caller controls deps explicitly
  }, [enabled, reloadKey, ...deps]);

  if (status === "ready" && data !== undefined) {
    return { status: "ready", data, reload };
  }

  if (status === "error") {
    return {
      status: "error",
      message: message ?? "Не удалось загрузить данные",
      reload,
    };
  }

  return { status: "loading", reload };
}
