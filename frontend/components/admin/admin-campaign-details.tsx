"use client";

import { useCallback, useState, type FormEvent } from "react";
import Link from "next/link";
import { useRouter } from "@/lib/navigation";
import { RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Progress } from "@/components/ui/progress";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { CampaignError, CampaignLoading } from "@/components/advertiser/campaign-shared";
import { displayCountry, displayLanguage, formatMicros } from "@/lib/advertiser-format";
import { ApiError, clearToken } from "@/lib/auth";
import { deleteAdminCampaign, getAdminCampaign, type AdminCampaignDetail } from "@/lib/admin-campaigns";
import { userDateTime } from "@/lib/admin-users";
import { UserField, UserPanel } from "./user-shared";
import { AdminCampaignBreadcrumb, AdvertiserIdentity, CampaignState } from "./admin-campaign-shared";
import { useAdminQuery } from "./use-admin-query";
import { AdminCampaignCreatives } from "./admin-campaign-creatives";
import { AdminCampaignDelivery } from "./admin-campaign-delivery";

export function AdminCampaignDetails({ id }: { id: string }) {
  const loader = useCallback((signal: AbortSignal) => getAdminCampaign(id, signal), [id]);
  const { data: result, error, loading, refresh, replaceData } = useAdminQuery(loader, id);
  const [tab, setTab] = useState("summary");
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [notice, setNotice] = useState("");
  const data = result?.data;
  if (!data) return <div className="space-y-6"><AdminCampaignBreadcrumb /><Button asChild variant="outline"><Link href="/admin/campaigns">Back to campaigns</Link></Button>{error ? <CampaignError message={error} retry={refresh} /> : <CampaignLoading label="Loading campaign" />}</div>;
  return <div className="space-y-6">
    <AdminCampaignBreadcrumb name={data.name} />
    <div className="flex flex-wrap items-center justify-between gap-4"><div className="min-w-0"><h1 className="break-words text-2xl font-semibold">{data.name}</h1><div className="mt-2 flex flex-wrap items-center gap-3 text-sm text-muted-foreground"><span className="text-xs">CMP-{data.id}</span><AdvertiserIdentity advertiser={data.advertiser} canLink={data.can_view_advertiser} /><CampaignState status={data.status} /></div></div><div className="flex gap-2"><Button variant="outline" size="icon" disabled={loading} onClick={refresh} aria-label="Refresh campaign"><RefreshCw className={loading ? "animate-spin" : ""} /></Button><Button asChild variant="outline"><Link href="/admin/campaigns">Back to campaigns</Link></Button></div></div>
    {!data.can_view_money && <p className="text-xs leading-5 text-muted-foreground">Read-only access · Configuration and delivery counts are visible. Monetary values and management actions are restricted.</p>}
    {notice && <p role="status" className="rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-4 text-sm">{notice}</p>}
    {error && <CampaignError message={"Showing previously loaded data. " + error} retry={refresh} />}
    {data.status === "deleted" && <Alert><AlertTitle>Campaign deleted</AlertTitle><AlertDescription><p>{data.deleted_by ? "Deleted by " + data.deleted_by.name : "Deleted"}{data.deleted_at ? " on " + userDateTime(data.deleted_at) : ""}.</p><p>Future ad delivery is blocked. This campaign is no longer available in the advertiser portal. Historical records are retained and this view is read-only.</p></AlertDescription></Alert>}
    <Tabs value={tab === "actions" && !data.can_delete ? "summary" : tab} onValueChange={setTab} className="gap-6">
      <TabsList className="h-auto max-w-full flex-wrap"><TabsTrigger value="summary">Summary</TabsTrigger><TabsTrigger value="creatives">Creatives</TabsTrigger><TabsTrigger value="delivery">Delivery</TabsTrigger>{data.can_delete && <TabsTrigger value="actions">Actions</TabsTrigger>}</TabsList>
      <TabsContent value="summary" className="m-0 space-y-6">
        {data.status !== "deleted" && <Alert><AlertTitle>{data.delivery_eligible ? "Eligible for ad selection" : "Not eligible to serve"}</AlertTitle><AlertDescription>{data.delivery_eligible ? "The campaign is active, within schedule, and has targeting, approved creatives, budget and funds. Actual delivery depends on a matching audience." : <ul className="list-disc space-y-1 pl-4">{data.delivery_issues.map(issue => <li key={issue}>{issue}</li>)}</ul>}</AlertDescription></Alert>}
        <UserPanel title="Campaign information"><dl className="grid gap-6 sm:grid-cols-2 xl:grid-cols-3"><UserField label="Campaign ID">CMP-{data.id}</UserField><UserField label="Advertiser"><AdvertiserIdentity advertiser={data.advertiser} canLink={data.can_view_advertiser} email /></UserField><UserField label="Scheduled start · UTC">{data.starts_at ? userDateTime(data.starts_at) : "No start restriction"}</UserField><UserField label="Scheduled end · UTC">{data.ends_at ? userDateTime(data.ends_at) : "No end date"}</UserField><UserField label="Created">{userDateTime(data.created_at)}</UserField><UserField label="Updated">{userDateTime(data.updated_at)}</UserField></dl></UserPanel>
        {data.budget && <UserPanel title="Budget & advertiser funds"><div className="grid gap-6 md:grid-cols-2"><BudgetUsage label="Daily budget / spent today" cap={data.daily_budget_micros ?? null} used={data.budget.spent_today_micros} remaining={data.budget.daily_remaining_micros} /><BudgetUsage label="Total budget / lifetime spend" cap={data.total_budget_micros ?? null} used={data.budget.lifetime_spend_micros} remaining={data.budget.total_remaining_micros} /></div><dl className="border-t pt-5"><UserField label="Advertiser available balance · shared across campaigns">{formatMicros(data.budget.available_balance_micros)}</UserField></dl><p className="text-xs leading-5 text-muted-foreground">Budget usage follows posted advertiser charges. Daily usage resets at midnight UTC. Campaign activation remains controlled by the advertiser; existing user, account, creative and schedule restrictions still apply.</p></UserPanel>}
        <UserPanel title="Targeting rules" description="Each row is a matching audience combination. A visitor must match every condition within a row."><div className="max-h-96 overflow-auto"><Table><TableHeader><TableRow><TableHead>Countries</TableHead><TableHead>Languages</TableHead><TableHead>Server categories</TableHead></TableRow></TableHeader><TableBody>{data.targeting_rules.map((rule, index) => <TableRow key={index}><TableCell className="max-w-96 whitespace-normal">{rule.countries.length ? rule.countries.map(displayCountry).join(", ") : "All countries"}</TableCell><TableCell>{rule.language ? displayLanguage(rule.language) : "All languages"}</TableCell><TableCell className={rule.server_category ? "capitalize" : ""}>{rule.server_category ?? "All categories"}</TableCell></TableRow>)}{!data.targeting_rules.length && <TableRow><TableCell colSpan={3} className="h-24 text-center text-muted-foreground">No audience targeting configured.</TableCell></TableRow>}</TableBody></Table></div></UserPanel>
        {data.status === "deleted" && <CampaignHistory data={data} />}
      </TabsContent>
      <TabsContent value="creatives" className="m-0"><AdminCampaignCreatives campaign={data} /></TabsContent>
      <TabsContent value="delivery" className="m-0"><AdminCampaignDelivery campaign={data} /></TabsContent>
      {data.can_delete && <TabsContent value="actions" className="m-0 space-y-6"><UserPanel title="Delete campaign"><p className="text-sm leading-6 text-muted-foreground">Stop future ad delivery and remove this campaign from the advertiser’s workspace. Campaign, creative, financial and reporting history is retained.</p><p className="text-xs text-muted-foreground">Deletion is available to Owners and Ad Operations. Deleted campaigns remain visible to authorized admins as read-only records.</p><Button variant="destructive" onClick={() => setDeleteOpen(true)}>Delete campaign</Button></UserPanel><CampaignHistory data={data} /></TabsContent>}
    </Tabs>
    <p className="text-xs text-muted-foreground">Updated {userDateTime(data.generated_at)}.</p>
    {deleteOpen && <DeleteCampaignDialog campaign={data} onClose={() => setDeleteOpen(false)} onDeleted={updated => { replaceData({ data: updated }); setDeleteOpen(false); setTab("summary"); setNotice("Campaign deleted. Historical records have been retained."); }} />}
  </div>;
}

function BudgetUsage({ label, cap, used, remaining }: { label: string; cap: number | null; used: number; remaining: number | null }) {
  const percent = cap === null ? 0 : cap <= 0 ? 100 : Math.min(100, Math.max(0, used / cap * 100));
  return <div className="space-y-3"><dl><UserField label={label}>{cap === null ? "Unlimited" : formatMicros(cap)} / {formatMicros(used)}</UserField></dl>{cap !== null && <Progress aria-label={label + " used"} value={percent} className="h-1.5" />}<p className="text-xs text-muted-foreground">{cap === null ? "No budget limit" : percent.toFixed(1) + "% · " + formatMicros(remaining ?? 0) + " remaining"}</p></div>;
}

function CampaignHistory({ data }: { data: AdminCampaignDetail }) {
  return <UserPanel title="Admin activity">{data.history.length ? <ul className="space-y-4">{data.history.map(event => <li key={event.id} className="text-sm"><p className="font-medium">Campaign {event.action}</p><p className="mt-1 text-xs text-muted-foreground">{event.actor_name} · {userDateTime(event.created_at)}</p></li>)}</ul> : <p className="text-sm text-muted-foreground">No administrative changes yet.</p>}<p className="text-xs leading-5 text-muted-foreground">Administrative actions are recorded with the acting admin and timestamp. Advertiser changes remain separate.</p></UserPanel>;
}

function DeleteCampaignDialog({ campaign, onClose, onDeleted }: { campaign: AdminCampaignDetail; onClose: () => void; onDeleted: (data: AdminCampaignDetail) => void }) {
  const router = useRouter();
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError("");
    try { const result = await deleteAdminCampaign(campaign.id, name); onDeleted(result.data); }
    catch (error) { if (error instanceof ApiError && error.status === 401) { clearToken(); router.replace("/login"); return; } setError(error instanceof Error ? error.message : "Unable to delete campaign."); }
    finally { setBusy(false); }
  }
  return <Dialog open onOpenChange={open => { if (!open && !busy) onClose(); }}><DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-lg"><DialogHeader><DialogTitle>Delete campaign</DialogTitle><DialogDescription>Delete {campaign.name} while retaining its history.</DialogDescription></DialogHeader><form className="space-y-4" onSubmit={submit}><div className="space-y-2 pt-2"><Label htmlFor="campaign-delete-confirm">Type {campaign.name} to confirm</Label><Input id="campaign-delete-confirm" className="h-8 py-1 text-sm" value={name} onChange={event => setName(event.target.value)} disabled={busy} autoComplete="off" required maxLength={255} /></div><p className="text-xs leading-5 text-muted-foreground">Future ad delivery will stop. Existing creative, ledger and reporting records are retained. The campaign will be read-only and cannot be restored in this MVP.</p>{error && <p role="alert" className="text-sm text-destructive">{error}</p>}<DialogFooter><Button type="button" variant="outline" disabled={busy} onClick={onClose}>Cancel</Button><Button variant="destructive" disabled={busy || name !== campaign.name}>{busy ? "Deleting…" : "Delete campaign"}</Button></DialogFooter></form></DialogContent></Dialog>;
}
