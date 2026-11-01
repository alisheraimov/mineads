"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";
import { useRouter } from "@/lib/navigation";
import { RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { CampaignError, CampaignLoading } from "@/components/advertiser/campaign-shared";
import { displayLanguage } from "@/lib/advertiser-format";
import { userDate, userDateTime } from "@/lib/admin-users";
import { listAdminCreatives, creativeDestination } from "@/lib/admin-creatives";
import { useAdminQuery } from "./use-admin-query";
import { CampaignPagination, CampaignState, AdvertiserIdentity } from "./admin-campaign-shared";
import { CreativeBreadcrumb, CreativeFilter, CreativeLanguageFilter } from "./admin-creative-shared";

export function AdminCreativeList() {
  const params = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const query = params.toString();
  const status = params.get("status") ?? "pending_review";
  const urlSearch = params.get("search") ?? "";
  const [search, setSearch] = useState(urlSearch);
  const submitted = useRef<string | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(() => { if (submitted.current === urlSearch) submitted.current = null; else setSearch(urlSearch); }, [urlSearch]);
  useEffect(() => () => { if (timer.current) clearTimeout(timer.current); }, []);
  const loader = useCallback((signal: AbortSignal) => listAdminCreatives(query, signal), [query]);
  const { data, error, loading, refresh } = useAdminQuery(loader, query);
  function filter(key: string, value: string, reset = true) {
    if (timer.current) clearTimeout(timer.current);
    const next = new URLSearchParams(query);
    if (key !== "search" && search !== urlSearch) { if (search) next.set("search", search); else next.delete("search"); submitted.current = search; }
    if (!value || (value === "all" && key !== "status")) next.delete(key); else next.set(key, value);
    if (key === "advertiser_id") next.delete("campaign_id");
    if (reset) next.delete("page");
    router.replace(pathname + (next.size ? "?" + next : ""), { scroll: false });
  }
  const filtered = params.has("search") || params.has("advertiser_id") || params.has("campaign_id") || params.has("language");
  return <div className="space-y-6"><CreativeBreadcrumb />
    <div className="flex flex-wrap items-center justify-between gap-4"><div><h1 className="text-2xl font-semibold">Creatives</h1><p className="mt-2 text-sm text-muted-foreground">Review advertiser submissions and control which ads can serve.</p></div><Button variant="outline" size="sm" onClick={refresh} disabled={loading}><RefreshCw aria-hidden="true" className={loading ? "animate-spin" : ""} />Refresh</Button></div>
    <Tabs value={status} onValueChange={value => filter("status", value)}><TabsList className="h-auto max-w-full flex-wrap"><TabsTrigger value="pending_review">Pending review{data && " · " + data.pending_count}</TabsTrigger><TabsTrigger value="approved">Approved</TabsTrigger><TabsTrigger value="rejected">Rejected</TabsTrigger><TabsTrigger value="all">All</TabsTrigger></TabsList></Tabs>
    <div className="flex flex-wrap items-center gap-3"><Input value={search} onChange={event => { const value = event.target.value; setSearch(value); if (timer.current) clearTimeout(timer.current); timer.current = setTimeout(() => { submitted.current = value; filter("search", value); }, 300); }} aria-label="Search creatives" placeholder="Search message text or creative ID" maxLength={255} className="h-9 max-w-96 bg-background" />
      <CreativeFilter kind="advertisers" value={params.get("advertiser_id") ?? "all"} selected={data?.selected_advertiser ?? null} onChange={value => filter("advertiser_id", value)} />
      <CreativeFilter kind="campaigns" value={params.get("campaign_id") ?? "all"} selected={data?.selected_campaign ?? null} advertiser={params.get("advertiser_id") ?? ""} onChange={value => filter("campaign_id", value)} />
      <CreativeLanguageFilter value={params.get("language") ?? "all"} languages={data?.languages ?? []} onChange={value => filter("language", value)} />
      {filtered && <Button variant="ghost" size="sm" onClick={() => { if (timer.current) clearTimeout(timer.current); setSearch(""); submitted.current = ""; router.replace(pathname + "?status=" + status); }}>Clear filters</Button>}
    </div>
    <p className="text-xs text-muted-foreground">{data ? data.meta.total.toLocaleString() + " matching creatives · " : ""}{status === "pending_review" ? "Oldest submitted first" : "Most recently updated first"}{status === "all" && " · Includes deleted history"}</p>
    {error && <CampaignError message={(data ? "Showing previously loaded data. " : "") + error} retry={refresh} />}
    {!data ? !error && <CampaignLoading label="Loading creatives" /> : <><div className="overflow-hidden rounded-lg border bg-card" aria-busy={loading}><Table className="min-w-[1040px] text-xs [&_th]:text-xs [&_tr>:first-child]:pl-5 [&_tr>:last-child]:pr-5"><TableHeader className="bg-muted"><TableRow><TableHead>Creative</TableHead><TableHead>Campaign / Advertiser</TableHead><TableHead>Language</TableHead><TableHead>Destination</TableHead><TableHead>Status</TableHead><TableHead>{status === "pending_review" ? "Submitted ↑" : "Updated ↓"}</TableHead><TableHead>Action</TableHead></TableRow></TableHeader><TableBody>{data.data.map(creative => <TableRow key={creative.id}><TableCell className="w-72 max-w-72 py-4 whitespace-normal"><Link href={"/admin/creatives/" + creative.id} className="line-clamp-2 break-words text-sm font-medium hover:underline">{creative.message_text}</Link><p className="mt-1 text-muted-foreground">CR-{creative.id}</p></TableCell><TableCell className="max-w-56 whitespace-normal"><p className="text-sm">{creative.campaign ? data.can_view_campaign ? <Link className="hover:underline" href={"/admin/campaigns/" + creative.campaign.id}>{creative.campaign.name}</Link> : creative.campaign.name : "Campaign unavailable"}</p><div className="mt-1 text-muted-foreground"><AdvertiserIdentity advertiser={creative.advertiser} canLink={data.can_view_advertiser} /></div>{creative.has_deleted_parent && <p className="mt-1 text-muted-foreground">Deleted campaign or advertiser</p>}</TableCell><TableCell>{creative.language ? displayLanguage(creative.language) : "Not specified (all languages)"}</TableCell><TableCell className="max-w-40 whitespace-normal break-all text-muted-foreground">{creativeDestination(creative.click_url)}</TableCell><TableCell><CampaignState status={creative.status} />{creative.is_paused && <Badge variant="secondary" className="mt-1 block">Paused by advertiser</Badge>}</TableCell><TableCell className="text-muted-foreground"><time dateTime={status === "pending_review" ? creative.submitted_at : creative.updated_at} title={userDateTime(status === "pending_review" ? creative.submitted_at : creative.updated_at)}>{userDate(status === "pending_review" ? creative.submitted_at : creative.updated_at)}</time></TableCell><TableCell><Button asChild variant={creative.status === "pending_review" && data.can_review && !creative.has_deleted_parent ? "default" : "outline"} size="sm"><Link href={"/admin/creatives/" + creative.id} aria-label={(data.can_review && creative.status === "pending_review" ? "Review" : "View") + " creative CR-" + creative.id}>{creative.status === "pending_review" && data.can_review && !creative.has_deleted_parent ? "Review" : "View"}</Link></Button></TableCell></TableRow>)}{!data.data.length && <TableRow><TableCell colSpan={7} className="h-40 text-center"><p className="text-sm font-medium">{filtered ? "No matching creatives" : status === "pending_review" ? "No creatives awaiting review" : "No creatives in this view"}</p><p className="mt-2 text-muted-foreground">{filtered ? "Try another search or clear the selected filters." : "New advertiser submissions will appear here."}</p></TableCell></TableRow>}</TableBody></Table></div>
      <CampaignPagination meta={data.meta} onPage={page => filter("page", String(page), false)} perPage={params.get("per_page") ?? "10"} onPerPage={value => filter("per_page", value)} noun="creatives" />
      <p className="text-xs text-muted-foreground">{status === "all" ? "Deleted records remain read-only. Content, review decisions and reporting history are retained." : "Deleted creatives and submissions from deleted campaigns or advertisers are excluded from this view."}</p>
    </>}
  </div>;
}

