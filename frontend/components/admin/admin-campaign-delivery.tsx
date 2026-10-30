"use client";

import { useId, useState } from "react";
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from "recharts";
import { ChartContainer, ChartTooltip, ChartTooltipContent } from "@/components/ui/chart";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { formatMicros } from "@/lib/advertiser-format";
import { userDate, userDateTime } from "@/lib/admin-users";
import { type AdminCampaignDetail } from "@/lib/admin-campaigns";
import { UserPanel } from "./user-shared";

export function AdminCampaignDelivery({ campaign }: { campaign: AdminCampaignDetail }) {
  const gradient = useId().replaceAll(":", "");
  const [metric, setMetric] = useState("impressions");
  const money = metric !== "impressions";
  const metrics = campaign.metrics;
  const points = campaign.performance.points;
  const valueLabel = metric === "impressions" ? "Validated impressions" : metric === "spend_micros" ? "Spend" : "Effective CPM";
  const last = metrics.last_delivery_at;
  const cards = [
    { label: "Reported impressions", value: metrics.reported_impressions.toLocaleString() },
    { label: "Validated impressions", value: metrics.validated_impressions.toLocaleString() },
    { label: "Invalid impressions", value: metrics.invalid_impressions.toLocaleString(), caption: metrics.invalid_rate + "% of processed" },
    { label: "Pending validation", value: metrics.pending_impressions.toLocaleString() },
    { label: "Served deliveries", value: metrics.served_deliveries.toLocaleString() },
    ...(campaign.can_view_money ? [{ label: "Advertiser spend", value: formatMicros(metrics.spend_micros ?? 0), caption: "Validated impressions" }, { label: "Effective CPM", value: metrics.effective_cpm_micros == null ? "—" : formatMicros(metrics.effective_cpm_micros), caption: "Spend / validated × 1,000" }] : []),
    { label: "Last served delivery", value: last ? new Date(last).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit", timeZone: "UTC" }) + " UTC" : "Not served yet", caption: last ? userDate(last) : "" },
  ];
  return <div className="space-y-6"><div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-base font-semibold">Delivery performance</h2><p className="text-xs text-muted-foreground">Lifetime totals · UTC</p></div><div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{cards.map(card => <div key={card.label} className="min-h-[122px] rounded-lg border bg-card p-5"><p className="text-xs text-muted-foreground">{card.label}</p><p className="mt-3 text-2xl font-semibold tabular-nums">{card.value}</p>{card.caption && <p className="mt-2 text-xs text-muted-foreground">{card.caption}</p>}</div>)}</div>
    <UserPanel title="Performance over time"><Tabs value={metric} onValueChange={setMetric}><TabsList className="h-auto max-w-full flex-wrap"><TabsTrigger value="impressions">Impressions</TabsTrigger>{campaign.can_view_money && <><TabsTrigger value="spend_micros">Spend</TabsTrigger><TabsTrigger value="effective_cpm_micros">eCPM</TabsTrigger></>}</TabsList></Tabs><p className="text-xs text-muted-foreground">{campaign.performance.interval === "day" ? "Daily" : "Monthly"} {valueLabel.toLowerCase()} · campaign lifetime · UTC</p>
      {metrics.validated_impressions === 0 ? <div className="grid h-60 place-items-center text-center"><div><p className="text-sm font-medium">No validated delivery activity yet</p><p className="mt-1 text-xs text-muted-foreground">Reported impressions may still be awaiting validation.</p></div></div> : <ChartContainer className="h-64 w-full aspect-auto" aria-label={valueLabel + " over campaign lifetime"} config={{ value: { label: valueLabel, color: "var(--foreground)" } }}><AreaChart accessibilityLayer data={points.map(point => ({ date: point.date, value: metric === "impressions" ? point.impressions : metric === "spend_micros" ? point.spend_micros : point.effective_cpm_micros }))} margin={{ top: 12, right: 8, left: 0, bottom: 0 }}><defs><linearGradient id={gradient} x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="var(--foreground)" stopOpacity={0.22} /><stop offset="100%" stopColor="var(--foreground)" stopOpacity={0.02} /></linearGradient></defs><CartesianGrid vertical={false} /><XAxis dataKey="date" tickFormatter={date => new Date(date).toLocaleDateString("en-US", { month: "short", ...(campaign.performance.interval === "day" ? { day: "numeric" } : { year: "2-digit" }), timeZone: "UTC" })} tickLine={false} axisLine={false} minTickGap={32} tickMargin={12} /><YAxis width={64} allowDecimals={money} tickLine={false} axisLine={false} tickFormatter={value => money ? "$" + (Number(value) / 1_000_000).toLocaleString("en-US", { maximumFractionDigits: 2 }) : Number(value).toLocaleString()} /><ChartTooltip content={<ChartTooltipContent labelFormatter={(_, payload) => { const date = payload[0]?.payload?.date; return typeof date === "string" ? userDateTime(date).replace(" · 00:00 UTC", " UTC") : ""; }} valueFormatter={value => typeof value === "number" ? money ? formatMicros(value) : value.toLocaleString() : value} />} /><Area dataKey="value" type="monotone" stroke="var(--foreground)" strokeWidth={2} fill={"url(#" + gradient + ")"} dot={points.length === 1} activeDot={{ r: 4 }} connectNulls={false} /></AreaChart></ChartContainer>}
      <p className="text-xs leading-5 text-muted-foreground">{metrics.pending_impressions.toLocaleString()} reported impressions are still awaiting validation. Financial totals follow validation time; reported impressions follow receipt time.</p>
    </UserPanel><p className="text-xs leading-5 text-muted-foreground">Served deliveries count ad decisions; reported and validated impressions count player impressions. One delivery can produce multiple impressions.</p>
  </div>;
}
