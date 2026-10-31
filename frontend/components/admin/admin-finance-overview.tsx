"use client";

import { useCallback, useState } from "react";
import Link from "next/link";
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from "recharts";
import { ChartContainer, ChartTooltip, ChartTooltipContent } from "@/components/ui/chart";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Checkbox } from "@/components/ui/checkbox";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { getFinanceOverview, financeLabel, type FinanceMetric } from "@/lib/admin-finance";
import { formatMicros } from "@/lib/advertiser-format";
import { userDate, userDateTime } from "@/lib/admin-users";
import { useAdminQuery } from "./use-admin-query";
import { useServerFilters } from "./admin-server-shared";
import { FinanceHeader, FinanceMetric as Metric, FinanceStatus } from "./finance-shared";
import { CreativePeriod } from "./creative-period";
import { UserPanel, UserSelect, UsersError } from "./user-shared";
import { CampaignLoading } from "@/components/advertiser/campaign-shared";

const periods = [{ value: "month", label: "This month" }, { value: "today", label: "Today" }, { value: "yesterday", label: "Yesterday" }, { value: "last_week", label: "Last week" }, { value: "last_month", label: "Last month" }, { value: "custom", label: "Custom range" }];
const labels = { spend_micros: "Advertiser spend", earnings_micros: "Publisher earnings", revenue_micros: "Platform revenue" };
export function AdminFinanceOverview() {
 const { params, query, filter, router, pathname } = useServerFilters();
 const [metric, setMetric] = useState<FinanceMetric>("spend_micros");
 const loader = useCallback((signal: AbortSignal) => getFinanceOverview(query, signal), [query]);
 const { data: result, error, loading, refresh } = useAdminQuery(loader, query);
 const data = error ? undefined : result?.data;
 const compare = params.get("compare") === "1";
 const previous = ("previous_" + metric) as `previous_${FinanceMetric}`;
 const period = data ? { from: data.period.from, to: new Date(Math.max(Date.parse(data.period.from), Date.parse(data.period.to) - 1)).toISOString() } : undefined;
 const range = period ? new URLSearchParams({ from: period.from.slice(0,10), to: period.to.slice(0,10) }).toString() : "";
 const hasActivity = data?.points.some(p => p[metric] !== 0 || compare && p[previous] !== 0);
 return <div className="space-y-6"><FinanceHeader title="Finance overview" description="Monitor financial performance, balances, and payout workload." loading={loading} refresh={refresh} />
 <CreativePeriod value={params.get("period") ?? "month"} period={period} options={periods} onChange={(value, range) => { const next = new URLSearchParams(query); next.set("period", value); next.delete("from"); next.delete("to"); if (range) { next.set("from", range.from); next.set("to", range.to); } router.replace(pathname + "?" + next, { scroll: false }); }} />
 {error && <UsersError message={error} retry={refresh} />}
 {!data ? !error && <CampaignLoading label="Loading financial totals" /> : <>
 <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
 <Metric label="Advertiser spend · USD" value={formatMicros(data.metrics.spend_micros)} help="Charges for impressions validated in this reporting period. Excludes pending and invalid impressions." href={"/admin/finance/advertiser-ledger?" + range + "&type=spend"} />
 <Metric label="Publisher earnings · USD" value={formatMicros(data.metrics.earnings_micros)} help="Earnings from those same validated impressions, including earnings awaiting release." href={"/admin/finance/publisher-ledger?" + range} />
 <Metric label="Platform revenue · USD" value={formatMicros(data.metrics.revenue_micros)} help="Platform fees on those same impressions, before operating and payment costs." />
 <Metric label="Billable impressions" value={data.metrics.impressions.toLocaleString()} help="Validated, billable impressions by validation timestamp in UTC." />
 <Metric label="Payouts paid · USD" value={formatMicros(data.metrics.paid_micros)} help="Payouts currently Paid whose actual payment date falls in this period." href={"/admin/finance/payouts?" + range + "&status=paid&date_field=paid"} />
 </div>
 <p className="text-xs text-muted-foreground">Advertising metrics follow validation date. Payouts paid follows actual payment date. Updated {userDateTime(data.generated_at)}.</p>
 <UserPanel title="Financial history" description="USD · Validation date · UTC">
 <div className="flex flex-wrap items-center justify-between gap-3"><Tabs value={metric} onValueChange={value => setMetric(value as FinanceMetric)}><TabsList className="h-auto flex-wrap">{Object.entries(labels).map(([key, label]) => <TabsTrigger key={key} value={key}>{label}</TabsTrigger>)}</TabsList></Tabs><div className="flex flex-wrap items-center gap-4"><UserSelect label="Chart interval" value={params.get("interval") ?? "day"} options={["day", "week", "month"].map(value => ({ value, label: financeLabel(value) }))} onChange={value => filter("interval", value)} /><label className="flex cursor-pointer items-center gap-2 text-sm"><Checkbox checked={compare} onCheckedChange={v => filter("compare", v ? "1" : "")} />Compare previous period</label></div></div>
 {!hasActivity ? <div className="grid h-64 place-items-center text-center"><div><p className="text-sm font-medium">No financial activity in {compare ? "either period" : "this period"}</p><p className="mt-2 text-xs text-muted-foreground">Choose another date range to inspect earlier activity.</p></div></div> :
 <ChartContainer className="h-72 w-full aspect-auto" aria-label={labels[metric] + " over time"} config={{ [metric]: { label: labels[metric], color: "var(--foreground)" }, [previous]: { label: "Previous period", color: "var(--muted-foreground)" } }}><LineChart accessibilityLayer data={data.points} margin={{ top: 12, right: 12, bottom: 0, left: 0 }}><CartesianGrid vertical={false} /><XAxis dataKey="date" tickLine={false} axisLine={false} minTickGap={40} tickMargin={12} tickFormatter={v => userDate(String(v)).replace(/, \d{4}/, "")} /><YAxis tickLine={false} axisLine={false} width={70} tickFormatter={v => formatMicros(Number(v))} /><ChartTooltip content={<ChartTooltipContent labelFormatter={(_, payload) => { const p = payload[0]?.payload; if (!p) return ""; const current = Number(p[metric]), old = Number(p[previous]); const change = old === 0 ? current === 0 ? "0%" : "N/A" : ((current-old)/old*100).toFixed(1) + "%"; return <div className="space-y-1"><p>{userDate(String(p.date))} – {userDate(new Date(Date.parse(String(p.to)) - 1).toISOString())} · UTC</p>{compare && <><p className="text-muted-foreground">Previous: {userDate(String(p.previous_from))} – {userDate(new Date(Date.parse(String(p.previous_to)) - 1).toISOString())}</p><p>Change: {change}</p></>}</div>; }} valueFormatter={value => typeof value === "number" ? formatMicros(value) : value} />} /><Line dataKey={metric} type="monotone" stroke="var(--foreground)" strokeWidth={2} dot={data.points.length === 1} />{compare && <Line dataKey={previous} type="monotone" stroke="var(--muted-foreground)" strokeDasharray="5 5" strokeWidth={2} dot={data.points.length === 1} />}</LineChart></ChartContainer>}
 {compare && <p className="text-xs text-muted-foreground">Solid: current period · Dashed: immediately preceding equal-duration period. Hover for exact dates and percentage change.</p>}
 </UserPanel>
 <section className="space-y-4"><div><h2 className="text-base font-semibold">Current balances</h2><p className="mt-1 text-xs text-muted-foreground">As of {userDateTime(data.generated_at)} · Unaffected by the reporting period.</p></div><div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
 <Metric label="Advertiser funds · USD" value={formatMicros(data.balances.advertiser_funds_micros)} help="All signed advertiser ledger amounts. Deposits increase funds and spend reduces them. These customer funds are not platform revenue." href="/admin/finance/advertiser-ledger" />
 <Metric label="Awaiting release · USD" value={formatMicros(data.balances.awaiting_micros)} help="Current pending_earning amounts, including earnings held on suspended servers. This refers to settlement eligibility, not impression validation." href="/admin/finance/publisher-ledger?type=pending_earning" caption={data.balances.held_micros ? formatMicros(data.balances.held_micros) + " held on suspended servers" : undefined} />
 <Metric label="Available for payout · USD" value={formatMicros(data.balances.available_micros)} help="Per publisher: released earnings minus Pending and Paid payouts. Minimum thresholds and account restrictions may prevent payment. Negative balances are flagged separately." href="/admin/finance/publisher-ledger?type=payable" />
 <Metric label="Reserved for payout · USD" value={formatMicros(data.balances.reserved_micros)} help="Funds committed to Pending payouts. Failed and Cancelled payouts release their reservation." href="/admin/finance/payouts?status=pending" />
 </div>{data.balances.inconsistent_count > 0 && <div role="alert" className="space-y-2 rounded-lg border border-destructive/30 bg-card p-4 text-sm"><p className="font-medium">Balance inconsistency · {data.balances.inconsistent_count} publisher(s)</p><p className="text-muted-foreground">Negative available balances are excluded from the positive total above. Further reservations are blocked for affected publishers.</p>{data.balances.inconsistencies.map(p => <p key={p.user_id}><Link className="underline" href={"/admin/finance/publisher-ledger?account=" + p.user_id}>Publisher U-{p.user_id}</Link>: {formatMicros(p.available_micros)}</p>)}</div>}</section>
 <section className="space-y-4"><div><h2 className="text-base font-semibold">Current payout workload</h2><p className="mt-1 text-xs text-muted-foreground">All time · Current status · Failed amounts are not reserved.</p></div><div className="overflow-hidden rounded-lg border bg-card"><Table className="[&_tr>:first-child]:pl-5 [&_tr>:last-child]:pr-5"><TableHeader className="bg-muted"><TableRow><TableHead>Status</TableHead><TableHead>Payout count</TableHead><TableHead>Total · USD</TableHead><TableHead>Action</TableHead></TableRow></TableHeader><TableBody>{data.workload.map(w => <TableRow key={w.status}><TableCell className="py-4"><FinanceStatus value={w.status} /></TableCell><TableCell>{w.count.toLocaleString()}</TableCell><TableCell>{formatMicros(w.amount_micros)}</TableCell><TableCell><Button asChild variant="outline" size="sm"><Link href={"/admin/finance/payouts?status=" + w.status}>View payouts</Link></Button></TableCell></TableRow>)}</TableBody></Table></div></section>
 <p className="text-xs text-muted-foreground">Pending payouts are prepared automatically after settlement. Payments happen outside MineAds; Finance records their outcomes.</p>
 </>}
 </div>;
}
