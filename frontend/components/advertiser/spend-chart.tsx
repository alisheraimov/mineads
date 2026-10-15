"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  Line,
  LineChart,
  XAxis,
  YAxis,
} from "recharts";
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
} from "@/components/ui/chart";
import { formatMicros, formatNumber } from "@/lib/advertiser-format";

export type ChartMetric = "spend" | "impressions" | "effective_cpm";
export const metricLabels: Record<ChartMetric, string> = {
  spend: "Spend",
  impressions: "Impressions",
  effective_cpm: "Effective CPM",
};

export function SpendChart({
  data,
  variant = "bar",
  metric = "spend",
}: {
  data: {
    date: string;
    label: string;
    spend_micros: number;
    impressions?: number;
    effective_cpm_micros?: number;
  }[];
  variant?: "bar" | "line";
  metric?: ChartMetric;
}) {
  const Chart = variant === "bar" ? BarChart : LineChart;
  return (
    <ChartContainer
      className="h-60 w-full aspect-auto"
      aria-label={`Daily ${metricLabels[metric].toLowerCase()}`}
      config={{
        value: { label: metricLabels[metric], color: "var(--foreground)" },
      }}
    >
      <Chart
        accessibilityLayer
        data={data.map((point) => ({
          ...point,
          value:
            metric === "impressions"
              ? (point.impressions ?? 0)
              : metric === "effective_cpm"
                ? (point.effective_cpm_micros ?? 0)
                : point.spend_micros,
        }))}
        margin={{ top: 12, right: 12, bottom: 0, left: 12 }}
      >
        <CartesianGrid vertical={false} />
        <XAxis
          dataKey="label"
          axisLine={false}
          tickLine={false}
          tickMargin={12}
          minTickGap={24}
        />
        <YAxis hide domain={[0, (max: number) => max || 1]} />
        <ChartTooltip
          cursor={
            variant === "bar" ? { fill: "var(--muted)", radius: 4 } : false
          }
          content={
            <ChartTooltipContent
              valueFormatter={(value) =>
                typeof value === "number"
                  ? metric === "impressions"
                    ? formatNumber(value)
                    : formatMicros(value)
                  : value
              }
              labelFormatter={(_, payload) =>
                String(payload[0]?.payload?.date ?? "")
              }
            />
          }
        />
        {variant === "bar" ? (
          <Bar
            key={metric}
            dataKey="value"
            fill="var(--color-value)"
            radius={[6, 6, 6, 6]}
            maxBarSize={38}
          />
        ) : (
          <Line
            key={metric}
            dataKey="value"
            type="monotone"
            stroke="var(--color-value)"
            strokeWidth={2}
            dot={false}
            activeDot={{ r: 4 }}
          />
        )}
      </Chart>
    </ChartContainer>
  );
}
