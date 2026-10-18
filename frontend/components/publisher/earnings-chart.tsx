"use client";

import { useState } from "react";
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from "recharts";
import { ChartContainer, ChartTooltip, ChartTooltipContent } from "@/components/ui/chart";
import type { PublisherDashboard } from "@/lib/publisher";
import { cn } from "@/lib/utils";

export function EarningsChart({ dashboard }: { dashboard: PublisherDashboard }) {
  const [metric, setMetric] = useState<"earnings" | "impressions">("earnings");
  const money = (micros: number) => new Intl.NumberFormat("en-US", { style: "currency", currency: dashboard.account.currency }).format(micros / 1_000_000);
  const data = dashboard.performance_chart;
  const totals = {
    earnings: money(data.reduce((total, point) => total + point.earnings_micros, 0)),
    impressions: data.reduce((total, point) => total + point.impressions, 0).toLocaleString("en-US"),
  };
  const label = metric === "earnings" ? "Earnings" : "Validated impressions";
  const hasData = data.some((point) => point.impressions > 0);

  return (
    <section className="overflow-hidden rounded-xl border bg-card" aria-label="Performance over the last 30 days">
      <div className="flex flex-col border-b sm:flex-row sm:items-stretch">
        <div className="flex-1 p-6">
          <h2 className="text-base font-semibold">Daily {metric}</h2>
          <p className="mt-1 text-xs text-muted-foreground">Last 30 days · {dashboard.account.currency} · UTC</p>
        </div>
        <div role="group" aria-label="Chart metric" className="grid grid-cols-2 border-t sm:border-t-0">
          {(["earnings", "impressions"] as const).map((item) => (
            <button key={item} type="button" aria-pressed={metric === item} onClick={() => setMetric(item)} className={cn("min-w-0 border-l px-6 py-5 text-left focus-visible:outline-2 focus-visible:-outline-offset-4 focus-visible:outline-ring sm:min-w-40", metric === item && "bg-foreground text-background")}>
              <span className={cn("block text-xs", metric === item ? "text-background/80" : "text-muted-foreground")}>{item === "earnings" ? "Earnings" : "Impressions"}</span>
              <span className="mt-1 block text-2xl font-semibold tabular-nums">{totals[item]}</span>
            </button>
          ))}
        </div>
      </div>
      <div id="publisher-performance-chart" className="p-6">
        {!hasData && <p className="mb-4 text-sm text-muted-foreground">No validated impressions in the last 30 days. Earnings will appear as ads are delivered and validated.</p>}
        <ChartContainer className="h-60 w-full aspect-auto" aria-label={`Daily ${label.toLowerCase()}, last 30 days in UTC`} config={{ value: { label, color: "var(--foreground)" } }}>
          <LineChart accessibilityLayer data={data.map((point) => ({ ...point, value: metric === "earnings" ? point.earnings_micros : point.impressions }))} margin={{ top: 12, right: 12, left: 12 }}>
            <CartesianGrid vertical={false} />
            <XAxis dataKey="label" axisLine={false} tickLine={false} tickMargin={12} minTickGap={24} />
            <YAxis hide domain={[0, (max: number) => max || 1]} />
            <ChartTooltip cursor={false} content={<ChartTooltipContent valueFormatter={(value) => typeof value === "number" ? metric === "earnings" ? money(value) : value.toLocaleString("en-US") : value} labelFormatter={(_, payload) => `${payload[0]?.payload?.date ?? ""} · UTC`} />} />
            <Line key={metric} dataKey="value" type="monotone" stroke="var(--color-value)" strokeWidth={2} dot={false} activeDot={{ r: 4 }} />
          </LineChart>
        </ChartContainer>
      </div>
    </section>
  );
}
