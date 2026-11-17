"use client";

import { useEffect, useState } from "react";
import { apiRequest, getToken } from "@/lib/auth";
import { Button } from "@/components/ui/button";

export function useServerCategories() {
  const [categories, setCategories] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError("");
    apiRequest<{ data: string[] }>("/server-categories", {
      token: getToken(),
      signal: controller.signal,
    })
      .then(({ data }) => { if (!controller.signal.aborted) setCategories(data); })
      .catch((error) => {
        if (!controller.signal.aborted)
          setError(error instanceof Error ? error.message : "Unable to load server categories.");
      })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [attempt]);

  return { categories, loading, error, retry: () => setAttempt(value => value + 1) };
}

export function ServerCategoryFeedback({ loading, error, retry }: {
  loading: boolean; error: string; retry: () => void;
}) {
  if (error) return <div role="alert" className="text-sm text-destructive">
    Unable to load server categories. <Button type="button" variant="link" size="sm" onClick={retry}>Retry</Button>
  </div>;
  if (loading) return <p role="status" className="text-xs text-muted-foreground">Loading categories…</p>;
  return null;
}
