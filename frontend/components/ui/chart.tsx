"use client";

import * as React from "react";
import * as RechartsPrimitive from "recharts";

import { cn } from "@/lib/utils";

export type ChartConfig = Record<
  string,
  {
    label?: React.ReactNode;
    color?: string;
  }
>;

type ChartContextValue = {
  config: ChartConfig;
};

const ChartContext = React.createContext<ChartContextValue | null>(null);

function useChart() {
  const context = React.useContext(ChartContext);

  if (!context) {
    throw new Error("useChart must be used within a <ChartContainer />");
  }

  return context;
}

function ChartContainer({
  id,
  className,
  children,
  config,
  ...props
}: React.ComponentProps<"div"> & {
  config: ChartConfig;
  children: React.ComponentProps<typeof RechartsPrimitive.ResponsiveContainer>["children"];
}) {
  const uniqueId = React.useId();
  const chartId = `chart-${id || uniqueId.replace(/:/g, "")}`;

  return (
    <ChartContext.Provider value={{ config }}>
      <div
        data-chart={chartId}
        className={cn(
          "flex aspect-video justify-center text-xs",
          "[&_.recharts-cartesian-axis-tick_text]:fill-muted-foreground",
          "[&_.recharts-cartesian-grid_line]:stroke-border",
          "[&_.recharts-curve.recharts-tooltip-cursor]:stroke-border",
          "[&_.recharts-dot[stroke='#fff']]:stroke-transparent",
          "[&_.recharts-layer]:outline-none",
          "[&_.recharts-sector]:outline-none",
          "[&_.recharts-surface]:outline-none",
          className,
        )}
        {...props}
      >
        <ChartStyle id={chartId} config={config} />
        <RechartsPrimitive.ResponsiveContainer>{children}</RechartsPrimitive.ResponsiveContainer>
      </div>
    </ChartContext.Provider>
  );
}

function ChartStyle({ id, config }: { id: string; config: ChartConfig }) {
  const colorConfig = Object.entries(config).filter(([, itemConfig]) => itemConfig.color);

  if (!colorConfig.length) {
    return null;
  }

  return (
    <style
      dangerouslySetInnerHTML={{
        __html: `
[data-chart=${id}] {
${colorConfig.map(([key, itemConfig]) => `  --color-${key}: ${itemConfig.color};`).join("\n")}
}
`,
      }}
    />
  );
}

const ChartTooltip = RechartsPrimitive.Tooltip;

type TooltipPayloadItem = {
  color?: string;
  dataKey?: string | number;
  name?: string | number;
  value?: string | number | Array<string | number>;
  payload?: Record<string, unknown>;
};

function ChartTooltipContent({
  active,
  payload,
  label,
  className,
  valueFormatter,
  labelFormatter,
}: {
  active?: boolean;
  payload?: TooltipPayloadItem[];
  label?: string | number;
  className?: string;
  valueFormatter?: (value: TooltipPayloadItem["value"], item: TooltipPayloadItem) => React.ReactNode;
  labelFormatter?: (label: string | number | undefined, payload: TooltipPayloadItem[]) => React.ReactNode;
}) {
  const { config } = useChart();

  if (!active || !payload?.length) {
    return null;
  }

  return (
    <div
      className={cn(
        "grid min-w-32 gap-2 rounded-md border border-border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md",
        className,
      )}
    >
      <div className="font-medium">
        {labelFormatter ? labelFormatter(label, payload) : label}
      </div>
      <div className="grid gap-1.5">
        {payload.map((item) => {
          const key = String(item.dataKey || item.name || "");
          const itemConfig = config[key];

          return (
            <div key={key} className="flex items-center justify-between gap-4">
              <div className="flex items-center gap-2 text-muted-foreground">
                <span
                  className="size-2 rounded-full"
                  style={{ backgroundColor: item.color || itemConfig?.color || "var(--foreground)" }}
                />
                <span>{itemConfig?.label || item.name || key}</span>
              </div>
              <span className="font-mono font-medium text-foreground">
                {valueFormatter ? valueFormatter(item.value, item) : item.value}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

export { ChartContainer, ChartTooltip, ChartTooltipContent };
