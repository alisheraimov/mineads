"use client";

import { useCallback, useState } from "react";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ChatPreview } from "@/components/advertiser/creative-shared";
import { CampaignError, CampaignLoading } from "@/components/advertiser/campaign-shared";
import { displayLanguage } from "@/lib/advertiser-format";
import { getAdminCampaignCreatives, type AdminCampaignCreative, type AdminCampaignDetail } from "@/lib/admin-campaigns";
import { userDateTime } from "@/lib/admin-users";
import { useAdminQuery } from "./use-admin-query";
import { CampaignPagination, CampaignState } from "./admin-campaign-shared";
import { UserField } from "./user-shared";

function destination(url: string | null) {
  if (!url) return "No destination URL";
  try { return new URL(url).hostname; } catch { return "Invalid destination URL"; }
}

export function AdminCampaignCreatives({ campaign }: { campaign: AdminCampaignDetail }) {
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<AdminCampaignCreative>();
  const loader = useCallback((signal: AbortSignal) => getAdminCampaignCreatives(campaign.id, page, signal), [campaign.id, page]);
  const { data, error, loading, refresh } = useAdminQuery(loader, campaign.id + ":" + page);
  const counts = campaign.creative_counts;
  return <div className="space-y-5">
    <div><h2 className="text-base font-semibold">Campaign creatives</h2><p className="mt-2 text-sm text-muted-foreground">{counts.approved} approved · {counts.pending_review} pending review · {counts.rejected} rejected{counts.deleted ? ` · ${counts.deleted} deleted` : ""} · Content is read-only in Campaigns.</p></div>
    {error && <CampaignError message={error} retry={refresh} />}
    {!data ? !error && <CampaignLoading label="Loading campaign creatives" /> : <><div className="overflow-hidden rounded-lg border bg-card" aria-busy={loading}><Table className="min-w-[850px] text-xs [&_th]:text-xs [&_tr>:first-child]:pl-5 [&_tr>:last-child]:pr-5"><TableHeader className="bg-muted"><TableRow><TableHead>Creative / message</TableHead><TableHead>Language</TableHead><TableHead>Destination</TableHead><TableHead>Status</TableHead><TableHead>Submitted · UTC</TableHead><TableHead>Actions</TableHead></TableRow></TableHeader><TableBody>{data.data.map(creative => <TableRow key={creative.id}><TableCell className="max-w-80 py-4 whitespace-normal"><p className="text-sm font-medium">CR-{creative.id} · {creative.name ?? "Untitled creative"}</p><p className="mt-1 line-clamp-2 break-words text-muted-foreground">{creative.message_text}</p>{creative.rejection_reason && <p className="mt-2 break-words text-destructive">Rejection reason: {creative.rejection_reason}</p>}</TableCell><TableCell>{creative.language ? displayLanguage(creative.language) : "All languages"}</TableCell><TableCell className="max-w-44 break-all whitespace-normal">{destination(creative.click_url)}</TableCell><TableCell><CampaignState status={creative.status} />{creative.is_paused && <Badge variant="secondary" className="mt-1 block">Paused by advertiser</Badge>}</TableCell><TableCell>{userDateTime(creative.created_at)}</TableCell><TableCell><Button variant="outline" size="sm" onClick={() => setSelected(creative)} aria-label={"View creative CR-" + creative.id}>View</Button></TableCell></TableRow>)}{!data.data.length && <TableRow><TableCell colSpan={6} className="h-32 text-center text-muted-foreground">No creatives have been added to this campaign.</TableCell></TableRow>}</TableBody></Table></div>{data.meta.last_page > 1 && <CampaignPagination meta={data.meta} onPage={setPage} noun="creatives" />}</>}
    {selected && <Dialog open onOpenChange={open => { if (!open) setSelected(undefined); }}><DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-2xl"><DialogHeader><DialogTitle>CR-{selected.id} · {selected.name ?? "Creative details"}</DialogTitle><DialogDescription>Read-only creative information for {campaign.name}.</DialogDescription></DialogHeader><dl className="grid gap-4 sm:grid-cols-2"><UserField label="Status"><CampaignState status={selected.status} /></UserField><UserField label="Language">{selected.language ? displayLanguage(selected.language) : "All languages"}</UserField><UserField label="Submitted">{userDateTime(selected.created_at)}</UserField><UserField label="Destination URL">{selected.click_url ?? "No destination URL"}</UserField></dl>{selected.rejection_reason && <p className="text-sm text-destructive">Rejection reason: {selected.rejection_reason}</p>}<div><h3 className="mb-2 text-sm font-medium">Message</h3><p className="whitespace-pre-wrap break-words rounded-md border bg-muted/50 p-3 text-sm">{selected.message_text}</p></div><ChatPreview message={selected.message_text} url={selected.click_url} /><DialogFooter><Button variant="outline" onClick={() => setSelected(undefined)}>Close</Button><Button asChild><Link href={"/admin/creatives/" + selected.id}>Open creative details</Link></Button></DialogFooter></DialogContent></Dialog>}
  </div>;
}

