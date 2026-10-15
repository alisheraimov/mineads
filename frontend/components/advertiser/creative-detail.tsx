"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Loader2, Pause, Play, Pencil, Trash2 } from "lucide-react";
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
import { CreativeDetail, creativeRequest } from "@/lib/creatives";
import {
  displayCountry,
  displayLanguage,
  formatDate,
  formatMicros,
  formatNumber,
} from "@/lib/advertiser-format";
import { cn } from "@/lib/utils";
import { CampaignError, CampaignLoading, Properties } from "./campaign-shared";
import {
  ChatPreview,
  CreativeBreadcrumb,
  CreativeStatus,
} from "./creative-shared";
import { ChartMetric, metricLabels, SpendChart } from "./spend-chart";

function dateRange(preset: string) {
  const end = new Date();
  if (preset === "yesterday") end.setUTCDate(end.getUTCDate() - 1);
  const start = new Date(end);
  if (preset === "7" || preset === "30")
    start.setUTCDate(start.getUTCDate() - Number(preset) + 1);
  return {
    from: start.toISOString().slice(0, 10),
    to: end.toISOString().slice(0, 10),
  };
}

export function CreativeDetailsPage({ id }: { id: string }) {
  const router = useRouter();
  const [data, setData] = useState<CreativeDetail | null>(null);
  const [error, setError] = useState("");
  const [actionError, setActionError] = useState("");
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [reload, setReload] = useState(0);
  const [preset, setPreset] = useState("7");
  const [range, setRange] = useState(() => dateRange("7"));
  const [draftRange, setDraftRange] = useState(range);
  const [metric, setMetric] = useState<ChartMetric>("spend");
  const [variant, setVariant] = useState<"bar" | "line">("bar");
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError("");
    creativeRequest<{ data: CreativeDetail }>(
      `/${id}?${new URLSearchParams(range)}`,
      { signal: controller.signal },
    )
      .then((response) => setData(response.data))
      .catch((error) => {
        if (!controller.signal.aborted) setError(error.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [id, range, reload]);
  async function mutate(remove = false) {
    if (!data) return;
    setBusy(true);
    setActionError("");
    try {
      await creativeRequest(`/${id}${remove ? "" : "/pause"}`, {
        method: remove ? "DELETE" : "PATCH",
        ...(!remove
          ? { body: JSON.stringify({ is_paused: !data.is_paused }) }
          : {}),
      });
      if (remove) router.push("/advertiser/creatives");
      else {
        setReload((value) => value + 1);
        setBusy(false);
      }
    } catch (error) {
      setActionError(
        error instanceof Error ? error.message : "Unable to update creative.",
      );
      setBusy(false);
    }
  }
  return (
    <div className="mx-auto flex max-w-[1112px] flex-col gap-6">
      <CreativeBreadcrumb current={`CR-${id}`} />
      {error ? (
        <CampaignError
          message={error}
          retry={() => setReload((value) => value + 1)}
        />
      ) : !data ? (
        <CampaignLoading label="Loading creative" />
      ) : (
        <>
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div className="min-w-0">
              <h1 className="break-words text-2xl font-semibold">
                {data.name}
              </h1>
              <p className="mt-2 text-sm text-muted-foreground">
                CR-{data.id} · Minecraft chat ad
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button asChild variant="outline">
                <Link href={`/advertiser/creatives/${id}/edit`}>
                  <Pencil />
                  {data.status === "rejected" ? "Edit & resubmit" : "Edit"}
                </Link>
              </Button>
              <Button
                variant="outline"
                disabled={busy || loading}
                onClick={() => mutate()}
              >
                {busy ? (
                  <Loader2 className="animate-spin" />
                ) : data.is_paused ? (
                  <Play />
                ) : (
                  <Pause />
                )}
                {data.is_paused ? "Resume" : "Pause"}
              </Button>
              <Button
                variant="ghost"
                disabled={busy}
                onClick={() => {
                  setActionError("");
                  setDeleteOpen(true);
                }}
              >
                <Trash2 />
                Delete
              </Button>
            </div>
          </div>
          <CreativeStatus creative={data} />
          {actionError && !deleteOpen && (
            <CampaignError message={actionError} />
          )}
          {data.status === "rejected" && (
            <Alert variant="destructive">
              <AlertTitle>Creative rejected</AlertTitle>
              <AlertDescription>
                {data.rejection_reason ?? "No rejection reason was provided."}
              </AlertDescription>
            </Alert>
          )}
          <div className="grid gap-8 md:grid-cols-2">
            <section className="min-w-0 space-y-5">
              <h2 className="text-base font-medium">Creative information</h2>
              <Properties
                items={[
                  ["Creative ID", `CR-${data.id}`],
                  [
                    "Campaign",
                    <Link
                      key="campaign"
                      href={`/advertiser/campaigns/${data.campaign_id}`}
                      className="underline underline-offset-4"
                    >
                      {data.campaign_name} · CMP-{data.campaign_id}
                    </Link>,
                  ],
                  [
                    "Language",
                    data.language
                      ? displayLanguage(data.language)
                      : "All languages",
                  ],
                  ["Created", formatDate(data.created_at, true)],
                  ["Updated", formatDate(data.updated_at, true)],
                  [
                    "Click URL",
                    data.click_url ? (
                      <a
                        key="url"
                        href={
                          /^https?:\/\//i.test(data.click_url)
                            ? data.click_url
                            : undefined
                        }
                        target="_blank"
                        rel="noopener noreferrer"
                        className="break-all underline"
                      >
                        {data.click_url}
                      </a>
                    ) : (
                      "None"
                    ),
                  ],
                ]}
              />
            </section>
            <section className="min-w-0 space-y-5">
              <h2 className="text-base font-medium">
                Message and chat preview
              </h2>
              <ChatPreview message={data.message_text} url={data.click_url} />
            </section>
          </div>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <Tabs
              value={preset}
              onValueChange={(value) => {
                setPreset(value);
                if (value === "custom") setDraftRange(range);
                else setRange(dateRange(value));
              }}
            >
              <TabsList className="h-auto flex-wrap">
                {[
                  ["today", "Today"],
                  ["yesterday", "Yesterday"],
                  ["7", "Last 7 days"],
                  ["30", "Last 30 days"],
                  ["custom", "Custom range"],
                ].map(([value, label]) => (
                  <TabsTrigger value={value} key={value}>
                    {label}
                  </TabsTrigger>
                ))}
              </TabsList>
            </Tabs>
            <Tabs
              value={variant}
              onValueChange={(value) => setVariant(value as "bar" | "line")}
            >
              <TabsList aria-label="Chart type">
                <TabsTrigger value="bar">Bar</TabsTrigger>
                <TabsTrigger value="line">Line</TabsTrigger>
              </TabsList>
            </Tabs>
          </div>
          {preset === "custom" && (
            <form
              className="flex flex-wrap items-end gap-3"
              onSubmit={(event) => {
                event.preventDefault();
                const days =
                  (Date.parse(draftRange.to) - Date.parse(draftRange.from)) /
                  86400000;
                if (!Number.isFinite(days) || days < 0 || days > 365) {
                  setActionError("Choose a date range of up to 366 days.");
                  return;
                }
                setActionError("");
                setRange(draftRange);
              }}
            >
              <div className="space-y-2">
                <Label htmlFor="creative-range">Date range · UTC</Label>
                <DateRangePicker
                  id="creative-range"
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
              <div className="p-5">
                <h2 className="text-base font-medium">
                  Daily {metricLabels[metric].toLowerCase()}
                </h2>
                <p className="mt-1 text-xs text-muted-foreground">
                  {formatDate(data.period.from)} – {formatDate(data.period.to)}{" "}
                  · UTC
                </p>
              </div>
              {(["impressions", "spend", "effective_cpm"] as const).map(
                (key) => (
                  <button
                    key={key}
                    type="button"
                    aria-pressed={metric === key}
                    onClick={() => setMetric(key)}
                    className={cn(
                      "min-w-0 border-t p-5 text-left hover:bg-muted/50 focus-visible:outline-2 focus-visible:outline-ring sm:border-l sm:border-t-0",
                      metric === key && "bg-muted/50",
                    )}
                  >
                    <span className="block text-xs text-muted-foreground">
                      {metricLabels[key]}
                    </span>
                    <span className="mt-1 block break-words text-2xl font-semibold">
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
            <div className="p-5">
              <SpendChart
                data={data.spend_chart}
                metric={metric}
                variant={variant}
              />
            </div>
          </section>
          {!data.metrics.impressions ? (
            <p className="text-sm text-muted-foreground">
              No delivery data for this period.
            </p>
          ) : (
            <div className="grid gap-8 md:grid-cols-2">
              {(
                [
                  ["Country", data.countries],
                  ["Server category", data.server_categories],
                ] as const
              ).map(([title, rows]) => (
                <section key={title} className="min-w-0 space-y-4">
                  <h2 className="text-base font-medium">{title}</h2>
                  <div className="overflow-hidden rounded-lg border bg-card">
                    <Table className="table-fixed text-xs">
                      <TableHeader className="bg-muted">
                        <TableRow>
                          <TableHead className="h-10 w-[44%] text-xs">
                            {title}
                          </TableHead>
                          <TableHead className="h-10 w-[30%] text-right text-xs">
                            Impressions
                          </TableHead>
                          <TableHead className="h-10 w-[26%] text-right text-xs">
                            Spend
                          </TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {rows.map((row, index) => (
                          <TableRow key={row.label ?? index}>
                            <TableCell className="whitespace-normal break-words py-4 capitalize">
                              {row.label
                                ? title === "Country"
                                  ? displayCountry(row.label)
                                  : row.label
                                : "Unknown"}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                              {formatNumber(Number(row.impressions))}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                              {formatMicros(Number(row.spend_micros))}
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                </section>
              ))}
            </div>
          )}
          <AlertDialog
            open={deleteOpen}
            onOpenChange={(open) => {
              if (!busy) setDeleteOpen(open);
            }}
          >
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Delete this creative?</AlertDialogTitle>
                <AlertDialogDescription>
                  {data.name} will stop serving and be removed from the portal.
                  Historical impressions and spend will be retained.
                </AlertDialogDescription>
              </AlertDialogHeader>
              {actionError && <CampaignError message={actionError} />}
              <AlertDialogFooter>
                <AlertDialogCancel disabled={busy}>Cancel</AlertDialogCancel>
                <AlertDialogAction
                  disabled={busy}
                  onClick={(event) => {
                    event.preventDefault();
                    mutate(true);
                  }}
                >
                  {busy && <Loader2 className="animate-spin" />}Delete creative
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </>
      )}
    </div>
  );
}
