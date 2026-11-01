"use client";

import { useCallback, useState } from "react";
import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";
import { useRouter } from "@/lib/navigation";
import { RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { CampaignError, CampaignLoading } from "@/components/advertiser/campaign-shared";
import { ChatPreview } from "@/components/advertiser/creative-shared";
import { displayLanguage } from "@/lib/advertiser-format";
import { userDateTime } from "@/lib/admin-users";
import { ApiError, clearToken } from "@/lib/auth";
import { changeCreative, getAdminCreative, type CreativeDetail } from "@/lib/admin-creatives";
import { UserField, UserPanel } from "./user-shared";
import { AdvertiserIdentity, CampaignState } from "./admin-campaign-shared";
import { CreativeBreadcrumb } from "./admin-creative-shared";
import { useAdminQuery } from "./use-admin-query";
import { CreativePeriod } from "./creative-period";
import { CreativeMetrics, CreativePerformance } from "./admin-creative-performance";
import { CreativeActionDialog, type CreativeAction } from "./creative-action-dialog";

const eventLabels: Record<string, string> = { approved: "Approved", rejected: "Rejected", returned_to_review: "Returned to review", deleted: "Deleted", resubmitted: "Resubmitted for review" };
export function AdminCreativeDetails({ id }: { id: string }) {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const query = params.toString();
  const loader = useCallback((signal: AbortSignal) => getAdminCreative(id, query, signal), [id, query]);
  const { data: response, error, loading, refresh, replaceData } = useAdminQuery(loader, id + ":" + query);
  const data = response?.data;
  const [action, setAction] = useState<CreativeAction | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");
  const [actionError, setActionError] = useState("");
  const [conflictVersion, setConflictVersion] = useState<string | null>(null);
  const conflict = !!data && conflictVersion === data.version;
  function saved(updated: CreativeDetail, message: string) { replaceData({ data: updated }); setAction(null); setNotice(message); setActionError(""); setConflictVersion(null); }
  async function approve() {
    if (!data) return;
    setBusy(true); setActionError(""); setNotice("");
    try { const result = await changeCreative(data.id, query, { version: data.version, action: "approve" }); saved(result.data, result.message); }
    catch (error) {
      if (error instanceof ApiError && error.status === 401) { clearToken(); router.replace("/login"); return; }
      if (error instanceof ApiError && error.status === 409) setConflictVersion(data.version);
      setActionError(error instanceof Error ? error.message : "Unable to approve creative.");
    } finally { setBusy(false); }
  }
  if (!data) return <div className="space-y-6"><CreativeBreadcrumb />{error ? <CampaignError message={error} retry={refresh} /> : <CampaignLoading label="Loading creative" />}</div>;
  const deletedEvent = data.history.find(event => event.action === "deleted");
  return <div className="space-y-6"><CreativeBreadcrumb id={data.id} /><div className="flex flex-wrap items-center justify-between gap-4"><div><h1 className="text-2xl font-semibold">Creative CR-{data.id}</h1><p className="mt-2 text-sm text-muted-foreground">{data.campaign?.name ?? "Campaign unavailable"} · {data.advertiser?.name ?? "Advertiser unavailable"}</p></div><div className="flex gap-2"><Button variant="outline" size="icon-sm" aria-label="Refresh creative" disabled={loading || busy} onClick={refresh}><RefreshCw className={loading ? "animate-spin" : ""} /></Button><Button asChild variant="outline" size="sm"><Link href="/admin/creatives">Back to creatives</Link></Button></div></div>
    <div className="flex flex-wrap items-center gap-3"><CampaignState status={data.status} /><p className="text-xs text-muted-foreground">Submitted {userDateTime(data.submitted_at)}</p>{data.is_paused && <Badge variant="secondary">Paused by advertiser</Badge>}</div>
    {error && <CampaignError message={"Showing previously loaded data. " + error} retry={refresh} />}
    {notice && <p role="status" className="rounded-lg border bg-card p-4 text-sm">{notice}</p>}
    {(actionError || conflict) && <Alert variant="destructive"><AlertTitle>Review could not be saved</AlertTitle><AlertDescription><p>{actionError || "This creative changed. Reload the latest content before deciding."}</p><Button variant="outline" size="sm" disabled={loading} onClick={() => { setAction(null); refresh(); }}>Reload creative</Button></AlertDescription></Alert>}
    {!data.can_moderate && data.status !== "deleted" && !data.has_deleted_parent && <Alert><AlertTitle>Read-only access</AlertTitle><AlertDescription>Support can inspect content and delivery counts. Financial information and moderation controls are hidden.</AlertDescription></Alert>}
    {data.has_deleted_parent && <Alert><AlertTitle>Campaign or advertiser deleted</AlertTitle><AlertDescription>This creative is retained as read-only history and is excluded from the review queue.</AlertDescription></Alert>}
    {data.status === "deleted" && <Alert><AlertTitle>Creative deleted · Read-only history</AlertTitle><AlertDescription>Deleted {deletedEvent ? "by " + deletedEvent.actor_name + " " : ""}{data.deleted_at ? "on " + userDateTime(data.deleted_at) : ""}. Future serving is stopped; content, review and reporting history are retained.</AlertDescription></Alert>}
    <CreativePeriod value={params.get("period") ?? "last30"} period={data.period} onChange={(value, range) => { const next = new URLSearchParams({ period: value }); if (range) { next.set("from", range.from); next.set("to", range.to); } setNotice(""); router.replace(pathname + "?" + next, { scroll: false }); }} />
    <CreativeMetrics data={data} />
    <UserPanel title="Creative information"><dl className="grid gap-6 sm:grid-cols-2 xl:grid-cols-3"><UserField label="Creative ID">CR-{data.id}</UserField><UserField label="Campaign">{data.campaign ? data.can_view_campaign ? <Link href={"/admin/campaigns/" + data.campaign.id} className="hover:underline">{data.campaign.name} ↗</Link> : data.campaign.name : "Campaign unavailable"}</UserField><UserField label="Advertiser"><AdvertiserIdentity advertiser={data.advertiser} canLink={data.can_view_advertiser} email /></UserField><UserField label="Language">{data.language ? displayLanguage(data.language) : "Not specified (all languages)"}</UserField><UserField label="Created">{userDateTime(data.created_at)}</UserField><UserField label="Updated">{userDateTime(data.updated_at)}</UserField><UserField label="Destination URL">{data.click_url ?? "No destination URL"}</UserField></dl></UserPanel>
    <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_344px]"><div className="min-w-0 space-y-6"><UserPanel title="Message & chat preview"><p className="whitespace-pre-wrap break-words rounded-md border bg-muted/50 p-4 text-sm">{data.message_text}</p><p className="text-xs font-medium text-muted-foreground">Minecraft chat preview</p><ChatPreview message={data.message_text} url={data.click_url} /></UserPanel><CreativeHistory data={data} /></div>
      <UserPanel title={data.status === "deleted" ? "Retained record" : data.can_moderate ? "Moderation" : "Current decision"}>
        {data.rejection_reason && <div className="space-y-2"><p className="text-sm font-medium text-destructive">{data.rejection_code ? ({ misleading_content: "Misleading content", inappropriate_content: "Inappropriate content", suspicious_url: "Broken or suspicious URL", unsupported_language: "Unsupported language", formatting_issue: "Formatting issue", policy_violation: "Policy violation", other: "Other" }[data.rejection_code] ?? data.rejection_reason) : data.rejection_reason}</p>{data.rejection_explanation && <p className="whitespace-pre-wrap break-words text-sm text-muted-foreground">{data.rejection_explanation}</p>}</div>}
        {data.can_moderate ? <><p className="text-sm leading-6 text-muted-foreground">{data.status === "pending_review" ? "Review the message, language and destination before deciding. Approval allows serving when the campaign and account are eligible." : data.status === "approved" ? "Approved for serving, subject to campaign, account, schedule and advertiser pause settings." : "The advertiser can correct the content and resubmit it for review."}</p><div className="space-y-3">{data.status === "pending_review" ? <Button className="w-full" disabled={busy || loading || conflict} onClick={approve}>{busy ? "Approving…" : "Approve creative"}</Button> : <Button className="w-full" variant="outline" disabled={busy || loading || conflict} onClick={() => setAction("return_to_review")}>Return to review</Button>}{data.status !== "rejected" && <Button className="w-full" variant="outline" disabled={busy || loading || conflict} onClick={() => setAction("reject")}>Reject creative</Button>}</div><div className="space-y-3 border-t pt-5"><h3 className="text-sm font-medium">Delete creative</h3><p className="text-xs leading-5 text-muted-foreground">Stop future serving and retain content, review and financial history.</p><Button className="w-full" variant="destructive" disabled={busy || loading || conflict} onClick={() => setAction("delete")}>Delete creative</Button></div></> : <p className="text-sm leading-6 text-muted-foreground">{data.status === "deleted" || data.has_deleted_parent ? "No further moderation or deletion actions are available." : "Review decisions can be changed by Owners and Ad Operations."}</p>}
      </UserPanel></div>
    <CreativePerformance data={data} /><p className="text-xs text-muted-foreground">Updated {userDateTime(data.generated_at)}.</p>
    {action && <CreativeActionDialog key={action + data.version} data={data} action={action} query={query} onClose={() => setAction(null)} onSaved={saved} onConflict={() => setConflictVersion(data.version)} />}
  </div>;
}
function CreativeHistory({ data }: { data: CreativeDetail }) {
  return <UserPanel title="Review history"><ul className="space-y-5">{data.history.map(event => <li key={event.id} className="text-sm"><p className="font-medium">{eventLabels[event.action] ?? event.action}</p><p className="mt-1 text-xs text-muted-foreground">{event.actor_name} · {userDateTime(event.created_at)}</p>{event.details.reason && <p className="mt-2 whitespace-pre-wrap break-words text-sm text-muted-foreground">{event.details.reason}</p>}{event.action !== "deleted" && <details className="mt-2 text-xs text-muted-foreground"><summary className="cursor-pointer">Content at this decision</summary><p className="mt-2 whitespace-pre-wrap break-words">{event.details.message_text}</p><p className="mt-1 break-all">{event.details.click_url ?? "No destination URL"} · {event.details.language ?? "All languages"}</p></details>}</li>)}<li className="text-xs text-muted-foreground">Initially submitted · {data.advertiser?.name ?? "Advertiser"} · {userDateTime(data.created_at)}</li></ul></UserPanel>;
}

