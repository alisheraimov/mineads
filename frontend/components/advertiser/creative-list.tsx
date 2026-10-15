"use client";

import Link from "next/link";
import { useSearchParams, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { ChevronLeft, ChevronRight, Plus, Search } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { CreativeList, creativeRequest } from "@/lib/creatives";
import {
  displayLanguage,
  formatMicros,
  formatNumber,
} from "@/lib/advertiser-format";
import { CampaignError, CampaignLoading } from "./campaign-shared";
import { CreativeBreadcrumb, CreativeStatus } from "./creative-shared";

export function CreativesListPage() {
  const params = useSearchParams();
  const router = useRouter();
  const urlSearch = params.get("search") ?? "";
  const [search, setSearch] = useState(urlSearch);
  const [data, setData] = useState<CreativeList | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [loading, setLoading] = useState(true);
  const status = params.get("status") ?? "all";
  const query = params.toString();
  function update(key: string, value: string) {
    const next = new URLSearchParams(params);
    if (value && value !== "all") next.set(key, value);
    else next.delete(key);
    if (key !== "page") next.delete("page");
    router.replace(`/advertiser/creatives?${next}`, { scroll: false });
  }
  useEffect(() => {
    setSearch(urlSearch);
  }, [urlSearch]);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError("");
    creativeRequest<{ data: CreativeList }>(`?${query}`, {
      signal: controller.signal,
    })
      .then((response) => setData(response.data))
      .catch((error) => {
        if (!controller.signal.aborted) setError(error.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [query, reload]);
  return (
    <div className="mx-auto flex max-w-[1112px] flex-col gap-6">
      <CreativeBreadcrumb />
      <div className="flex flex-wrap items-center justify-between gap-4">
        <h1 className="text-2xl font-semibold">Creatives</h1>
        <Button asChild>
          <Link href="/advertiser/creatives/new">
            <Plus />
            Create creative
          </Link>
        </Button>
      </div>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <form
          className="flex w-full max-w-80 gap-2"
          onSubmit={(event) => {
            event.preventDefault();
            update("search", search);
          }}
        >
          <Input
            aria-label="Search creatives"
            placeholder="Search by name or message"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            className="min-w-0 flex-1"
          />
          <Button
            type="submit"
            variant="outline"
            size="icon"
            className="size-10"
            aria-label="Search"
          >
            <Search />
          </Button>
        </form>
        <Tabs value={status} onValueChange={(value) => update("status", value)}>
          <TabsList className="h-auto flex-wrap">
            {[
              ["all", "All"],
              ["approved", "Approved"],
              ["pending_review", "Pending"],
              ["rejected", "Rejected"],
              ["paused", "Paused"],
            ].map(([value, label]) => (
              <TabsTrigger key={value} value={value}>
                {label}
                {data ? ` · ${data.counts[value] ?? 0}` : ""}
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
      ) : !data ? (
        <CampaignLoading label="Loading creatives" />
      ) : (
        <>
          <div
            className="overflow-hidden rounded-md border bg-card"
            aria-busy={loading}
          >
            <Table className="min-w-[900px] text-xs">
              <TableHeader className="bg-muted">
                <TableRow>
                  {[
                    "Creative / campaign",
                    "Message / click URL",
                    "Language",
                    "Impressions",
                    "Spend",
                    "Status",
                    "Action",
                  ].map((label) => (
                    <TableHead key={label}>{label}</TableHead>
                  ))}
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.creatives.map((creative) => (
                  <TableRow key={creative.id}>
                    <TableCell className="max-w-52 whitespace-normal break-words py-4">
                      <Link
                        href={`/advertiser/creatives/${creative.id}`}
                        className="font-medium hover:underline"
                      >
                        {creative.name}
                      </Link>
                      <Link
                        href={`/advertiser/campaigns/${creative.campaign_id}`}
                        className="mt-1 block text-muted-foreground hover:underline"
                      >
                        {creative.campaign_name}
                      </Link>
                    </TableCell>
                    <TableCell className="max-w-72 whitespace-normal break-words">
                      <p className="line-clamp-2">{creative.message_text}</p>
                      <p className="mt-1 truncate text-muted-foreground">
                        {creative.click_url ?? "No URL"}
                      </p>
                    </TableCell>
                    <TableCell>
                      {creative.language
                        ? displayLanguage(creative.language)
                        : "All"}
                    </TableCell>
                    <TableCell>{formatNumber(creative.impressions)}</TableCell>
                    <TableCell>{formatMicros(creative.spend_micros)}</TableCell>
                    <TableCell>
                      <CreativeStatus creative={creative} />
                    </TableCell>
                    <TableCell>
                      <Button asChild variant="ghost" size="sm">
                        <Link href={`/advertiser/creatives/${creative.id}`}>
                          View
                        </Link>
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
                {!data.creatives.length && (
                  <TableRow>
                    <TableCell
                      colSpan={7}
                      className="h-32 text-center text-muted-foreground"
                    >
                      No creatives found.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>
          <div className="flex items-center justify-between gap-4 text-xs text-muted-foreground">
            <p>
              {formatNumber(data.pagination.total)}{" "}
              {data.pagination.total === 1 ? "creative" : "creatives"} ·
              All-time delivery
            </p>
            <div className="flex items-center gap-3">
              <Button
                variant="outline"
                size="icon"
                aria-label="Previous page"
                disabled={loading || data.pagination.page <= 1}
                onClick={() => update("page", String(data.pagination.page - 1))}
              >
                <ChevronLeft />
              </Button>
              <span>
                {data.pagination.page} / {data.pagination.last_page}
              </span>
              <Button
                variant="outline"
                size="icon"
                aria-label="Next page"
                disabled={
                  loading || data.pagination.page >= data.pagination.last_page
                }
                onClick={() => update("page", String(data.pagination.page + 1))}
              >
                <ChevronRight />
              </Button>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
