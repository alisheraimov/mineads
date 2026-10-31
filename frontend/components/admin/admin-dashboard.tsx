"use client";

import { useCallback, useEffect, useId, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowRight, CalendarDays, ChevronRight, Info, Loader2, RefreshCw, ShieldCheck } from "lucide-react";
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from "recharts";
import { AccountHeader } from "@/components/account/account-header";
import { AdvertiserSidebar } from "@/components/advertiser/advertiser-shell";
import { Button } from "@/components/ui/button";
import { DatePicker } from "@/components/ui/date-picker";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ChartContainer, ChartTooltip, ChartTooltipContent } from "@/components/ui/chart";
import { ApiError, clearToken, getCurrentUser, getToken, logout, adminCan, adminHome, type User } from "@/lib/auth";
import { getAdminPerformance, getAdminSnapshot, type AdminPerformance, type AdminPeriod } from "@/lib/admin";
import { cn } from "@/lib/utils";

const periods: Record<AdminPeriod, string> = {
  today: "Today", yesterday: "Yesterday", last7: "Last 7 days", last30: "Last 30 days", custom: "Custom range",
};
const number = (value: number) => value.toLocaleString("en-US");
const money = (value: number, currency = "USD") => new Intl.NumberFormat("en-US", { style: "currency", currency }).format(value / 1_000_000);
const date = (value: string) => new Date(value).toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric", timeZone: "UTC" });
const time = (value: string) => new Date(value).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit", timeZone: "UTC" });

function useDashboardSection<T>(
  loader: (signal?: AbortSignal) => Promise<{ data: T }>,
  key: string,
  enabled: boolean,
  onAccessError: (error: ApiError) => void,
) {
  const [result, setResult] = useState<{ key: string; data: T }>();
  const [failure, setFailure] = useState<{ key: string; message: string }>();
  const [refreshing, setRefreshing] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const refresh = useCallback(() => setAttempt(value => value + 1), []);
  useEffect(() => {
    if (!enabled) return;
    const controller = new AbortController();
    let active = true;
    let inFlight = false;
    async function load() {
      if (inFlight) return;
      inFlight = true;
      setRefreshing(true);
      try {
        const response = await loader(controller.signal);
        if (active) {
          setResult({ key, data: response.data });
          setFailure(undefined);
        }
      } catch (error) {
        if (!active) return;
        if (error instanceof ApiError && [401, 403].includes(error.status)) {
          onAccessError(error);
          return;
        }
        setFailure({ key, message: error instanceof Error ? error.message : "Unable to load this section." });
      } finally {
        inFlight = false;
        if (active) setRefreshing(false);
      }
    }
    void load();
    const interval = window.setInterval(() => {
      if (document.visibilityState === "visible") void load();
    }, 60_000);
    return () => { active = false; controller.abort(); window.clearInterval(interval); };
  }, [loader, key, enabled, onAccessError, attempt]);
  return { data: enabled && result?.key === key ? result.data : undefined, error: failure?.key === key ? failure.message : undefined, refreshing, refresh };
}

export function AdminDashboard() {
  const router = useRouter();
  const [user, setUser] = useState<User | null>(null);
  const [comingSoon, setComingSoon] = useState("");
  const [selection, setSelection] = useState<{ period: AdminPeriod; from?: string; to?: string }>({ period: "today" });
  const [customOpen, setCustomOpen] = useState(false);
  const [draft, setDraft] = useState({ from: "", to: "" });
  const accessError = useCallback((error: ApiError) => {
    setUser(null);
    if (error.status === 401) { clearToken(); router.replace("/login"); }
    else router.replace("/account");
  }, [router]);
  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }
    let active = true;
    getCurrentUser().then(({ data }) => {
      if (!active) return;
      if (data.status !== "active") {
        router.replace("/account-suspended");
        return;
      }
      if (data.role !== "admin") {
        router.replace(data.role === "publisher" ? "/publisher" : "/advertiser");
        return;
      }
      if (!data.email_verified_at) {
        router.replace("/verify-email?email=" + encodeURIComponent(data.email));
        return;
      }
      if (!adminCan(data, "admin.analytics.view")) {
        router.replace(adminHome(data));
        return;
      }
      setUser(data);
    }).catch(error => {
      if (!active) return;
      if (error instanceof ApiError && [401, 403].includes(error.status)) accessError(error);
      else router.replace("/login");
    });
    return () => { active = false; };
  }, [router, accessError]);
  const query = new URLSearchParams(Object.entries(selection).filter(([, value]) => value !== undefined) as [string, string][]).toString();
  const performanceLoader = useCallback((signal?: AbortSignal) => getAdminPerformance(query, signal), [query]);
  const snapshot = useDashboardSection(getAdminSnapshot, "snapshot", !!user, accessError);
  const performance = useDashboardSection(performanceLoader, query, !!user, accessError);
  const today = new Date().toISOString().slice(0, 10);
  const s = snapshot.data;
  const p = performance.data;
  const refreshing = snapshot.refreshing || performance.refreshing;
  async function handleLogout() {
    await logout().catch(() => clearToken());
    clearToken();
    setUser(null);
    router.replace("/login");
  }
  if (!user) {
    return (
      <main className="grid min-h-screen place-items-center bg-background px-6">
        <Loader2 className="size-5 animate-spin" aria-label="Verifying access" />
      </main>
    );
  }
  return (
    <div className="min-h-screen bg-background text-foreground">
      <AccountHeader user={user} onLogout={() => { void handleLogout(); }} />
      <div className="flex min-h-[calc(100vh-72px)]">
        <AdvertiserSidebar balance={0} workspace="admin" user={user} onComingSoon={setComingSoon} />
        <main className="min-w-0 flex-1 bg-canvas px-5 py-8 sm:px-8 lg:px-10">
          <div className="mx-auto flex max-w-[1112px] flex-col gap-6">
            <nav aria-label="Breadcrumb" className="flex items-center gap-2 text-sm text-muted-foreground">
              <span>Admin</span><ChevronRight className="size-3.5" aria-hidden="true" /><span aria-current="page" className="text-foreground">Overview</span>
            </nav>
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div><h1 className="text-2xl font-semibold">Overview</h1><p className="mt-2 text-sm text-muted-foreground">Marketplace activity, financial performance and work that needs attention.</p></div>
              <Button variant="outline" disabled={!user || refreshing} onClick={() => { snapshot.refresh(); performance.refresh(); }}>
                <RefreshCw className={cn("size-4", refreshing && "animate-spin")} aria-hidden="true" />Refresh
              </Button>
            </div>
            <div className="flex flex-wrap gap-2 lg:hidden" aria-label="Admin navigation">
              {adminCan(user, "admin.users.view") && <Button asChild variant="outline" size="sm"><Link href="/admin/users">Users & accounts</Link></Button>}
            </div>
            <>
              <section aria-labelledby="marketplace-heading" className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <h2 id="marketplace-heading" className="text-sm font-semibold">Marketplace now</h2>
                  <p className="text-xs text-muted-foreground">{s ? "Updated " + time(s.generated_at) + " UTC · " : ""}Refreshes every 60s</p>
                </div>
                {snapshot.error && <SectionError message={snapshot.error} stale={!!s} onRetry={snapshot.refresh} />}
                {s ? <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                  <Metric label="Active campaigns" value={number(s.active_campaigns)} caption="Currently active campaigns" />
                  <Metric label="Pending creative reviews" value={number(s.pending_creatives)} caption="Open review queue" onClick={adminCan(user, "admin.creatives.view") || adminCan(user, "admin.creatives.review") ? () => router.push("/admin/creatives") : undefined} />
                  <Metric label="Active publisher servers" value={number(s.active_servers)} caption="Enabled to serve ads" />
                  <Metric label="Online servers" blinkingDot value={number(s.online_servers) + " / " + number(s.active_servers)} caption={"Active · heartbeat within " + s.health_rules.heartbeat_timeout_seconds / 60 + " min"} />
                </div> : !snapshot.error && <MetricSkeleton count={4} />}
              </section>
              <section aria-labelledby="performance-heading" className="space-y-4" aria-busy={performance.refreshing}>
                <div className="flex flex-wrap items-center justify-between gap-4">
                  <div><h2 id="performance-heading" className="text-base font-semibold">Performance</h2><p className="mt-1 text-xs text-muted-foreground">{p ? date(p.period.from) + (p.period.to !== p.period.from ? " – " + date(p.period.to) : "") + " · UTC · Updated " + time(p.generated_at) : "Selected period · UTC"}</p></div>
                  <div className="ml-auto flex max-w-full flex-wrap items-center justify-end gap-2">
                    <Select value={selection.period} onValueChange={value => {
                      if (value === "custom") {
                        setDraft({ from: selection.from ?? p?.period.from ?? today, to: selection.to ?? p?.period.to ?? today });
                        setCustomOpen(true);
                      } else setSelection({ period: value as AdminPeriod });
                    }}>
                      <SelectTrigger aria-label="Performance date range" className="bg-background"><SelectValue /></SelectTrigger>
                      <SelectContent position="popper" side="bottom" align="start">
                        <SelectGroup>
                          {Object.entries(periods).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}
                        </SelectGroup>
                      </SelectContent>
                    </Select>
                    {selection.period === "custom" && (
                      <Button variant="outline" aria-label="Edit custom date range" className="max-w-full" onClick={() => { setDraft({ from: selection.from!, to: selection.to! }); setCustomOpen(true); }}>
                        <CalendarDays aria-hidden="true" />
                        <span className="truncate">{date(selection.from!)}{selection.to !== selection.from && " – " + date(selection.to!)}</span>
                      </Button>
                    )}
                  </div>
                </div>
                {performance.error && <SectionError message={performance.error} stale={!!p} onRetry={performance.refresh} />}
                {p ? <>
                  <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <Metric label="Reported impressions" value={number(p.metrics.reported_impressions)} caption="Received during selected period" />
                    <Metric label="Validated impressions" value={number(p.metrics.validated_impressions)} caption="Processed during selected period" />
                    <Metric label="Invalid impressions" value={number(p.metrics.invalid_impressions)} caption={number(p.metrics.invalid_rate) + "% of processed impressions"} />
                    {adminCan(user, "admin.finance.view") && <><Metric label="Advertiser spend" value={money(p.metrics.spend_micros, p.currency)} caption="Validated impressions · USD" />
                    <Metric label="Publisher earnings" value={money(p.metrics.earnings_micros, p.currency)} caption="Includes earnings awaiting release" />
                    <Metric label="Platform fee" value={money(p.metrics.fee_micros, p.currency)} caption="Before operating and payment costs" /></>}
                  </div>
                  <PerformanceChart performance={p} canViewFinance={adminCan(user, "admin.finance.view")} />
                </> : !performance.error && <><MetricSkeleton count={6} /><div role="status" aria-label="Loading performance chart" className="h-80 animate-pulse rounded-lg border bg-muted" /></>}
              </section>
              <section aria-labelledby="attention-heading" className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-2"><h2 id="attention-heading" className="text-base font-semibold">Needs attention</h2><p className="text-xs text-muted-foreground">Current queues · independent of selected dates</p></div>
                {s ? <div className="grid gap-4 xl:grid-cols-3">
                  <Attention title="Creative reviews" value={s.pending_creatives ? number(s.pending_creatives) + " pending" : "All caught up"} detail={s.oldest_pending_creative_at ? "Oldest submission · " + date(s.oldest_pending_creative_at) + " at " + time(s.oldest_pending_creative_at) + " UTC" : "No creatives awaiting review"} action="Review creatives" onClick={adminCan(user, "admin.creatives.view") || adminCan(user, "admin.creatives.review") ? () => router.push("/admin/creatives") : undefined} />
                  <Attention title="Unhealthy servers" value={s.unhealthy_servers ? number(s.unhealthy_servers) + " servers" : "All healthy"} detail={s.offline_servers + " offline · " + s.high_invalid_rate_servers + " high invalid rate · counts may overlap"} action="Inspect servers" onClick={adminCan(user, "admin.servers.view") ? () => router.push("/admin/servers") : undefined} />
                  {adminCan(user, "admin.finance.view") && <Attention title="Pending payouts" value={s.pending_payouts ? number(s.pending_payouts) + " pending" : "All caught up"} detail={s.pending_payout_amounts.length ? s.pending_payout_amounts.map(amount => money(amount.amount_micros, amount.currency)).join(" · ") + " awaiting processing" : "No payouts awaiting processing"} action="View payouts" onClick={() => router.push("/admin/finance/payouts?status=pending")} />}
                </div> : snapshot.error ? <p className="text-sm text-muted-foreground">Queues are unavailable until the marketplace snapshot loads.</p> : <MetricSkeleton count={3} />}
                {s && <details className="text-xs text-muted-foreground"><summary className="w-fit cursor-pointer">Server health rules</summary><p className="mt-2 max-w-3xl leading-5">An active server is offline without a heartbeat in the last {s.health_rules.heartbeat_timeout_seconds / 60} minutes. It is also unhealthy when more than {s.health_rules.invalid_rate_percent}% of at least {s.health_rules.minimum_processed_impressions} processed impressions are invalid over the last 24 hours. A server matching both conditions is counted once.</p></details>}
              </section>
              <p className="text-xs leading-5 text-muted-foreground">Financial totals use validation time in UTC. Reported impressions use received time. Current counts and queues update independently.</p>
            </>
          </div>
        </main>
      </div>
      <Dialog open={!!comingSoon} onOpenChange={open => { if (!open) setComingSoon(""); }}>
        <DialogContent><DialogHeader><DialogTitle>{comingSoon}</DialogTitle><DialogDescription>This section is coming soon. The overview shows live totals; the detailed management view will be added in a later milestone.</DialogDescription></DialogHeader><Button variant="outline" onClick={() => setComingSoon("")}>Back to overview</Button></DialogContent>
      </Dialog>
      <Dialog open={customOpen} onOpenChange={setCustomOpen}>
        <DialogContent><DialogHeader><DialogTitle>Custom date range</DialogTitle><DialogDescription>Select up to 366 days. Dates and reporting boundaries use UTC.</DialogDescription></DialogHeader>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2"><p className="text-sm font-medium">From</p><DatePicker id="admin-date-from" label="Start date" value={draft.from} max={draft.to || today} onChange={from => setDraft(value => ({ ...value, from }))} /></div>
            <div className="space-y-2"><p className="text-sm font-medium">To</p><DatePicker id="admin-date-to" label="End date" value={draft.to} min={draft.from} max={today} onChange={to => setDraft(value => ({ ...value, to }))} /></div>
          </div>
          <Button disabled={!draft.from || !draft.to || draft.from > draft.to || draft.to > today || (Date.parse(draft.to) - Date.parse(draft.from)) / 86_400_000 >= 366} onClick={() => { setSelection({ period: "custom", ...draft }); setCustomOpen(false); }}>Apply range</Button>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function SectionError({ message, stale, onRetry }: { message: string; stale?: boolean; onRetry: () => void }) {
  return <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-destructive/30 bg-card p-4 text-sm"><p>{stale ? "Showing previously loaded data. " : ""}{message}</p><Button variant="outline" size="sm" onClick={onRetry}>Retry</Button></div>;
}

function Metric({ label, value, caption, onClick, blinkingDot = false }: { label: string; value: string; caption: string; onClick?: () => void; blinkingDot?: boolean }) {
  const content = <><p className="flex items-center gap-2 text-sm text-muted-foreground">{blinkingDot && <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-emerald-500 motion-safe:animate-pulse" />}{label}</p><p className="mt-2 text-[28px] font-semibold leading-9 tabular-nums">{value}</p><p className="mt-1 flex items-center gap-1 text-xs leading-5 text-muted-foreground">{caption}{onClick && <ArrowRight className="size-3" aria-hidden="true" />}</p></>;
  const classes = "min-h-[122px] rounded-lg border border-border bg-card p-5 text-left";
  return onClick ? <button className={cn(classes, "transition-colors hover:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring")} onClick={onClick}>{content}</button> : <div className={classes}>{content}</div>;
}

function MetricSkeleton({ count }: { count: number }) {
  return <div role="status" aria-label="Loading metrics" className={cn("grid gap-4 sm:grid-cols-2", count === 4 ? "xl:grid-cols-4" : "xl:grid-cols-3")}>{Array.from({ length: count }, (_, i) => <div key={i} className="h-[132px] animate-pulse rounded-lg border bg-muted" />)}</div>;
}

function Attention({ title, value, detail, action, onClick }: { title: string; value: string; detail: string; action: string; onClick?: () => void }) {
  return <div className="flex flex-col rounded-lg border bg-card p-5"><h3 className="text-sm font-medium">{title}</h3><p className="mt-3 text-2xl font-semibold tabular-nums">{value}</p><p className="mb-3 mt-2 text-xs leading-5 text-muted-foreground">{detail}</p>{onClick && <Button variant="link" className="mt-auto h-auto justify-start self-start p-0 text-xs" onClick={onClick}>{action}<ArrowRight className="size-3" aria-hidden="true" /></Button>}</div>;
}

function PerformanceChart({ performance, canViewFinance }: { performance: AdminPerformance; canViewFinance: boolean }) {
  const gradientId = useId().replace(/:/g, "");
  const [metric, setMetric] = useState("impressions");
  const monetary = metric !== "impressions";
  const keys = metric === "impressions" ? ["validated", "invalid"] : [metric];
  const empty = performance.chart.every(point => keys.every(key => Number(point[key as keyof typeof point]) === 0));
  return <Tabs value={metric} onValueChange={setMetric} className="mt-6 gap-0 overflow-hidden rounded-lg border bg-card">
    <div className="flex flex-wrap items-center justify-between gap-4 px-6 pt-6">
      <div><h3 className="text-base font-semibold">Performance over time</h3><p className="mt-1 text-xs text-muted-foreground">{monetary ? "Amount in USD" : "Impressions"} by {performance.period.interval} · UTC</p></div>
      <TabsList aria-label="Chart metric" className="h-auto max-w-full flex-wrap">
        <TabsTrigger value="impressions">Impressions</TabsTrigger>{canViewFinance && <><TabsTrigger value="spend_micros">Spend</TabsTrigger><TabsTrigger value="earnings_micros">Earnings</TabsTrigger><TabsTrigger value="fee_micros">Platform fee</TabsTrigger></>}
      </TabsList>
    </div>
    {["impressions", "spend_micros", "earnings_micros", "fee_micros"].map(tab => <TabsContent value={tab} key={tab} className="m-0 px-4 pb-5 pt-4 sm:px-6">
      <div className="mb-4 flex gap-5 text-xs text-muted-foreground">{!monetary && <><span className="flex items-center gap-2"><span className="h-0.5 w-4 bg-foreground" />Validated</span><span className="flex items-center gap-2"><span className="w-4 border-t-2 border-dashed border-muted-foreground" />Invalid</span></>}</div>
      {empty ? <div className="grid h-60 place-items-center text-center"><div><ShieldCheck className="mx-auto mb-3 size-6 text-muted-foreground" aria-hidden="true" /><p className="text-sm font-medium">No activity in this period</p><p className="mt-1 text-xs text-muted-foreground">Choose another date range to explore marketplace performance.</p></div></div> :
        <ChartContainer className="h-60 w-full aspect-auto" aria-label={monetary ? "Financial performance in USD" : "Validated and invalid impressions"} config={{
          validated: { label: "Validated", color: "var(--foreground)" },
          invalid: { label: "Invalid", color: "var(--muted-foreground)" },
          spend_micros: { label: "Advertiser spend", color: "var(--foreground)" },
          earnings_micros: { label: "Publisher earnings", color: "var(--foreground)" },
          fee_micros: { label: "Platform fee", color: "var(--foreground)" },
        }}>
          <AreaChart accessibilityLayer data={performance.chart} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
            <defs>
              {keys.map(key => (
                <linearGradient key={key} id={gradientId + "-" + tab + "-" + key} x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor={"var(--color-" + key + ")"} stopOpacity={key === "invalid" ? 0.14 : 0.28} />
                  <stop offset="100%" stopColor={"var(--color-" + key + ")"} stopOpacity={0.02} />
                </linearGradient>
              ))}
            </defs>
            <CartesianGrid vertical={false} /><XAxis dataKey="label" tickLine={false} axisLine={false} minTickGap={32} tickMargin={12} />
            <YAxis width={56} tickLine={false} axisLine={false} allowDecimals={monetary} tickFormatter={value => monetary ? "$" + (Number(value) / 1_000_000).toLocaleString("en-US", { maximumFractionDigits: 2 }) : Intl.NumberFormat("en-US", { notation: "compact" }).format(value)} />
            <ChartTooltip content={<ChartTooltipContent valueFormatter={value => typeof value === "number" ? monetary ? money(value, performance.currency) : number(value) : value} labelFormatter={(_, payload) => {
              const timestamp = payload[0]?.payload?.date;
              return typeof timestamp === "string" ? date(timestamp) + (performance.period.interval === "hour" ? " · " + time(timestamp) : "") + " UTC" : "";
            }} />} />
            {keys.map(key => <Area key={key} dataKey={key} type="monotone" fill={"url(#" + gradientId + "-" + tab + "-" + key + ")"} fillOpacity={1} stroke={"var(--color-" + key + ")"} strokeWidth={2} strokeDasharray={key === "invalid" ? "5 5" : undefined} dot={performance.chart.length === 1} activeDot={{ r: 4 }} />)}
          </AreaChart>
        </ChartContainer>}
      <p className="mt-4 flex items-start gap-2 text-xs leading-5 text-muted-foreground"><Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{number(performance.metrics.pending_impressions)} impressions received in this period are still awaiting validation.</p>
    </TabsContent>)}
  </Tabs>;
}
