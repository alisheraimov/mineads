import type { ClickMetrics } from "@/lib/clicks";

export function ClickMetricsSummary({ metrics }: { metrics: ClickMetrics }) {
  return (
    <div className="p-5">
      <dl className="grid gap-5 sm:grid-cols-3">
        <div>
          <dt className="summary-label">Unique clicks</dt>
          <dd className="summary-value mt-3">{metrics.clicks.toLocaleString()}</dd>
        </div>
        <div>
          <dt className="summary-label">Raw link opens</dt>
          <dd className="summary-value mt-3">{metrics.raw_clicks.toLocaleString()}</dd>
        </div>
        <div>
          <dt className="summary-label">CTR</dt>
          <dd className="summary-value mt-3">
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
