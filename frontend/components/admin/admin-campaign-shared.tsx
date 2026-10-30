"use client";

import Link from "next/link";
import { ChevronRight } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { UserSelect } from "./user-shared";
import { type PageMeta, type CampaignAdvertiser } from "@/lib/admin-campaigns";

export function AdminCampaignBreadcrumb({ name }: { name?: string }) {
  return <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"><span>Admin</span><ChevronRight className="size-3.5" aria-hidden="true" />{name ? <><Link href="/admin/campaigns" className="hover:text-foreground">Campaigns</Link><ChevronRight className="size-3.5" aria-hidden="true" /><span aria-current="page" className="text-foreground">{name}</span></> : <span aria-current="page" className="text-foreground">Campaigns</span>}</nav>;
}
export function CampaignState({ status }: { status: string }) {
  return <Badge variant="outline" className={status === "active" || status === "approved" ? "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-400" : status === "paused" || status === "pending_review" ? "border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-400" : status === "rejected" ? "border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-400" : "bg-muted text-muted-foreground"}>{status.charAt(0).toUpperCase() + status.slice(1).replaceAll("_", " ")}</Badge>;
}
export function AdvertiserIdentity({ advertiser, canLink, email = false }: { advertiser: CampaignAdvertiser | null; canLink: boolean; email?: boolean }) {
  if (!advertiser) return <span className="text-muted-foreground">Unavailable advertiser</span>;
  return <div className="min-w-0">{canLink ? <Link href={"/admin/users/" + advertiser.id} className="hover:underline">{advertiser.name}</Link> : <span>{advertiser.name}</span>}{email && <p className="mt-1 break-all text-xs text-muted-foreground">{advertiser.email}</p>}</div>;
}
export function CampaignPagination({ meta, onPage, perPage, onPerPage, noun = "campaigns" }: { meta: PageMeta; onPage: (page: number) => void; perPage?: string; onPerPage?: (value: string) => void; noun?: string }) {
  return <div className="flex flex-wrap items-center justify-between gap-4"><p className="text-xs text-muted-foreground">{meta.from !== undefined ? `Showing ${meta.from ?? 0}–${meta.to ?? 0} of ${meta.total.toLocaleString()} ${noun}` : `${meta.total.toLocaleString()} ${noun}`}</p><div className="flex items-center gap-2">{onPerPage && <UserSelect label={noun.charAt(0).toUpperCase() + noun.slice(1) + " per page"} value={perPage ?? "10"} onChange={onPerPage} options={[10, 25, 50].map(n => ({ value: String(n), label: n + " per page" }))} className="bg-background" />}<Button variant="outline" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Previous</Button><span className="px-2 text-xs">{meta.current_page} / {meta.last_page}</span><Button variant="outline" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Next</Button></div></div>;
}
