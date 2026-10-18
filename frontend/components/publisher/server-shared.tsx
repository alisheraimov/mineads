"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { ChevronRight, Loader2 } from "lucide-react";
import { PublisherShell } from "@/components/publisher/publisher-shell";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { ApiError, User, clearToken, getCurrentUser, logout } from "@/lib/auth";

export function ServerWorkspace({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const [user, setUser] = useState<User | null>(null);
  const [error, setError] = useState("");
  useEffect(() => {
    let mounted = true;
    getCurrentUser().then(({ data }) => {
      if (!mounted) return;
      if (data.role !== "publisher" || data.publisher_account?.status !== "active") throw new Error("An active publisher account is required.");
      setUser(data);
    }).catch((err) => {
      if (!mounted) return;
      if (err instanceof ApiError && err.status === 401) { clearToken(); router.replace("/login"); }
      else setError(err instanceof Error ? err.message : "Unable to load workspace.");
    });
    return () => { mounted = false; };
  }, [router]);
  if (error) return <main className="grid min-h-screen place-items-center p-6"><div className="space-y-4 text-center"><p role="alert">{error}</p><Button asChild><Link href="/account">Account settings</Link></Button></div></main>;
  if (!user) return <main className="grid min-h-screen place-items-center"><Loader2 className="size-5 animate-spin" aria-label="Loading publisher workspace" /></main>;
  return <PublisherShell user={user} onLogout={async () => { await logout().catch(clearToken); router.push("/login"); }}><div className="mx-auto flex max-w-[1112px] flex-col gap-6">{children}</div></PublisherShell>;
}

export function ServerBreadcrumb({ current }: { current?: string }) {
  return <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"><Link href="/publisher">Publisher</Link><ChevronRight className="size-3" /><Link href="/publisher/servers">Servers</Link>{current && <><ChevronRight className="size-3" /><span className="text-foreground" aria-current="page">{current}</span></>}</nav>;
}
export function ServerStatus({ status }: { status: string }) {
  return <Badge variant="secondary" className={status === "active" ? "bg-emerald-100 text-emerald-800" : status === "pending" ? "bg-amber-100 text-amber-800" : status === "suspended" ? "bg-red-100 text-red-800" : ""}>{status === "inactive" ? "Disabled" : status.charAt(0).toUpperCase() + status.slice(1)}</Badge>;
}
export function utcDate(value: string | null) {
  return value ? new Date(value).toLocaleString("en-US", { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit", timeZone: "UTC" }) + " UTC" : "Never";
}
export function money(value: number, currency = "USD") {
  return new Intl.NumberFormat("en-US", { style: "currency", currency }).format(value / 1_000_000);
}
export function UnavailablePluginLinks() {
  return <div className="flex flex-wrap gap-2"><Button disabled title="Plugin download will be available later">Download plugin</Button><Button variant="outline" disabled title="Setup guide will be available later">Setup guide</Button></div>;
}
export function ServerMetric({ label, value, caption }: { label: string; value: string | number; caption: string }) {
  return <div className="rounded-lg border bg-card p-5"><p className="text-xs text-muted-foreground">{label}</p><p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p><p className="mt-2 text-xs text-muted-foreground">{caption}</p></div>;
}
export const panelClass = "rounded-lg border bg-card p-6";
