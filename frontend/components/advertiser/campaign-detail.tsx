"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { CircleAlert, Loader2, Pause, Play, Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { DateRangePicker } from "@/components/ui/date-picker";
import { Label } from "@/components/ui/label";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import {
  AlertDialog,
  AlertDialogContent,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogCancel,
  AlertDialogAction,
} from "@/components/ui/alert-dialog";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  CampaignDetail,
  getCampaign,
  setCampaignStatus,
} from "@/lib/campaigns";
import {
  displayCountry,
  displayLanguage,
  formatDate,
  formatMicros,
  formatNumber,
} from "@/lib/advertiser-format";
import {
  AccountDataTime,
  CampaignBreadcrumb,
  CampaignError,
  CampaignLoading,
  CampaignStatus,
  Properties,
} from "./campaign-shared";
import { ChartMetric, metricLabels, SpendChart } from "./spend-chart";
import { cn } from "@/lib/utils";

function dateRange(preset: string) {
  const end = new Date();
  if (preset === "yesterday") end.setUTCDate(end.getUTCDate() - 1);
  const start = new Date(end);
  if (preset === "7") start.setUTCDate(start.getUTCDate() - 6);
  if (preset === "30") start.setUTCDate(start.getUTCDate() - 29);
  return {
    from: start.toISOString().slice(0, 10),
    to: end.toISOString().slice(0, 10),
  };
}

export function CampaignDetailsPage({ id }: { id: string }) {
  const [data, setData] = useState<CampaignDetail | null>(null);
  const [preset, setPreset] = useState("7");
  const [metric, setMetric] = useState<ChartMetric>("spend");
  const [range, setRange] = useState(() => dateRange("7"));
  const [draftRange, setDraftRange] = useState(range);
  const [error, setError] = useState("");
  const [actionError, setActionError] = useState("");
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [endOpen, setEndOpen] = useState(false);
  const [reload, setReload] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError("");
    getCampaign(id, new URLSearchParams(range), controller.signal)
      .then((response) => setData(response.data))
      .catch((error) => {
        if (!controller.signal.aborted)
          setError(
            error instanceof Error ? error.message : "Unable to load campaign.",
          );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [id, range, reload]);

  async function changeStatus(status: string) {
    if (!data) return;
    setBusy(true);
    setActionError("");
    try {
      await setCampaignStatus(data.id, status);
      setEndOpen(false);
      setReload((value) => value + 1);
    } catch (error) {
      setActionError(
        error instanceof Error ? error.message : "Unable to update campaign.",
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mx-auto flex max-w-[1112px] flex-col gap-6">
      <CampaignBreadcrumb current={`CMP-${id}`} />
      {error ? (
        <CampaignError
          message={error}
          retry={() => setReload((value) => value + 1)}
        />
      ) : !data ? (
        <CampaignLoading />
      ) : (
        <>
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div className="min-w-0">
              <h1 className="break-words text-2xl font-semibold">
                {data.name}
              </h1>
              <p className="mt-2 text-sm text-muted-foreground">
                CMP-{data.id} · {data.account_name}
              </p>
            </div>
            {data.status !== "ended" && (
              <div className="flex flex-wrap gap-2">
                <Button asChild variant="outline">
                  <Link href={`/advertiser/campaigns/${id}/edit`}>Edit</Link>
                </Button>
                <Button
                  variant="outline"
                  disabled={busy || loading}
                  onClick={() =>
                    changeStatus(data.status === "active" ? "paused" : "active")
                  }
                >
                  {busy ? (
                    <Loader2 className="animate-spin" />
                  ) : data.status === "active" ? (
                    <Pause />
                  ) : (
                    <Play />
                  )}
                  {data.status === "active" ? "Pause" : "Resume"}
                </Button>
                <Button
                  variant="ghost"
                  disabled={busy}
                  onClick={() => {
                    setActionError("");
                    setEndOpen(true);
                  }}
                >
                  End campaign
                </Button>
              </div>
            )}
          </div>
          {actionError && !endOpen && <CampaignError message={actionError} />}
          <div className="flex flex-wrap gap-2">
            <CampaignStatus status={data.status} />
            <CampaignStatus
              status={data.delivery_eligible ? "eligible" : "not eligible"}
            />
          </div>
          {data.delivery_issues.length > 0 && (
            <Alert>
              <CircleAlert />
              <AlertTitle>Delivery blocked</AlertTitle>
              <AlertDescription>
                <ul className="list-inside list-disc space-y-1">
                  {data.delivery_issues.map((issue) => (
                    <li key={issue}>{issue}</li>
                  ))}
                </ul>
              </AlertDescription>
            </Alert>
          )}
          <div className="grid gap-4 md:grid-cols-2">
            <section className="space-y-5 rounded-lg border bg-card p-6">
              <h2 className="text-base font-medium">Campaign overview</h2>
              <Properties
                items={[
                  ["Campaign ID", `CMP-${data.id}`],
                  ["Starts", formatDate(data.starts_at, true)],
                  ["Ends", formatDate(data.ends_at, true)],
                ]}
              />
            </section>
            <section className="space-y-5 rounded-lg border bg-card p-6">
              <h2 className="text-base font-medium">Targeting</h2>
              <Properties
                items={[
                  [
                    "Countries",
                    data.countries.map(displayCountry).join(", ") ||
                      "All countries",
                  ],
                  [
                    "Languages",
                    data.languages.map(displayLanguage).join(", ") ||
                      "All languages",
                  ],
                  [
                    "Server categories",
                    <span key="categories" className="capitalize">
                      {data.server_categories.join(", ") || "All categories"}
                    </span>,
                  ],
                ]}
              />
            </section>
          </div>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <Tabs
              value={preset}
              onValueChange={(value) => {
                setPreset(value);
                if (value !== "custom") setRange(dateRange(value));
                else setDraftRange(range);
              }}
            >
              <TabsList className="flex h-auto flex-wrap">
                {[
                  ["today", "Today"],
                  ["yesterday", "Yesterday"],
                  ["7", "Last 7 days"],
                  ["30", "Last 30 days"],
                  ["custom", "Custom range"],
                ].map(([value, label]) => (
                  <TabsTrigger key={value} value={value}>
                    {label}
                  </TabsTrigger>
                ))}
              </TabsList>
            </Tabs>
            <span className="text-xs text-muted-foreground">
              {formatDate(range.from)} – {formatDate(range.to)}
            </span>
          </div>
          {preset === "custom" && (
            <form
              className="flex flex-wrap items-end gap-3"
              onSubmit={(event) => {
                event.preventDefault();
                if (draftRange.from && draftRange.to >= draftRange.from)
                  setRange(draftRange);
              }}
            >
              <div className="space-y-2">
                <Label htmlFor="campaign-range">Date range · UTC</Label>
                <DateRangePicker
                  id="campaign-range"
                  label="Date range · UTC"
                  value={draftRange}
                  onChange={setDraftRange}
                />
              </div>
              <Button
                type="submit"
                variant="outline"
                disabled={!draftRange.from || !draftRange.to}
              >
                Apply
              </Button>
            </form>
          )}
          <section
            className="overflow-hidden rounded-lg border bg-card"
            aria-busy={loading}
          >
            <div className="grid border-b sm:grid-cols-[2fr_1fr_1fr_1fr]">
              <div className="p-6">
                <h2 className="text-base font-medium">
                  {metric === "effective_cpm"
                    ? "Daily effective CPM"
                    : `Daily ${metricLabels[metric].toLowerCase()}`}
                </h2>
                <p className="mt-1 text-xs text-muted-foreground">
                  {formatDate(data.period.from)} – {formatDate(data.period.to)}{" "}
                  {metric !== "impressions" && "· USD"}
                </p>
              </div>
              {(["impressions", "spend", "effective_cpm"] as const).map(
                (key) => (
                  <button
                    type="button"
                    key={key}
                    aria-pressed={metric === key}
                    onClick={() => setMetric(key)}
                    className={cn(
                      "min-w-0 border-t p-4 text-left transition-colors hover:bg-muted/50 focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-offset-[-2px] sm:border-l sm:border-t-0 sm:p-6",
                      metric === key && "bg-muted/50",
                    )}
                  >
                    <span className="block text-xs text-muted-foreground">
                      {metricLabels[key]}
                    </span>
                    <span className="mt-1 block text-2xl font-semibold">
                      {key === "impressions"
                        ? formatNumber(data.metrics.impressions)
                        : formatMicros(
                            key === "spend"
                              ? data.metrics.spend_micros
                              : data.metrics.effective_cpm_micros,
                          )}
                    </span>
                  </button>
                ),
              )}
            </div>
            <div className="p-6">
              <SpendChart data={data.spend_chart} metric={metric} />
            </div>
          </section>
          <div className="grid gap-4 md:grid-cols-3">
            {[
              [
                "Daily budget / used today",
                `${formatMicros(data.daily_budget_micros)} / ${formatMicros(data.metrics.spend_today_micros)}`,
                data.daily_budget_micros === null
                  ? "No daily cap"
                  : `${formatMicros(Math.max(0, data.daily_budget_micros - data.metrics.spend_today_micros))} remaining today`,
              ],
              [
                "Total budget / used",
                `${formatMicros(data.total_budget_micros)} / ${formatMicros(data.metrics.spend_total_micros)}`,
                data.total_budget_micros === null
                  ? "No total cap"
                  : `${formatMicros(Math.max(0, data.total_budget_micros - data.metrics.spend_total_micros))} remaining in configured cap`,
              ],
              [
                "Spend this month",
                formatMicros(data.metrics.spend_month_micros),
                "Informational usage · no monthly cap",
              ],
            ].map(([label, value, caption]) => (
              <div key={label} className="rounded-lg bg-card p-6">
                <p className="text-sm text-muted-foreground">{label}</p>
                <p className="mt-2 break-words text-xl font-semibold">
                  {value}
                </p>
                <p className="mt-2 text-xs text-muted-foreground">{caption}</p>
              </div>
            ))}
          </div>
          <section className="space-y-5 rounded-lg border bg-card p-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div>
                <h2 className="text-base font-medium">Attached creatives</h2>
                <p className="mt-1 text-xs text-muted-foreground">
                  All-time delivery ·{" "}
                  {
                    data.creatives.filter((item) => item.status === "approved")
                      .length
                  }{" "}
                  approved creatives
                </p>
              </div>
              {data.status !== "ended" && (
                <span>
                  <Button asChild variant="outline">
                    <Link href={`/advertiser/creatives/new?campaign=${id}`}>
                      <Plus aria-hidden="true" />
                      Create creative
                    </Link>
                  </Button>
                </span>
              )}
            </div>
            <div className="overflow-hidden rounded-md border">
              <Table className="min-w-[680px] text-xs">
                <TableHeader className="bg-muted">
                  <TableRow>
                    {[
                      "Creative",
                      "Review status",
                      "Language",
                      "Impressions",
                      "Spend",
                      "Action",
                    ].map((label) => (
                      <TableHead key={label} className="text-xs">
                        {label}
                      </TableHead>
                    ))}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.creatives.length ? (
                    data.creatives.map((item) => (
                      <TableRow key={item.id}>
                        <TableCell className="max-w-64 whitespace-normal break-words py-4">
                          {item.name}
                        </TableCell>
                        <TableCell>
                          <CampaignStatus status={item.status} />
                          {item.is_paused && <CampaignStatus status="paused" />}
                        </TableCell>
                        <TableCell>
                          {item.language
                            ? displayLanguage(item.language)
                            : "All"}
                        </TableCell>
                        <TableCell>{formatNumber(item.impressions)}</TableCell>
                        <TableCell>{formatMicros(item.spend_micros)}</TableCell>
                        <TableCell>
                          <Button variant="ghost" size="sm" asChild>
                            <Link href={`/advertiser/creatives/${item.id}`}>
                              View
                            </Link>
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))
                  ) : (
                    <TableRow>
                      <TableCell
                        colSpan={6}
                        className="h-24 text-center text-muted-foreground"
                      >
                        No creatives attached yet.
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>
          </section>
          <AccountDataTime value={data.generated_at} />
          <AlertDialog
            open={endOpen}
            onOpenChange={(value) => {
              if (!busy) setEndOpen(value);
            }}
          >
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>End this campaign?</AlertDialogTitle>
                <AlertDialogDescription>
                  {data.name} will stop serving. An ended campaign cannot be
                  resumed.
                </AlertDialogDescription>
              </AlertDialogHeader>
              {actionError && <CampaignError message={actionError} />}
              <AlertDialogFooter>
                <AlertDialogCancel disabled={busy}>Cancel</AlertDialogCancel>
                <AlertDialogAction
                  disabled={busy}
                  onClick={(event) => {
                    event.preventDefault();
                    changeStatus("ended");
                  }}
                >
                  {busy && <Loader2 className="animate-spin" />}End campaign
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </>
      )}
    </div>
  );
}
