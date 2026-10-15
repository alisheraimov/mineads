"use client";

import Link from "next/link";
import { ChevronRight, Loader2, RotateCw } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { cn } from "@/lib/utils";
import { formatDate } from "@/lib/advertiser-format";

export function CampaignBreadcrumb({ current }: { current?: string }) {
  return (
    <nav
      aria-label="Breadcrumb"
      className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
    >
      <Link href="/advertiser">Advertiser</Link>
      <ChevronRight className="size-3.5" />
      <Link
        href="/advertiser/campaigns"
        aria-current={!current ? "page" : undefined}
      >
        Campaigns
      </Link>
      {current && (
        <>
          <ChevronRight className="size-3.5" />
          <span className="text-foreground">{current}</span>
        </>
      )}
    </nav>
  );
}

export function CampaignStatus({ status }: { status: string }) {
  return (
    <Badge
      variant="outline"
      className={cn(
        "capitalize",
        status === "active" || status === "approved" || status === "eligible"
          ? "border-emerald-200 bg-emerald-50 text-emerald-700"
          : status === "paused"
            ? "border-amber-200 bg-amber-50 text-amber-700"
            : status === "rejected" || status === "not eligible"
              ? "border-rose-200 bg-rose-50 text-rose-700"
              : status === "pending_review"
                ? "border-sky-200 bg-sky-50 text-sky-700"
                : "bg-muted text-muted-foreground",
      )}
    >
      {status.replaceAll("_", " ")}
    </Badge>
  );
}

export function CampaignLoading({
  label = "Loading campaigns",
}: {
  label?: string;
}) {
  return (
    <div className="grid min-h-60 place-items-center" role="status">
      <Loader2 className="size-5 animate-spin" aria-label={label} />
    </div>
  );
}

export function CampaignError({
  message,
  retry,
}: {
  message: string;
  retry?: () => void;
}) {
  return (
    <Alert variant="destructive" role="alert">
      <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
        <span>{message}</span>
        {retry && (
          <Button variant="outline" size="sm" onClick={retry}>
            <RotateCw />
            Retry
          </Button>
        )}
      </AlertDescription>
    </Alert>
  );
}

export function AccountDataTime({ value }: { value: string }) {
  return (
    <p className="text-xs text-muted-foreground">
      USD · Account data as of {formatDate(value, true)}
    </p>
  );
}

export function Properties({ items }: { items: [string, React.ReactNode][] }) {
  return (
    <dl className="space-y-4">
      {items.map(([label, value]) => (
        <div
          key={label}
          className="grid gap-1 sm:grid-cols-[160px_minmax(0,1fr)] sm:gap-6"
        >
          <dt className="text-xs text-muted-foreground">{label}</dt>
          <dd className="min-w-0 break-words text-sm">{value}</dd>
        </div>
      ))}
    </dl>
  );
}
