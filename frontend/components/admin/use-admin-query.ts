"use client";

import { useEffect, useState } from "react";
import { useRouter } from "@/lib/navigation";
import { ApiError, clearToken } from "@/lib/auth";

export function useAdminQuery<T>(loader: (signal: AbortSignal) => Promise<T>, key: string) {
  const router = useRouter();
  const [result, setResult] = useState<{ key: string; data: T }>();
  const [failure, setFailure] = useState<{ key: string; message: string }>();
  const [attempt, setAttempt] = useState(0);
  const [loading, setLoading] = useState(false);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    loader(controller.signal).then(data => {
      if (!controller.signal.aborted) { setResult({ key, data }); setFailure(undefined); }
    }).catch(error => {
      if (controller.signal.aborted) return;
      if (error instanceof ApiError && error.status === 401) { clearToken(); router.replace("/login"); return; }
      setFailure({ key, message: error instanceof ApiError && error.status === 404 ? "This record could not be found." : error instanceof Error ? error.message : "Unable to load campaign data." });
    }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [loader, key, attempt, router]);
  return { data: result?.key === key ? result.data : undefined, error: failure?.key === key ? failure.message : undefined,
    loading, refresh: () => setAttempt(value => value + 1), replaceData: (data: T) => { setResult({ key, data }); setFailure(undefined); } };
}
