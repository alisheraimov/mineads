"use client";
import { useCallback, useState } from "react";
import Link from "next/link";
import { formatDistanceToNowStrict } from "date-fns";
import { CircleCheck, CirclePause, Clock3, Unplug } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from "@/components/ui/dialog";
import { adminServerDetail, adminServerAction, adminServerHistory, stateLabel, type AdminServerDetail, type ServerAction, type ServerEvent } from "@/lib/admin-servers";
import { ApiError } from "@/lib/auth";
import { userDateTime } from "@/lib/admin-users";
import { useAdminQuery } from "./use-admin-query";
import { UserPanel, UserField, UsersError, UsersLoading } from "./user-shared";
import { ServerBreadcrumb, ServerState, PublisherLink, useServerFilters } from "./admin-server-shared";
import { CampaignPagination } from "./admin-campaign-shared";
import { AdminServerActivity } from "./admin-server-activity";
export function AdminServerDetails({id}:{id:number}) {
 const {params,filter}=useServerFilters();const tab=params.get("tab")??"overview";
 const loader=useCallback((signal:AbortSignal)=>adminServerDetail(id,signal),[id]);const {data:response,error,loading,refresh,replaceData}=useAdminQuery(loader,String(id));
 const [action,setAction]=useState<ServerAction|null>(null),[notice,setNotice]=useState(""),[stale,setStale]=useState(false);
 const data=response?.data;
 if(!data)return <div className="space-y-6"><ServerBreadcrumb name={"SRV-"+id}/>{error?<UsersError message={error} retry={refresh}/>:<UsersLoading/>}</div>;
 const editable=data.can_manage&&!loading&&!stale;
 const pending=data.review_status==="pending_review"&&data.activated_at&&data.status!=="suspended";
 return <div className="space-y-6"><ServerBreadcrumb name={data.name}/><div className="flex flex-wrap items-center justify-between gap-4"><div><h1 className="text-2xl font-semibold">{data.name}</h1><p className="mt-2 text-sm text-muted-foreground">SRV-{id} · Added {userDateTime(data.created_at)} · <PublisherLink publisher={data.publisher} canLink={data.can_view_publisher}/></p></div><div className="flex gap-2"><Button variant="outline" size="sm" onClick={()=>{setStale(false);refresh();}}>Refresh</Button><Button asChild variant="outline" size="sm"><Link href="/admin/servers">Back to servers</Link></Button></div></div>
 <Tabs value={tab} onValueChange={v=>filter("tab",v)}><TabsList><TabsTrigger value="overview">Overview</TabsTrigger><TabsTrigger value="activity">Activity & validation</TabsTrigger><TabsTrigger value="history">History</TabsTrigger></TabsList></Tabs>
 <ServerReadiness data={data}/>
 {notice&&<p role="status" className="text-sm">{notice}</p>}{error&&<UsersError message={error} retry={refresh}/>}
 {(data.status==="deleted"||data.has_deleted_parent)&&<Alert><AlertTitle>Retained record · Read-only</AlertTitle><AlertDescription>{data.status==="deleted"?"This server was deleted.":"The publisher was deleted."} Advertising is blocked. Reporting and review history are retained.</AlertDescription></Alert>}
 {stale&&<UsersError message="This server changed. Refresh before deciding." retry={()=>{setStale(false);refresh();}}/>}
 {tab==="overview"&&<>
 <UserPanel title={<span className="flex flex-wrap items-center justify-between gap-3"><span>{pending?"Review server":"Server approval"}</span><ServerState value={data.review_status}/></span>}>
 <p className="text-sm leading-6 text-muted-foreground">{data.status==="suspended"?data.suspension_reason??"An admin has suspended this server.":pending?"Plugin activation is complete. Visit "+data.host+", then record your decision. Advertising stays blocked until approval.":data.review_status==="rejected"?data.review_reason??"The publisher can correct the issue and resubmit for review.":data.review_status==="awaiting_activation"?"The publisher must activate the plugin before this server can be reviewed.":data.legacy_approval?"Approved before the review requirement. Future host changes require a moderator review.":"Approved for advertising when the server and publisher are otherwise eligible."}</p>
 {data.reviewed_at&&<p className="text-xs text-muted-foreground">Decision recorded {userDateTime(data.reviewed_at)}</p>}
 {data.can_manage&&pending&&<div className="flex gap-3"><Button size="sm" disabled={!editable} onClick={()=>setAction("approve")}>Approve server</Button><Button variant="outline" size="sm" disabled={!editable} onClick={()=>setAction("reject")}>Reject server</Button></div>}
 {!data.can_manage&&data.status!=="deleted"&&!data.has_deleted_parent&&<p className="text-xs text-muted-foreground">Review and operational actions are available to Owners and Ad Operations.</p>}
 </UserPanel>
 {data.publisher?.status!=="active"&&<Alert><AlertTitle>Publisher restriction</AlertTitle><AlertDescription>The publisher is {data.publisher?.status??"unavailable"}. Server approval cannot override this restriction.</AlertDescription></Alert>}
 <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1.8fr)_minmax(0,1fr)]"><UserPanel title="Server information"><dl className="grid gap-6 sm:grid-cols-2"><UserField label="Host">{data.host}</UserField><UserField label="Category"><span className="capitalize">{data.category}</span></UserField><UserField label="Publisher"><PublisherLink publisher={data.publisher} canLink={data.can_view_publisher}/></UserField><UserField label="Activated">{data.activated_at?userDateTime(data.activated_at):"Not activated"}</UserField><UserField label="Minecraft version">{data.minecraft_version??"Not reported"}</UserField><UserField label="Plugin version">{data.plugin_version??"Not reported"}{data.plugin_update_available&&<span className="ml-2 text-amber-600">Update available</span>}</UserField></dl></UserPanel>
 <UserPanel title={<span className="flex flex-wrap items-center justify-between gap-3"><span>Connection health</span><ServerState value={data.connection}/></span>}><dl className="space-y-5"><UserField label="Last heartbeat">{data.last_seen_at?<>{userDateTime(data.last_seen_at)}<p className="mt-1 text-xs text-muted-foreground">{formatDistanceToNowStrict(new Date(data.last_seen_at),{addSuffix:true})}</p></>:"Never"}</UserField><UserField label="Online players">{data.online_players===null?"Not available":data.online_players.toLocaleString()+(data.max_players!==null?" / "+data.max_players:"")}</UserField><UserField label="Activation">{data.activated_at?"Setup complete":"Not activated"}</UserField></dl><p className="text-xs leading-5 text-muted-foreground">Online means a heartbeat within 5 minutes. Player counts come from the latest fresh sample.</p></UserPanel></div>
 <UserPanel title="Recent activity"><ServerHistoryRows events={data.history}/><Button variant="outline" size="sm" onClick={()=>filter("tab","history")}>View full history</Button></UserPanel>
 {data.can_manage&&<UserPanel title={<span className="flex flex-wrap items-center justify-between gap-3"><span>Server controls</span><Badge variant="outline">{data.status==="active"?"Advertising enabled":data.status==="disabled"?"Advertising disabled":stateLabel(data.status)}</Badge></span>}><p className="text-sm leading-6 text-muted-foreground">{data.status==="suspended"?"Lifting the suspension restores the prior enabled/disabled state. It does not override approval or publisher restrictions.":"Suspend advertising or delete this server while keeping its review, impression and earnings history."}</p><div className="flex flex-wrap gap-3"><Button variant="outline" size="sm" disabled={!editable} onClick={()=>setAction(data.status==="suspended"?"reactivate":"suspend")}>{data.status==="suspended"?"Reactivate server":"Suspend server"}</Button><Button variant="destructive" size="sm" disabled={!editable} onClick={()=>setAction("delete")}>Delete server</Button></div></UserPanel>}
 </>}
 {tab==="activity"&&<AdminServerActivity id={id} money={data.can_view_money}/>}
 {tab==="history"&&<FullServerHistory id={id}/>}
 {action&&<ServerActionDialog key={action+data.version} data={data} action={action} close={()=>setAction(null)} conflict={()=>{setStale(true);setAction(null);refresh();}} saved={result=>{replaceData({data:result.data});setAction(null);setNotice(result.message);}}/>}
 </div>;
}
function ServerReadiness({ data }: { data: AdminServerDetail }) {
 const blocked = data.status === "deleted" || data.has_deleted_parent ? { title: "Retained server record", detail: "This record is read-only. Advertising is stopped.", icon: CirclePause }
  : data.status === "suspended" ? { title: "Advertising suspended", detail: data.suspension_reason ?? "An admin has suspended this server. Review approval is retained separately.", icon: CirclePause }
  : data.publisher?.status !== "active" ? { title: "Publisher account restricted", detail: "Advertising is blocked by the publisher’s account status.", icon: CirclePause }
  : !data.activated_at ? { title: "Waiting for plugin activation", detail: "Complete plugin setup before this server can be reviewed or receive ads.", icon: Clock3 }
  : data.review_status === "rejected" ? { title: "Server review rejected", detail: "The publisher must address the review feedback and resubmit before advertising can start.", icon: CirclePause }
  : data.review_status !== "approved" ? { title: "Waiting for server review", detail: "The plugin is activated. A moderator must approve the server before advertising can start.", icon: Clock3 }
  : data.status === "disabled" ? { title: "Advertising disabled", detail: "The server is approved, but its publisher has disabled advertising.", icon: CirclePause }
  : data.connection !== "online" ? { title: data.connection === "never_connected" ? "Waiting for the first heartbeat" : "Server is offline", detail: data.connection === "never_connected" ? "The server is approved and advertising is enabled. The plugin has not sent a heartbeat yet." : "The server is approved and advertising is enabled, but no heartbeat has arrived within 5 minutes.", icon: Unplug }
  : null;
 const Icon = blocked?.icon ?? CircleCheck;
 return <section aria-label="Server readiness" className="flex items-start gap-3 rounded-lg border bg-card px-4 py-4"><span className={"mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full " + (blocked ? "bg-muted text-muted-foreground" : "bg-emerald-500/10 text-emerald-700 dark:text-emerald-400")}><Icon className="size-4" aria-hidden="true"/></span><div className="min-w-0"><p className="text-sm font-medium">{blocked?.title ?? "Ready for advertising"}</p><p className="mt-1 text-xs leading-5 text-muted-foreground">{blocked?.detail ?? "Review approved, advertising enabled and plugin online. Ads can be delivered when matching demand is available."}</p></div></section>;
}
function ServerActionDialog({data,action,close,saved,conflict}:{data:AdminServerDetail;action:ServerAction;close:()=>void;saved:(r:{data:AdminServerDetail;message:string})=>void;conflict:()=>void}) {
 const [reason,setReason]=useState(""),[confirmation,setConfirmation]=useState(""),[busy,setBusy]=useState(false),[error,setError]=useState(""),[changed,setChanged]=useState(false);
 const needReason=action==="reject"||action==="suspend",label=stateLabel(action)+" server";
 async function confirm(){setBusy(true);setError("");try{saved(await adminServerAction(data,action,reason.trim(),confirmation));}catch(err){setError(err instanceof Error?err.message:"Unable to save.");if(err instanceof ApiError&&err.status===409){setChanged(true);conflict();}}finally{setBusy(false);}}
 return <Dialog open onOpenChange={open=>{if(!open&&!busy)close();}}><DialogContent className="overflow-hidden p-0 sm:max-w-lg" showCloseButton={!busy}><div className="space-y-5 p-6"><DialogHeader><DialogTitle>{label}</DialogTitle><DialogDescription>{action==="approve"?"Approve "+data.name+" at "+data.host+" after completing your in-person visit. Advertising may begin when the server and publisher are eligible.":action==="reject"?"The publisher will see your reason and can correct the issue and resubmit.":action==="suspend"?"Stop advertising on this server. The publisher will see the suspension reason. History is retained.":action==="reactivate"?"Lift the suspension and restore the prior enabled/disabled state. Review approval and publisher restrictions still apply.":"Delete "+data.name+" and stop future advertising. Impression, earnings and review history are retained."}</DialogDescription></DialogHeader>
 {needReason&&<label className="block space-y-2.5 pt-2 text-sm font-medium">Reason *<Textarea className="mt-2.5 min-h-24 font-normal" autoFocus value={reason} maxLength={2000} disabled={busy||changed} onChange={e=>setReason(e.target.value)}/></label>}
 {action==="delete"&&<label className="block space-y-2.5 pt-2 text-sm font-medium">Type SRV-{data.id} to confirm<Input className="mt-2.5 h-8" autoFocus value={confirmation} disabled={busy||changed} onChange={e=>setConfirmation(e.target.value)} autoComplete="off"/></label>}
 {error&&<p role="alert" className="text-sm text-destructive">{error}</p>}</div><DialogFooter className="m-0 border-t bg-muted/60 px-6 py-4"><Button variant="outline" size="sm" disabled={busy} onClick={close}>Cancel</Button><Button size="sm" variant={["delete","reject","suspend"].includes(action)?"destructive":"default"} disabled={busy||changed||(needReason&&!reason.trim())||(action==="delete"&&confirmation!=="SRV-"+data.id)} onClick={confirm}>{busy?"Saving…":label}</Button></DialogFooter></DialogContent></Dialog>;
}
function ServerHistoryRows({events}:{events:ServerEvent[]}) {return <ul className="space-y-4">{events.map(e=><li key={e.id} className="text-sm"><div className="flex flex-wrap gap-x-5 gap-y-1"><span className="text-xs text-muted-foreground">{userDateTime(e.created_at)}</span><span>{stateLabel(e.action)} · {e.actor_name}</span></div><p className="mt-1 break-all text-xs text-muted-foreground">{e.details.host}{e.details.previous_host&&" · Previous: "+e.details.previous_host}</p>{(e.action==="rejected"||e.action==="suspended")&&<p className="mt-2 whitespace-pre-wrap text-sm text-muted-foreground">{e.action==="rejected"?e.details.review_reason:e.details.suspension_reason}</p>}</li>)}{events.length===0&&<li className="text-sm text-muted-foreground">No recorded review or operational changes. Older servers may predate this history.</li>}</ul>;}
function FullServerHistory({id}:{id:number}) {const {params,filter}=useServerFilters();const page=params.get("page")??"1";const loader=useCallback((signal:AbortSignal)=>adminServerHistory(id,page,signal),[id,page]);const {data,error,refresh}=useAdminQuery(loader,id+page);return <div className="space-y-6">{error&&<UsersError message={error} retry={refresh}/>}<UserPanel title="Review & operational history">{data?<ServerHistoryRows events={data.data}/>:!error&&<UsersLoading/>}</UserPanel>{data&&<CampaignPagination meta={data.meta} noun="events" onPage={v=>filter("page",String(v),false)}/>}</div>;}


