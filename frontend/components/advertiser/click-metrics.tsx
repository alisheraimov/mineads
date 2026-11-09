import type { ClickMetrics } from "@/lib/clicks";

export function ClickMetricsSummary({ metrics }: { metrics: ClickMetrics }) {
  return (
    <div className="p-5">
      <dl className="grid grid-cols-3 gap-4 text-sm">
        <div>
          <dt className="text-xs text-muted-foreground">Unique clicks</dt>
          <dd className="mt-1 font-semibold">{metrics.clicks.toLocaleString()}</dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">Raw link opens</dt>
          <dd className="mt-1 font-semibold">{metrics.raw_clicks.toLocaleString()}</dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">CTR</dt>
          <dd className="mt-1 font-semibold">
            {metrics.ctr == null ? "—" : `${metrics.ctr.toFixed(2)}%`}
          </dd>
        </div>
      </dl>
      <p className="mt-3 text-xs text-muted-foreground">
        Unique clicks estimate visitors per ad delivery, excluding repeats and
        recognized automated opens. CTR is unique clicks ÷ validated impressions.
        Dates use link-open time in UTC; clicks do not affect billing.
      </p>
    </div>
  );
}
