"use client";

import { useMemo } from "react";
import { useRouter as useTopLoaderRouter } from "nextjs-toploader/app";

export function useRouter() {
  const { back, forward, refresh, prefetch, push, replace } = useTopLoaderRouter();

  // Keep router-dependent effects from restarting on every render.
  return useMemo(
    () => ({ back, forward, refresh, prefetch, push, replace }),
    [back, forward, refresh, prefetch, push, replace],
  );
}
