"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { ChevronRight, Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { ServerMetric as SummaryMetric, money, panelClass } from "@/components/publisher/server-shared";
import { PayoutsData, PayoutStatus, getPayouts, payoutStatuses } from "@/lib/payouts";

const statusStyles: Record<PayoutStatus, string> = {
  pending: "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200",
  processing: "bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200",
  paid: "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200",
  failed: "bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200",
  cancelled: "bg-muted text-muted-foreground",
};
const titleCase = (value: string) => value.charAt(0).toUpperCase() + value.slice(1);
function dateLabel(value: string) {
  return new Date(value.length === 10 ? `${value}T00:00:00Z` : value).toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric", timeZone: "UTC" });
}
function windowLabel(start: string, end: string) {
  const first = new Date(`${start}T00:00:00Z`);
  const last = new Date(`${end}T00:00:00Z`);
  if (start.slice(0, 7) !== end.slice(0, 7)) return `${dateLabel(start)}–${dateLabel(end)}`;
  return `${first.toLocaleDateString("en-US", { month: "short", timeZone: "UTC" })} ${first.getUTCDate()}–${last.getUTCDate()}, ${last.getUTCFullYear()}`;
}

export function PayoutsPage() {
  const [data, setData] = useState<PayoutsData | null>(null);
  const [year, setYear] = useState("all");
  const [status, setStatus] = useState("all");
  const [page, setPage] = useState(1);
  const [attempt, setAttempt] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let mounted = true;
    const params = new URLSearchParams({ page: String(page) });
    if (year !== "all") params.set("year", year);
    if (status !== "all") params.set("status", status);
    setLoading(true);
    setError("");
    getPayouts(params).then(({ data }) => { if (mounted) setData(data); })
      .catch((err) => { if (mounted) setError(err instanceof Error ? err.message : "Unable to load payouts."); })
      .finally(() => { if (mounted) setLoading(false); });
    return () => { mounted = false; };
  }, [year, status, page, attempt]);

  return <>
    <nav aria-label="Breadcrumb" className="flex items-center gap-2 text-sm text-muted-foreground">
      <Link href="/publisher">Publisher</Link><ChevronRight className="size-3.5" aria-hidden="true" /><span aria-current="page">Payouts</span>
    </nav>
    <div><h1 className="text-2xl font-semibold">Payouts</h1><p className="mt-2 text-sm text-muted-foreground">Review eligible earnings, your payout method and payout history.</p></div>
    {error && <div role="alert" className="flex flex-wrap items-center gap-4 rounded-lg border border-destructive/30 p-4"><p className="text-sm text-destructive">{error}</p><Button variant="outline" onClick={() => setAttempt((value) => value + 1)}>Try again</Button></div>}
    {loading && <p role="status" className="flex items-center gap-2 text-sm text-muted-foreground"><Loader2 className="size-4 animate-spin" aria-hidden="true" />Loading payouts…</p>}
    {data && <>
      <section aria-label="Payout summary" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <SummaryMetric label="Available for payout" value={money(data.metrics.available_payout_micros, data.account.currency)} caption="Closed billing periods · Eligible unpaid earnings" />
        <SummaryMetric label="Pending earnings" value={money(data.metrics.pending_earnings_micros, data.account.currency)} caption="Current calendar month estimate" />
        <SummaryMetric label="Next payout window" value={windowLabel(data.policy.estimated_window_start, data.policy.estimated_window_end)} caption={`Estimated · If ${money(data.policy.minimum_payout_micros, data.account.currency)} minimum is met`} />
        <SummaryMetric label="Lifetime paid" value={money(data.metrics.lifetime_paid_micros, data.account.currency)} caption="All completed payouts" />
      </section>
      <p className="text-xs leading-5 text-muted-foreground">
        Minimum payout: {money(data.policy.minimum_payout_micros, data.account.currency)}. Payouts are arranged after a {data.policy.hold_days_min}–{data.policy.hold_days_max} day hold following month-end. {data.policy.threshold_met ? "Your eligible balance meets the minimum." : "Your eligible balance is below the minimum."} Processing windows are estimates and depend on finalized earnings and payout eligibility. All dates are in UTC.
      </p>
      <section className={panelClass} aria-labelledby="payout-method-title">
        <h2 id="payout-method-title" className="font-semibold">Payout method</h2>
        <div className="my-6 flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-3"><h3 className="font-medium">Stripe Connect</h3><Badge variant="secondary">Not connected</Badge></div>
          <Button variant="outline" disabled title="Stripe Connect integration is coming soon">Connect Stripe</Button>
        </div>
        <dl className="grid gap-5 text-sm sm:grid-cols-3">
          <div><dt className="text-xs text-muted-foreground">Connected account</dt><dd className="mt-2">—</dd></div>
          <div><dt className="text-xs text-muted-foreground">Payout currency</dt><dd className="mt-2">{data.account.currency}</dd></div>
          <div><dt className="text-xs text-muted-foreground">Connection status</dt><dd className="mt-2">Stripe setup is not available yet</dd></div>
        </dl>
      </section>
      <section className="space-y-4" aria-labelledby="payout-history-title">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <h2 id="payout-history-title" className="font-semibold">Payout history</h2>
          <div className="flex flex-wrap gap-2">
            <Select value={year} onValueChange={(value) => { setYear(value); setPage(1); }}>
              <SelectTrigger aria-label="Filter payouts by period year" className="min-w-28"><SelectValue /></SelectTrigger>
              <SelectContent position="popper" align="end"><SelectGroup><SelectItem value="all">All years</SelectItem>{data.filter_years.map((value) => <SelectItem key={value} value={String(value)}>{value}</SelectItem>)}</SelectGroup></SelectContent>
            </Select>
            <Select value={status} onValueChange={(value) => { setStatus(value); setPage(1); }}>
              <SelectTrigger aria-label="Filter payouts by status" className="min-w-36"><SelectValue /></SelectTrigger>
              <SelectContent position="popper" align="end"><SelectGroup><SelectItem value="all">All statuses</SelectItem>{payoutStatuses.map((value) => <SelectItem key={value} value={value}>{titleCase(value)}</SelectItem>)}</SelectGroup></SelectContent>
            </Select>
          </div>
        </div>
        <div className="overflow-x-auto rounded-lg border bg-card" aria-busy={loading}>
          <table className="w-full min-w-[780px] text-left text-xs">
            <thead className="border-b bg-muted/40 text-muted-foreground"><tr>{["Period", "Amount (net)", "Status", "Method", "Created", "Paid"].map((label) => <th key={label} scope="col" className="px-4 py-4 font-medium">{label}</th>)}</tr></thead>
            <tbody>{data.history.map((payout) => <tr key={payout.id} className="border-b last:border-0">
              <td className="px-4 py-4">{dateLabel(payout.period_start)}–{dateLabel(payout.period_end)}</td>
              <td className="whitespace-nowrap px-4 py-4 tabular-nums">{money(payout.amount_micros, payout.currency)}</td>
              <td className="px-4 py-4"><Badge variant="secondary" className={statusStyles[payout.status]}>{titleCase(payout.status)}</Badge></td>
              <td className="px-4 py-4">{titleCase(payout.provider)}</td><td className="whitespace-nowrap px-4 py-4">{dateLabel(payout.created_at)}</td><td className="whitespace-nowrap px-4 py-4">{payout.paid_at ? dateLabel(payout.paid_at) : <span aria-label="Not paid">—</span>}</td>
            </tr>)}{data.history.length === 0 && <tr><td colSpan={6} className="px-6 py-12 text-center text-sm text-muted-foreground">{year === "all" && status === "all" ? "No payouts yet. Your payout history will appear here once payouts are arranged." : "No payouts match these filters."}</td></tr>}</tbody>
          </table>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-4 text-xs text-muted-foreground">
          <p>{data.pagination.total} {data.pagination.total === 1 ? "payout" : "payouts"} · Net amounts in {data.account.currency} · Unpaid payouts have no paid date</p><p>As of {dateLabel(data.generated_at)}</p>
        </div>
        {data.pagination.last_page > 1 && <nav aria-label="Payout history pages" className="flex items-center justify-end gap-3 text-sm"><Button variant="outline" disabled={loading || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</Button><span>Page {page} of {data.pagination.last_page}</span><Button variant="outline" disabled={loading || page >= data.pagination.last_page} onClick={() => setPage((value) => value + 1)}>Next</Button></nav>}
      </section>
    </>}
  </>;
}
