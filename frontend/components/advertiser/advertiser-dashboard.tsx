"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { ChevronRight, Loader2, Plus } from "lucide-react";

import {
  AdvertiserHeader,
  AdvertiserSidebar,
} from "@/components/advertiser/advertiser-shell";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { SpendChart } from "@/components/advertiser/spend-chart";
import { startRouteProgress } from "@/components/ui/route-progress";
import {
  AdvertiserDashboard as DashboardData,
  getAdvertiserDashboard,
} from "@/lib/advertiser";
import { User, clearToken, getCurrentUser, logout } from "@/lib/auth";
import { cn } from "@/lib/utils";

type LoadState =
  | { status: "loading" }
  | { status: "error"; message: string }
  | { status: "ready"; user: User; dashboard: DashboardData };

export function AdvertiserDashboard() {
  const router = useRouter();
  const [state, setState] = useState<LoadState>({ status: "loading" });

  useEffect(() => {
    let isMounted = true;

    async function loadDashboard() {
      try {
        const [userResponse, dashboardResponse] = await Promise.all([
          getCurrentUser(),
          getAdvertiserDashboard(),
        ]);

        if (!isMounted) {
          return;
        }

        if (userResponse.data.role !== "advertiser") {
          setState({
            status: "error",
            message: "This workspace is available to advertiser accounts only.",
          });
          return;
        }

        setState({
          status: "ready",
          user: userResponse.data,
          dashboard: dashboardResponse.data,
        });
      } catch (error) {
        if (!isMounted) {
          return;
        }

        clearToken();
        setState({
          status: "error",
          message:
            error instanceof Error
              ? error.message
              : "Unable to load advertiser dashboard.",
        });
      }
    }

    loadDashboard();

    return () => {
      isMounted = false;
    };
  }, []);

  async function handleLogout() {
    await logout().catch(() => clearToken());
    startRouteProgress();
    router.push("/");
  }

  if (state.status === "loading") {
    return (
      <main className="grid min-h-screen place-items-center bg-background">
        <Loader2
          className="size-5 animate-spin"
          aria-label="Loading advertiser dashboard"
        />
      </main>
    );
  }

  if (state.status === "error") {
    return (
      <main className="grid min-h-screen place-items-center bg-background px-6">
        <div className="w-full max-w-md rounded-lg border border-border bg-card p-6 text-center shadow-soft">
          <p className="text-sm text-muted-foreground">{state.message}</p>
          <Button asChild className="mt-6">
            <Link href="/login">Log in</Link>
          </Button>
        </div>
      </main>
    );
  }

  const { user, dashboard } = state;

  return (
    <div className="min-h-screen bg-background text-foreground">
      <AdvertiserHeader user={user} onLogout={handleLogout} />
      <div className="flex min-h-[calc(100vh-72px)]">
        <AdvertiserSidebar
          balance={dashboard.metrics.available_balance_micros}
        />
        <main className="min-w-0 flex-1 bg-[#f8f9fa] px-5 py-8 sm:px-8 lg:px-10">
          <DashboardContent dashboard={dashboard} />
        </main>
      </div>
    </div>
  );
}

function DashboardContent({ dashboard }: { dashboard: DashboardData }) {
  const periodLabel = useMemo(() => {
    const start = new Date(dashboard.period.month_start);
    const end = new Date(dashboard.period.month_end);

    return `This month · ${start.getDate()}–${end.getDate()} ${end.toLocaleDateString(
      undefined,
      {
        month: "short",
        year: "numeric",
      },
    )}`;
  }, [dashboard.period.month_end, dashboard.period.month_start]);

  return (
    <div className="mx-auto flex max-w-[1112px] flex-col gap-6">
      <Breadcrumb />

      <section className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-normal">Dashboard</h1>
          <p className="mt-2 text-sm text-muted-foreground">
            Your campaigns, creatives and account performance at a glance.
          </p>
        </div>
        <div className="flex gap-2">
          <Button asChild variant="outline">
            <Link href="/advertiser/billing#funding">Add funds</Link>
          </Button>
          <Button asChild>
            <Link href="/advertiser/campaigns/new">
              <Plus aria-hidden="true" />
              Create campaign
            </Link>
          </Button>
        </div>
      </section>

      <section className="flex flex-col gap-3 text-sm sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap items-center gap-3">
          <span className="font-medium">
            {dashboard.account.name} · Advertiser account
          </span>
          <StatusBadge status={dashboard.account.status} />
        </div>
        <span className="text-xs text-muted-foreground">{periodLabel}</span>
      </section>

      <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <MetricCard
          label="Available balance"
          href="/advertiser/billing"
          value={formatMicros(dashboard.metrics.available_balance_micros)}
          caption="View funding →"
        />
        <MetricCard
          label="Active campaigns"
          href="/advertiser/campaigns"
          value={formatNumber(dashboard.metrics.active_campaigns)}
          caption="View campaigns →"
        />
        <MetricCard
          label="Active creatives"
          href="/advertiser/creatives?status=approved"
          value={formatNumber(dashboard.metrics.active_creatives)}
          caption="Approved & eligible →"
        />
        <MetricCard
          label="Pending creatives"
          href="/advertiser/creatives?status=pending_review"
          value={formatNumber(dashboard.metrics.pending_creatives)}
          caption="View pending review →"
        />
      </section>

      <section className="grid gap-4 lg:grid-cols-3">
        <MetricCard
          label="Spend today"
          value={formatMicros(dashboard.metrics.spend_today_micros)}
          caption={`This month: ${formatMicros(dashboard.metrics.spend_month_micros)}`}
        />
        <MetricCard
          label="Impressions today"
          value={formatNumber(dashboard.metrics.impressions_today)}
          caption={`This month: ${formatNumber(dashboard.metrics.impressions_month)}`}
        />
        <MetricCard
          label="Effective CPM"
          value={formatMicros(dashboard.metrics.effective_cpm_micros)}
          caption="This month · validated impressions"
        />
      </section>

      <SpendPanel dashboard={dashboard} />
      <CampaignsPanel dashboard={dashboard} />
    </div>
  );
}

function Breadcrumb() {
  return (
    <nav
      className="flex items-center gap-2 text-sm text-muted-foreground"
      aria-label="Breadcrumb"
    >
      <span>Advertiser</span>
      <ChevronRight className="size-3.5" aria-hidden="true" />
      <span>Dashboard</span>
    </nav>
  );
}

function MetricCard({
  label,
  value,
  caption,
  href,
}: {
  label: string;
  value: string;
  caption: string;
  href?: string;
}) {
  return (
    <div className="rounded-lg bg-card p-6">
      <p className="text-sm text-muted-foreground">{label}</p>
      <p className="mt-2 text-3xl font-semibold tracking-normal">{value}</p>
      <p className="mt-2 text-xs text-muted-foreground">{href ? <Link href={href} className="hover:underline">{caption}</Link> : caption}</p>
    </div>
  );
}

function SpendPanel({ dashboard }: { dashboard: DashboardData }) {
  return (
    <section className="rounded-lg border border-border bg-card p-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h2 className="text-base font-medium">Spend this month</h2>
          <p className="mt-1 text-xs text-muted-foreground">
            {formatMicros(dashboard.metrics.spend_month_micros)} across{" "}
            {formatNumber(dashboard.metrics.impressions_month)} validated
            impressions
          </p>
        </div>
        <Button asChild variant="outline">
          <Link href="#">View reports</Link>
        </Button>
      </div>
      <div className="mt-6 border-t border-border pt-6">
        <SpendChart data={dashboard.spend_chart} variant="line" />
      </div>
    </section>
  );
}

function CampaignsPanel({ dashboard }: { dashboard: DashboardData }) {
  return (
    <section className="rounded-lg border border-border bg-card p-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h2 className="text-base font-medium">Running campaigns</h2>
          <p className="mt-1 text-xs text-muted-foreground">
            {formatNumber(dashboard.running_campaigns.length)} active campaigns
            · lifetime delivery totals
          </p>
        </div>
        <Button asChild variant="outline">
          <Link href="/advertiser/campaigns">View campaigns</Link>
        </Button>
      </div>

      <div className="mt-6 overflow-x-auto rounded-md border border-border">
        <table className="w-full min-w-[760px] border-collapse text-left text-xs">
          <thead className="bg-muted text-foreground">
            <tr>
              <th className="px-3 py-3 font-medium">Campaign</th>
              <th className="px-3 py-3 font-medium">Status</th>
              <th className="px-3 py-3 font-medium">Daily budget</th>
              <th className="px-3 py-3 font-medium">Spend</th>
              <th className="px-3 py-3 font-medium">Impressions</th>
              <th className="px-3 py-3 font-medium">Action</th>
            </tr>
          </thead>
          <tbody>
            {dashboard.running_campaigns.length === 0 ? (
              <tr>
                <td
                  className="px-3 py-6 text-center text-muted-foreground"
                  colSpan={6}
                >
                  No running campaigns yet.
                </td>
              </tr>
            ) : (
              dashboard.running_campaigns.map((campaign) => (
                <tr key={campaign.id} className="border-t border-border">
                  <td className="px-3 py-4 text-foreground">{campaign.name}</td>
                  <td className="px-3 py-4">
                    <StatusBadge status={campaign.status} />
                  </td>
                  <td className="px-3 py-4">
                    {formatMicros(campaign.daily_budget_micros ?? 0)}
                  </td>
                  <td className="px-3 py-4">
                    {formatMicros(campaign.spend_micros)}
                  </td>
                  <td className="px-3 py-4">
                    {formatNumber(campaign.impressions)}
                  </td>
                  <td className="px-3 py-4">
                    <Button asChild variant="ghost" size="sm">
                      <Link href={`/advertiser/campaigns/${campaign.id}`}>
                        View
                      </Link>
                    </Button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function StatusBadge({ status }: { status: string }) {
  const isActive = status === "active";

  return (
    <Badge
      variant="outline"
      className={cn(
        "border-transparent capitalize",
        isActive
          ? "bg-emerald-100 text-emerald-700"
          : "bg-destructive/10 text-destructive",
      )}
    >
      {status.replace("_", " ")}
    </Badge>
  );
}

function formatMicros(value: number) {
  return formatCurrencyValue(value / 1_000_000);
}

function formatCurrencyValue(value: number) {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    maximumFractionDigits: 2,
  }).format(value);
}

function formatNumber(value: number) {
  return new Intl.NumberFormat("en-US").format(value);
}
