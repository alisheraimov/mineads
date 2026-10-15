"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { ChevronLeft, ChevronRight, Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { CampaignList, listCampaigns } from "@/lib/campaigns";
import {
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
} from "./campaign-shared";

export function CampaignListPage() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [page, setPage] = useState(1);
  const [data, setData] = useState<CampaignList | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    const timer = setTimeout(
      () => {
        setLoading(true);
        setError("");
        const params = new URLSearchParams({ page: String(page) });
        if (search.trim()) params.set("search", search.trim());
        if (status !== "all") params.set("status", status);
        listCampaigns(params, controller.signal)
          .then((response) => setData(response.data))
          .catch((error) => {
            if (!controller.signal.aborted)
              setError(
                error instanceof Error
                  ? error.message
                  : "Unable to load campaigns.",
              );
          })
          .finally(() => {
            if (!controller.signal.aborted) setLoading(false);
          });
      },
      search ? 250 : 0,
    );
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [search, status, page, reload]);

  return (
    <div className="mx-auto flex max-w-[1112px] flex-col gap-6">
      <CampaignBreadcrumb />
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">Campaigns</h1>
          <p className="mt-2 text-sm text-muted-foreground">
            Control delivery, schedules and spend across your campaigns.
          </p>
        </div>
        <Button asChild>
          <Link href="/advertiser/campaigns/new">
            <Plus />
            Create campaign
          </Link>
        </Button>
      </div>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="w-full space-y-2 sm:w-80">
          <Label htmlFor="campaign-search">Search campaigns</Label>
          <Input
            id="campaign-search"
            placeholder="Search by name or ID"
            maxLength={255}
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
        </div>
        <Tabs
          value={status}
          onValueChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
        >
          <TabsList className="flex h-auto flex-wrap">
            <TabsTrigger value="all">
              All campaigns · {data?.counts.all ?? 0}
            </TabsTrigger>
            {["active", "paused", "ended"].map((value) => (
              <TabsTrigger key={value} value={value} className="capitalize">
                {value} · {data?.counts[value] ?? 0}
              </TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
      </div>
      {error ? (
        <CampaignError
          message={error}
          retry={() => setReload((value) => value + 1)}
        />
      ) : loading && !data ? (
        <CampaignLoading />
      ) : (
        data && (
          <>
            <div
              className="overflow-hidden rounded-lg border bg-card"
              aria-busy={loading}
            >
              <Table className="min-w-[960px] text-xs">
                <TableHeader className="bg-muted">
                  <TableRow>
                    {[
                      "Campaign",
                      "Status",
                      "Budget / spent",
                      "Impressions",
                      "eCPM",
                      "Schedule · UTC",
                      "Active ads",
                      "Actions",
                    ].map((label) => (
                      <TableHead key={label} className="h-10 text-xs">
                        {label}
                      </TableHead>
                    ))}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.campaigns.length === 0 ? (
                    <TableRow>
                      <TableCell
                        colSpan={8}
                        className="h-40 text-center text-muted-foreground"
                      >
                        {search || status !== "all"
                          ? "No campaigns match your filters."
                          : "No campaigns yet."}
                      </TableCell>
                    </TableRow>
                  ) : (
                    data.campaigns.map((campaign) => (
                      <TableRow key={campaign.id}>
                        <TableCell className="max-w-64 py-4">
                          <Link
                            href={`/advertiser/campaigns/${campaign.id}`}
                            className="block whitespace-normal break-words text-sm font-medium hover:underline"
                          >
                            {campaign.name}
                          </Link>
                          <p className="mt-1 text-muted-foreground">
                            CMP-{campaign.id} · {data.account_name}
                          </p>
                        </TableCell>
                        <TableCell>
                          <CampaignStatus status={campaign.status} />
                        </TableCell>
                        <TableCell>
                          <p>
                            Daily {formatMicros(campaign.daily_budget_micros)}
                          </p>
                          <p>
                            Total {formatMicros(campaign.total_budget_micros)}
                          </p>
                          <p className="text-muted-foreground">
                            Spent {formatMicros(campaign.spend_micros)}
                          </p>
                        </TableCell>
                        <TableCell>
                          {formatNumber(campaign.impressions)}
                        </TableCell>
                        <TableCell>
                          {campaign.impressions
                            ? formatMicros(campaign.effective_cpm_micros)
                            : "—"}
                        </TableCell>
                        <TableCell>
                          <p>{formatDate(campaign.starts_at)}</p>
                          <p className="text-muted-foreground">
                            {formatDate(campaign.ends_at)}
                          </p>
                        </TableCell>
                        <TableCell>{campaign.active_creatives_count}</TableCell>
                        <TableCell>
                          <Button asChild variant="outline" size="sm">
                            <Link href={`/advertiser/campaigns/${campaign.id}`}>
                              View
                            </Link>
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </div>
            {data.pagination.last_page > 1 && (
              <div className="flex items-center justify-between gap-3">
                <span className="text-xs text-muted-foreground">
                  Page {data.pagination.page} of {data.pagination.last_page} ·{" "}
                  {data.pagination.total} campaigns
                </span>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="icon-sm"
                    title="Previous page"
                    aria-label="Previous page"
                    disabled={page === 1 || loading}
                    onClick={() => setPage((value) => value - 1)}
                  >
                    <ChevronLeft />
                  </Button>
                  <Button
                    variant="outline"
                    size="icon-sm"
                    title="Next page"
                    aria-label="Next page"
                    disabled={page >= data.pagination.last_page || loading}
                    onClick={() => setPage((value) => value + 1)}
                  >
                    <ChevronRight />
                  </Button>
                </div>
              </div>
            )}
            <AccountDataTime value={data.generated_at} />
          </>
        )
      )}
    </div>
  );
}
