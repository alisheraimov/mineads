"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import {
  ChevronLeft,
  ChevronRight,
  CircleAlert,
  CreditCard,
  RotateCw,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { AdvertiserBilling, getBilling } from "@/lib/billing";
import {
  formatDate,
  formatMicros,
  formatNumber,
} from "@/lib/advertiser-format";
import {
  AccountDataTime,
  CampaignError,
  CampaignLoading,
} from "./campaign-shared";

function monthLabel(month: string) {
  return new Intl.DateTimeFormat("en-GB", {
    month: "short",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(`${month}-01T00:00:00Z`));
}

export function BillingPage() {
  const [data, setData] = useState<AdvertiserBilling | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [reload, setReload] = useState(0);
  useEffect(() => {
    if (data && window.location.hash === "#funding") {
      document.getElementById("funding")?.scrollIntoView({ block: "start" });
    }
  }, [data]);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError("");
    getBilling(page, controller.signal)
      .then((response) => setData(response.data))
      .catch((error) => {
        if (!controller.signal.aborted)
          setError(
            error instanceof Error ? error.message : "Unable to load billing.",
          );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [page, reload]);
  return (
    <div className="mx-auto flex max-w-[1112px] flex-col gap-6">
      <nav
        aria-label="Breadcrumb"
        className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
      >
        <Link href="/advertiser">Advertiser</Link>
        <ChevronRight className="size-3.5" />
        <span aria-current="page" className="text-foreground">
          Billing &amp; funding
        </span>
      </nav>
      <div className="flex flex-wrap items-center justify-between gap-4">
        <h1 className="text-2xl font-semibold">Billing &amp; funding</h1>
        <Button
          variant="outline"
          size="icon"
          aria-label="Refresh billing"
          title="Refresh billing"
          disabled={loading}
          onClick={() => setReload((value) => value + 1)}
        >
          <RotateCw className={loading ? "animate-spin" : undefined} />
        </Button>
      </div>
      {error ? (
        <CampaignError
          message={error}
          retry={() => setReload((value) => value + 1)}
        />
      ) : !data ? (
        <CampaignLoading label="Loading billing" />
      ) : (
        <>
          {data.account.status === "suspended" && (
            <Alert variant="destructive">
              <CircleAlert />
              <AlertTitle>Account suspended</AlertTitle>
              <AlertDescription>
                Your billing history remains available. Ad delivery is
                suspended.
              </AlertDescription>
            </Alert>
          )}
          {data.low_balance && (
            <Alert>
              <CircleAlert />
              <AlertTitle>Low balance</AlertTitle>
              <AlertDescription>
                Your balance is below $10. Ad delivery requires sufficient
                available funds.
              </AlertDescription>
            </Alert>
          )}
          <section
            aria-label="Billing summary"
            className="grid gap-4 sm:grid-cols-3"
          >
            {[
              [
                "Available balance",
                formatMicros(data.metrics.available_balance_micros),
                "Advertiser ledger balance",
              ],
              [
                "Funds added this month",
                formatMicros(data.metrics.funds_added_month_micros),
                monthLabel(data.generated_at.slice(0, 7)),
              ],
              [
                "Spend this month",
                formatMicros(data.metrics.spend_month_micros),
                `${formatNumber(data.metrics.impressions_month)} validated impressions`,
              ],
            ].map(([label, value, caption]) => (
              <div key={label} className="min-w-0 rounded-lg bg-card p-5">
                <p className="text-sm text-muted-foreground">{label}</p>
                <p className="mt-3 break-words text-2xl font-semibold tabular-nums">
                  {value}
                </p>
                <p className="mt-2 text-xs text-muted-foreground">{caption}</p>
              </div>
            ))}
          </section>
          <section className="min-w-0 space-y-4" aria-busy={loading}>
            <div>
              <h2 className="text-base font-medium">Monthly balance history</h2>
              <p className="mt-1 text-xs text-muted-foreground">
                USD · Current month includes activity through{" "}
                {formatDate(data.generated_at, true)}
              </p>
            </div>
            <div className="overflow-hidden rounded-lg border bg-card">
              <Table className="min-w-[800px] text-xs">
                <TableHeader className="bg-muted">
                  <TableRow>
                    {[
                      "Month",
                      "Starting balance",
                      "Funds added",
                      "Spend",
                      "Adjustments / refunds",
                      "Ending balance",
                    ].map((label, index) => (
                      <TableHead
                        key={label}
                        className={
                          index ? "h-10 text-right text-xs" : "h-10 text-xs"
                        }
                      >
                        {label}
                      </TableHead>
                    ))}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.months.map((month) => (
                    <TableRow key={month.month}>
                      <TableCell className="py-4 font-medium">
                        {monthLabel(month.month)}
                        {month.is_current && (
                          <span className="ml-1 font-normal text-muted-foreground">
                            · To date
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatMicros(month.starting_balance_micros)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatMicros(month.funds_added_micros)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatMicros(
                          month.spend_micros === 0 ? 0 : -month.spend_micros,
                        )}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatMicros(month.adjustments_micros)}
                      </TableCell>
                      <TableCell className="text-right font-medium tabular-nums">
                        {formatMicros(month.ending_balance_micros)}
                      </TableCell>
                    </TableRow>
                  ))}
                  {!data.months.length && (
                    <TableRow>
                      <TableCell
                        colSpan={6}
                        className="h-24 text-center text-muted-foreground"
                      >
                        No history on this page.
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>
            {data.pagination.last_page > 1 && (
              <div className="flex items-center justify-end gap-3 text-xs text-muted-foreground">
                <Button
                  variant="outline"
                  size="icon"
                  aria-label="Newer months"
                  disabled={loading || page <= 1}
                  onClick={() => setPage((value) => value - 1)}
                >
                  <ChevronLeft />
                </Button>
                <span>
                  {page} / {data.pagination.last_page}
                </span>
                <Button
                  variant="outline"
                  size="icon"
                  aria-label="Older months"
                  disabled={loading || page >= data.pagination.last_page}
                  onClick={() => setPage((value) => value + 1)}
                >
                  <ChevronRight />
                </Button>
              </div>
            )}
          </section>
          <section id="funding" className="scroll-mt-6 space-y-3 border-t pt-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h2 className="text-base font-medium">Funding</h2>
              <Button disabled aria-describedby="funding-status">
                <CreditCard />
                Add funds
              </Button>
            </div>
            <p id="funding-status" className="text-sm text-muted-foreground">
              Online funding is not available yet. Stripe payments will be added
              later.
            </p>
          </section>
          <AccountDataTime value={data.generated_at} />
        </>
      )}
    </div>
  );
}
