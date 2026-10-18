"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { ChevronRight, Loader2, Server } from "lucide-react";
import { Button } from "@/components/ui/button";
import { startRouteProgress } from "@/components/ui/route-progress";
import { PublisherShell, AddServerButton } from "@/components/publisher/publisher-shell";
import { EarningsChart } from "@/components/publisher/earnings-chart";
import { getPublisherDashboard, PublisherDashboard as DashboardData } from "@/lib/publisher";
import { ApiError, User, clearToken, getCurrentUser, logout } from "@/lib/auth";

type LoadState = { status: "loading" } | { status: "error"; message: string } | { status: "ready"; user: User; dashboard: DashboardData };

export function PublisherDashboard() {
  const router = useRouter();
  const [state, setState] = useState<LoadState>({ status: "loading" });
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let mounted = true;
    Promise.all([getCurrentUser(), getPublisherDashboard()]).then(([user, dashboard]) => {
      if (!mounted) return;
      if (user.data.role !== "publisher") throw new Error("This workspace is available to publishers only.");
      setState({ status: "ready", user: user.data, dashboard: dashboard.data });
    }).catch((error) => {
      if (!mounted) return;
      if (error instanceof ApiError && error.status === 401) {
        clearToken();
        startRouteProgress();
        router.replace("/login");
        return;
      }
      setState({ status: "error", message: error instanceof Error ? error.message : "Unable to load publisher dashboard." });
    });
    return () => { mounted = false; };
  }, [attempt, router]);

  async function handleLogout() {
    await logout().catch(() => clearToken());
    startRouteProgress();
    router.push("/login");
  }

  if (state.status === "loading") return <main className="grid min-h-screen place-items-center bg-background"><Loader2 className="size-5 animate-spin" aria-label="Loading publisher dashboard" /></main>;
  if (state.status === "error") return (
    <main className="grid min-h-screen place-items-center bg-background p-6">
      <div className="max-w-md space-y-4 rounded-lg border bg-card p-6 text-center">
        <h1 className="text-lg font-semibold">Unable to load dashboard</h1>
        <p role="alert" className="text-sm text-muted-foreground">{state.message}</p>
        <div className="flex justify-center gap-2">
          <Button onClick={() => { setState({ status: "loading" }); setAttempt((value) => value + 1); }}>Try again</Button>
          <Button asChild variant="outline"><Link href="/account">Account settings</Link></Button>
        </div>
      </div>
    </main>
  );

  return <PublisherShell user={state.user} onLogout={handleLogout}><DashboardContent dashboard={state.dashboard} /></PublisherShell>;
}

function DashboardContent({ dashboard }: { dashboard: DashboardData }) {
  const formatDate = (date: string, options: Intl.DateTimeFormatOptions) => new Date(date).toLocaleDateString("en-US", { ...options, timeZone: "UTC" });
  const money = (micros: number) => new Intl.NumberFormat("en-US", { style: "currency", currency: dashboard.account.currency }).format(micros / 1_000_000);
  const m = dashboard.metrics;
  const today = formatDate(dashboard.period.today_start, { month: "short", day: "numeric" });
  const month = `${formatDate(dashboard.period.month_start, { month: "short", day: "numeric" })}–${formatDate(dashboard.period.month_end, { day: "numeric" })}`;
  const cards = [
    ["Earnings today", money(m.earnings_today_micros), `${today} · Estimated`],
    ["Earnings this month", money(m.earnings_month_micros), `${month} · Current estimate`],
    ["Available for payout", money(m.available_payout_micros), "Closed billing periods · Eligible unpaid earnings"],
    ["Validated impressions today", m.impressions_today.toLocaleString("en-US"), `${today} · Across all servers`],
    ["Validated impressions this month", m.impressions_month.toLocaleString("en-US"), `${month} · Across all servers`],
    ["Avg CPM", money(m.effective_cpm_micros), "Effective CPM · This month"],
  ];

  return (
    <div className="mx-auto flex max-w-[1112px] flex-col gap-6">
      <nav aria-label="Breadcrumb" className="flex items-center gap-2 text-sm text-muted-foreground"><span>Publisher</span><ChevronRight className="size-3.5" aria-hidden="true" /><span aria-current="page">Dashboard</span></nav>
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div><h1 className="text-2xl font-semibold">Dashboard</h1><p className="mt-2 text-sm text-muted-foreground">Your server performance and earnings at a glance.</p></div>
        {dashboard.server_count > 0 && <AddServerButton />}
      </div>
      {dashboard.server_count === 0 ? (
        <section className="rounded-lg border bg-card p-6 sm:p-8">
          <div className="max-w-[580px]">
            <div className="mb-4 grid size-10 place-items-center rounded-md bg-muted"><Server className="size-5" aria-hidden="true" /></div>
            <h2 className="text-xl font-semibold">Connect your first Minecraft server</h2>
            <p className="mt-3 text-sm leading-6 text-muted-foreground">Connect a server and activate the MineAds plugin to start earning from ads. Your server performance and earnings will appear here.</p>
            <div className="mt-4"><AddServerButton /></div>
          </div>
        </section>
      ) : (
        <>
          <div className="flex flex-col gap-2 text-xs text-muted-foreground sm:flex-row sm:justify-between">
            <span>{formatDate(dashboard.period.today_start, { weekday: "long", month: "long", day: "numeric", year: "numeric" })} · UTC</span>
            <span>Last updated {new Date(dashboard.generated_at).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit", timeZone: "UTC" })} UTC · Current estimates</span>
          </div>
          <section aria-label="Summary metrics" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {cards.map(([label, value, caption]) => <div key={label} className="flex min-h-[130px] flex-col justify-center rounded-lg border bg-card p-5"><p className="text-xs text-muted-foreground">{label}</p><p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p><p className="mt-1 text-xs text-muted-foreground">{caption}</p></div>)}
          </section>
          <EarningsChart dashboard={dashboard} />
        </>
      )}
    </div>
  );
}
