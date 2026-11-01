"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";
import { useRouter } from "@/lib/navigation";
import { Check, ChevronsUpDown, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { CampaignError, CampaignLoading } from "@/components/advertiser/campaign-shared";
import { formatMicros } from "@/lib/advertiser-format";
import { findCampaignAdvertisers, listAdminCampaigns, type CampaignAdvertiser } from "@/lib/admin-campaigns";
import { userDate, userDateTime } from "@/lib/admin-users";
import { UserSelect } from "./user-shared";
import { useAdminQuery } from "./use-admin-query";
import { AdminCampaignBreadcrumb, AdvertiserIdentity, CampaignPagination, CampaignState } from "./admin-campaign-shared";

export function AdminCampaignList() {
  const params = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const query = params.toString();
  const urlSearch = params.get("search") ?? "";
  const [search, setSearch] = useState(urlSearch);
  const submittedSearch = useRef<string | null>(null);
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(() => {
    if (submittedSearch.current === urlSearch) submittedSearch.current = null;
    else setSearch(urlSearch);
  }, [urlSearch]);
  useEffect(() => () => { if (searchTimer.current) clearTimeout(searchTimer.current); }, []);
  const loader = useCallback((signal: AbortSignal) => listAdminCampaigns(query, signal), [query]);
  const { data, error, loading, refresh } = useAdminQuery(loader, query);
  function filter(key: string, value: string, reset = true) {
    if (searchTimer.current) clearTimeout(searchTimer.current);
    const next = new URLSearchParams(query);
    if (key !== "search" && search !== urlSearch) {
      if (search) next.set("search", search); else next.delete("search");
      submittedSearch.current = search;
    }
    if (!value || value === "all") next.delete(key); else next.set(key, value);
    if (reset) next.delete("page");
    router.replace(pathname + (next.size ? "?" + next : ""), { scroll: false });
  }
  const filtered = params.has("search") || params.has("status") || params.has("advertiser_id");
  return <div className="space-y-6">
    <AdminCampaignBreadcrumb />
    <div className="flex flex-wrap items-center justify-between gap-4"><div><h1 className="text-2xl font-semibold">Campaigns</h1><p className="mt-2 text-sm text-muted-foreground">Inspect advertiser demand, campaign performance and delivery.</p></div><Button variant="outline" size="sm" disabled={loading} onClick={refresh}><RefreshCw className={loading ? "animate-spin" : ""} aria-hidden="true" />Refresh</Button></div>
    <div className="flex flex-wrap items-center gap-3">
      <Input aria-label="Search campaigns" placeholder="Search campaigns by name or ID" className="h-9 max-w-[440px] bg-background" maxLength={255} value={search} onChange={event => {
        const value = event.target.value;
        setSearch(value);
        if (searchTimer.current) clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => { submittedSearch.current = value; filter("search", value); }, 300);
      }} />
      <AdvertiserFilter selected={data?.selected_advertiser ?? null} value={params.get("advertiser_id") ?? "all"} onChange={value => filter("advertiser_id", value)} />
      <UserSelect label="Filter campaign status" value={params.get("status") ?? "all"} onChange={value => filter("status", value)} options={[{ value: "all", label: "All statuses" }, ...["active", "paused", "ended", "draft", "deleted"].map(value => ({ value, label: value.charAt(0).toUpperCase() + value.slice(1) }))]} className="h-9 min-w-40 bg-background" />
      {filtered && <Button variant="ghost" size="sm" onClick={() => { if (searchTimer.current) clearTimeout(searchTimer.current); setSearch(""); submittedSearch.current = ""; router.replace(pathname); }}>Clear filters</Button>}
    </div>
    {error && <CampaignError message={(data ? "Showing previously loaded data. " : "") + error} retry={refresh} />}
    {!data ? !error && <CampaignLoading label="Loading campaigns" /> : <>
      <div className="overflow-hidden rounded-lg border bg-card" aria-busy={loading}>
        <Table className={(data.can_view_money ? "min-w-[1050px]" : "min-w-[850px]") + " text-xs [&_th]:text-xs [&_tr>:first-child]:pl-5 [&_tr>:last-child]:pr-5"}>
          <TableHeader className="bg-muted"><TableRow><TableHead>Campaign</TableHead><TableHead>Advertiser</TableHead><TableHead>Status</TableHead>{data.can_view_money && <TableHead>Budget / spent</TableHead>}<TableHead>Impressions</TableHead><TableHead>Creatives</TableHead><TableHead>Last delivery · UTC</TableHead><TableHead>Actions</TableHead></TableRow></TableHeader>
          <TableBody>{data.data.map(campaign => <TableRow key={campaign.id}>
            <TableCell className="max-w-56 py-4"><Link href={"/admin/campaigns/" + campaign.id} className="block whitespace-normal break-words text-sm font-medium hover:underline">{campaign.name}</Link><p className="mt-1 text-muted-foreground">CMP-{campaign.id}</p></TableCell>
            <TableCell className="max-w-48 whitespace-normal"><AdvertiserIdentity advertiser={campaign.advertiser} canLink={data.can_view_advertiser} email /></TableCell>
            <TableCell><CampaignState status={campaign.status} /></TableCell>
            {data.can_view_money && <TableCell><p>Daily {campaign.daily_budget_micros == null ? "Unlimited" : formatMicros(campaign.daily_budget_micros)}</p><p>Total {campaign.total_budget_micros == null ? "Unlimited" : formatMicros(campaign.total_budget_micros)}</p><p className="text-muted-foreground">Spent {formatMicros(campaign.spend_micros ?? 0)}</p></TableCell>}
            <TableCell>{campaign.impressions.toLocaleString()}</TableCell><TableCell><p>{campaign.creatives_count} total</p>{campaign.pending_creatives_count > 0 && <p className="mt-1 text-muted-foreground">{campaign.pending_creatives_count} pending</p>}</TableCell>
            <TableCell>{campaign.last_delivery_at ? <time dateTime={campaign.last_delivery_at} title={userDateTime(campaign.last_delivery_at)}>{userDate(campaign.last_delivery_at)}<span className="mt-1 block text-muted-foreground">{new Date(campaign.last_delivery_at).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit", timeZone: "UTC" })}</span></time> : <span className="text-muted-foreground">Not served yet</span>}</TableCell>
            <TableCell><Button asChild variant="outline" size="sm"><Link href={"/admin/campaigns/" + campaign.id} aria-label={"View " + campaign.name}>View</Link></Button></TableCell>
          </TableRow>)}{!data.data.length && <TableRow><TableCell colSpan={data.can_view_money ? 8 : 7} className="h-40 text-center"><p className="font-medium">{filtered ? "No campaigns match your filters" : "No campaigns yet"}</p><p className="mt-1 text-muted-foreground">{filtered ? "Try another search or clear the selected filters." : "Campaigns created by advertisers will appear here."}</p></TableCell></TableRow>}</TableBody>
        </Table>
      </div>
      <CampaignPagination meta={data.meta} onPage={page => filter("page", String(page), false)} perPage={params.get("per_page") ?? "10"} onPerPage={value => filter("per_page", value)} />
      <p className="text-xs text-muted-foreground">Impressions are validated impressions. Spend and delivery totals are lifetime values. Updated {userDateTime(data.generated_at)}.</p>
    </>}
  </div>;
}

function AdvertiserFilter({ selected, value, onChange }: { selected: CampaignAdvertiser | null; value: string; onChange: (value: string) => void }) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const loader = useCallback((signal: AbortSignal) => findCampaignAdvertisers(search, signal), [search]);
  const { data, error, refresh } = useAdminQuery(loader, search);
  return <Popover open={open} onOpenChange={setOpen}><PopoverTrigger asChild><Button variant="outline" role="combobox" aria-expanded={open} aria-label="Filter advertiser" className="h-9 w-64 justify-between font-normal"><span className="truncate">{value === "all" ? "All advertisers" : selected?.name ?? "Advertiser U-" + value}</span><ChevronsUpDown className="size-4 shrink-0 opacity-50" /></Button></PopoverTrigger><PopoverContent className="w-[360px] max-w-[calc(100vw-2rem)] p-0" align="start" side="bottom"><Command shouldFilter={false}><CommandInput placeholder="Search name, email or user ID…" value={search} onValueChange={setSearch} /><CommandList><CommandGroup><CommandItem value="all" onSelect={() => { onChange("all"); setOpen(false); }}><span className="flex-1">All advertisers</span>{value === "all" && <Check />}</CommandItem>{data?.data.map(user => <CommandItem key={user.id} value={String(user.id)} onSelect={() => { onChange(String(user.id)); setOpen(false); }}><div className="min-w-0 flex-1"><p>{user.name}</p><p className="break-all text-xs text-muted-foreground">{user.email} · U-{user.id}</p></div>{value === String(user.id) && <Check />}</CommandItem>)}</CommandGroup>{!data && !error && <p role="status" className="p-3 text-xs text-muted-foreground">Loading advertisers…</p>}{data?.data.length === 0 && <CommandEmpty>No matching advertisers.</CommandEmpty>}{error && <div className="space-y-2 p-3"><p role="alert" className="text-xs text-destructive">{error}</p><Button size="sm" variant="outline" onClick={refresh}>Retry</Button></div>}</CommandList></Command></PopoverContent></Popover>;
}
