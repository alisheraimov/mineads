"use client";

import { FormEvent, useEffect, useState } from "react";
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from "recharts";
import { ChartContainer, ChartTooltip, ChartTooltipContent } from "@/components/ui/chart";
import { Button } from "@/components/ui/button";
import { DatePicker } from "@/components/ui/date-picker";
import { ServerPerformance, serverPerformance } from "@/lib/servers";
import { money, panelClass } from "@/components/publisher/server-shared";

function dateBefore(days: number) { const date = new Date(); date.setUTCDate(date.getUTCDate() - days); return date.toISOString().slice(0, 10); }

export function ServerPerformancePanel({ id, currency }: { id: number; currency: string }) {
  const [from, setFrom] = useState(() => dateBefore(29));
  const [to, setTo] = useState(() => dateBefore(0));
  const [group, setGroup] = useState("day");
  const [compare, setCompare] = useState(false);
  const [metric, setMetric] = useState<"earnings_micros" | "impressions" | "effective_cpm_micros">("earnings_micros");
  const [query, setQuery] = useState(() => ({ from: dateBefore(29), to: dateBefore(0), group: "day", compare: "0" }));
  const [report, setReport] = useState<ServerPerformance | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  useEffect(() => {
    let mounted = true;
    setLoading(true); setError("");
    serverPerformance(id, new URLSearchParams(query)).then(({ data }) => { if (mounted) setReport(data); })
      .catch((err) => { if (mounted) setError(err instanceof Error ? err.message : "Unable to load performance."); })
      .finally(() => { if (mounted) setLoading(false); });
    return () => { mounted = false; };
  }, [id, query]);
  function submit(event: FormEvent) { event.preventDefault(); setQuery({ from, to, group, compare: compare ? "1" : "0" }); }
  const formatValue = (value: number) => metric === "impressions" ? value.toLocaleString("en-US") : money(value, currency);
  const previousKey = `previous_${metric}` as const;

  return <section className={panelClass}>
    <h2 className="font-semibold">Server performance</h2>
    <form onSubmit={submit} className="mt-4 flex flex-wrap items-end gap-3">
      <div className="w-44 space-y-1"><label htmlFor="performance-from" className="text-xs">From (UTC)</label><DatePicker id="performance-from" label="Performance from date" value={from} onChange={setFrom} max={to} /></div>
      <div className="w-44 space-y-1"><label htmlFor="performance-to" className="text-xs">To (UTC)</label><DatePicker id="performance-to" label="Performance to date" value={to} onChange={setTo} min={from} max={dateBefore(0)} /></div>
      <Button type="submit" variant="outline" disabled={loading}>Apply dates</Button>
    </form>
    <div className="my-5 flex flex-wrap items-center justify-between gap-4">
      <div role="group" aria-label="Performance metric" className="flex gap-1">{([["earnings_micros", "Earnings"], ["impressions", "Impressions"], ["effective_cpm_micros", "CPM"]] as const).map(([value, label]) => <Button key={value} size="sm" variant={metric === value ? "default" : "ghost"} aria-pressed={metric === value} onClick={() => setMetric(value)}>{label}</Button>)}</div>
      <div className="flex flex-wrap items-center gap-3"><div role="group" aria-label="Chart grouping" className="flex gap-1">{["day", "week", "month"].map((value) => <Button key={value} size="sm" variant={group === value ? "default" : "ghost"} aria-pressed={group === value} onClick={() => { setGroup(value); setQuery((current) => ({ ...current, group: value })); }}>{value.charAt(0).toUpperCase() + value.slice(1)}</Button>)}</div><label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={compare} onChange={(event) => { setCompare(event.target.checked); setQuery((current) => ({ ...current, compare: event.target.checked ? "1" : "0" })); }} />Compare previous period</label></div>
    </div>
    {error && <p role="alert" className="my-3 text-sm text-destructive">{error}</p>}
    {loading && <p role="status" className="mb-3 text-xs text-muted-foreground">Loading performance…</p>}
    {report && <div aria-busy={loading}>
      {report.impressions === 0 && <p className="mb-4 text-sm text-muted-foreground">No validated impressions in this period. Earnings will appear when ads are delivered and validated.</p>}
      <ChartContainer className="h-64 w-full aspect-auto" aria-label="Server performance in UTC" config={{ value: { label: "Selected period", color: "var(--foreground)" }, previous: { label: "Previous period", color: "var(--muted-foreground)" } }}>
        <LineChart accessibilityLayer data={report.points.map((point) => ({ ...point, value: point[metric], previous: point[previousKey] }))} margin={{ top: 10, left: 8, right: 12 }}>
          <CartesianGrid vertical={false} /><XAxis dataKey="label" tickLine={false} axisLine={false} minTickGap={35} /><YAxis width={65} tickLine={false} axisLine={false} tickFormatter={formatValue} allowDecimals={metric !== "impressions"} domain={[0, (max: number) => max || (metric === "impressions" ? 1 : 1_000_000)]} />
          <ChartTooltip content={<ChartTooltipContent valueFormatter={(value) => typeof value === "number" ? formatValue(value) : value} labelFormatter={(_, payload) => `${payload[0]?.payload?.date ?? ""} · UTC`} />} />
          <Line dataKey="value" type="monotone" stroke="var(--color-value)" strokeWidth={2} dot={false} />
          {report.compare && <Line dataKey="previous" type="monotone" stroke="var(--color-previous)" strokeDasharray="5 5" dot={false} />}
        </LineChart>
      </ChartContainer>
      <p className="mt-4 text-xs text-muted-foreground">{money(report.earnings_micros, currency)} earnings · {report.impressions.toLocaleString("en-US")} validated impressions · {report.from}–{report.to} UTC. {report.compare && `Dashed line: ${report.previous_from}–${report.previous_to}. `}Current-period earnings are estimates until the billing period is finalized.</p>
    </div>}
  </section>;
}
