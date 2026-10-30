"use client";

import { useId, useState } from "react";
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from "recharts";
import { ChartContainer, ChartTooltip, ChartTooltipContent } from "@/components/ui/chart";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { formatMicros } from "@/lib/advertiser-format";
import { userDate, userDateTime } from "@/lib/admin-users";
import type { CreativeDetail } from "@/lib/admin-creatives";
import { UserPanel } from "./user-shared";

export function CreativePerformance({ data }: { data: CreativeDetail }) {
  const gradient = useId().replaceAll(":", "");
  const [metric, setMetric] = useState("impressions");
  const selectedMetric = !data.can_view_money ? "impressions" : metric;
  const money = selectedMetric !== "impressions";
  const label = selectedMetric === "impressions" ? "Validated impressions" : selectedMetric === "spend_micros" ? "Spend" : "Average CPM";
  return <UserPanel title="Performance over time"><Tabs value={selectedMetric} onValueChange={setMetric}><TabsList className="h-auto max-w-full flex-wrap"><TabsTrigger value="impressions">Impressions</TabsTrigger>{data.can_view_money && <><TabsTrigger value="spend_micros">Spend</TabsTrigger><TabsTrigger value="effective_cpm_micros">Average CPM</TabsTrigger></>}</TabsList></Tabs>
    <p className="text-xs text-muted-foreground">Daily {label.toLowerCase()} · {userDate(data.period.from)} – {userDate(data.period.to)} · UTC</p>
    {data.metrics.validated_impressions === 0 ? <div className="grid h-60 place-items-center text-center"><div><p className="text-sm font-medium">No validated activity in this period</p><p className="mt-2 text-xs text-muted-foreground">{data.status === "pending_review" ? "Pending creatives cannot serve until approved." : "Choose another reporting period to inspect earlier activity."}</p></div></div> :
      <ChartContainer className="h-64 w-full aspect-auto" aria-label={label + " over selected period"} config={{ value: { label, color: "var(--foreground)" } }}><AreaChart accessibilityLayer data={data.points.map(point => ({ date: point.date, value: selectedMetric === "impressions" ? point.impressions : selectedMetric === "spend_micros" ? point.spend_micros : point.effective_cpm_micros }))} margin={{ top: 12, right: 8, left: 0, bottom: 0 }}><defs><linearGradient id={gradient} x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="var(--foreground)" stopOpacity={0.22} /><stop offset="100%" stopColor="var(--foreground)" stopOpacity={0.02} /></linearGradient></defs><CartesianGrid vertical={false} /><XAxis dataKey="date" tickLine={false} axisLine={false} minTickGap={32} tickMargin={12} tickFormatter={value => new Date(value).toLocaleDateString("en-US", { month: "short", day: "numeric", timeZone: "UTC" })} /><YAxis width={64} allowDecimals={money} tickLine={false} axisLine={false} tickFormatter={value => money ? "$" + (Number(value) / 1_000_000).toLocaleString("en-US", { maximumFractionDigits: 2 }) : Number(value).toLocaleString()} /><ChartTooltip content={<ChartTooltipContent labelFormatter={(_, payload) => { const date = payload[0]?.payload?.date; return typeof date === "string" ? userDate(date) + " · UTC" : ""; }} valueFormatter={value => typeof value === "number" ? money ? formatMicros(value) : value.toLocaleString() : value} />} /><Area dataKey="value" type="monotone" stroke="var(--foreground)" strokeWidth={2} fill={"url(#" + gradient + ")"} dot={data.points.length === 1} activeDot={{ r: 4 }} connectNulls={false} /></AreaChart></ChartContainer>}
    <p className="text-xs leading-5 text-muted-foreground">Impressions{data.can_view_money ? ", spend and average CPM" : ""} follow validation time in UTC. Zero-activity days are included. Average CPM is unavailable when there are no validated impressions.</p>
  </UserPanel>;
}
export function CreativeMetrics({ data }: { data: CreativeDetail }) {
  const m = data.metrics;
  const cards = [
    { label: "Validated impressions", value: m.validated_impressions.toLocaleString() },
    ...(data.can_view_money ? [{ label: "Advertiser spend", value: formatMicros(m.spend_micros ?? 0) }] : []),
    { label: "Last served", value: m.last_served_at ? userDateTime(m.last_served_at) : "Not served in period" },
    { label: "Invalid impression rate", value: m.invalid_rate == null ? "—" : m.invalid_rate + "%", caption: m.invalid_rate == null ? "No processed impressions" : "Invalid / processed impressions" },
  ];
  return <div className={"grid gap-4 sm:grid-cols-2 " + (data.can_view_money ? "xl:grid-cols-4" : "xl:grid-cols-3")}>{cards.map(card => <div key={card.label} className="min-h-[122px] rounded-lg border bg-card p-5"><p className="text-xs text-muted-foreground">{card.label}</p><p className={"mt-3 font-semibold tabular-nums " + (card.label === "Last served" ? "text-lg" : "text-2xl")}>{card.value}</p>{card.caption && <p className="mt-2 text-xs text-muted-foreground">{card.caption}</p>}</div>)}</div>;
}

