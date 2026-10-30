"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { UserSelect, UsersError, UsersLoading } from "./user-shared";
import { CampaignPagination } from "./admin-campaign-shared";
import { adminServerList, stateLabel } from "@/lib/admin-servers";
import { userDateTime } from "@/lib/admin-users";
import { serverCategories } from "@/lib/servers";
import { useAdminQuery } from "./use-admin-query";
import { PublisherFilter, PublisherLink, ServerBreadcrumb, ServerState, useServerFilters } from "./admin-server-shared";

export function AdminServerList() {
 const {params,query,filter,router,pathname}=useServerFilters();
 const loader=useCallback((signal:AbortSignal)=>adminServerList(query,signal),[query]);
 const {data,error,loading,refresh}=useAdminQuery(loader,query);
 const review=params.get("review")??"all";
 return <div className="space-y-6"><ServerBreadcrumb/><div className="flex flex-wrap items-center justify-between gap-4"><div><h1 className="text-2xl font-semibold">Servers</h1><p className="mt-2 text-sm text-muted-foreground">Inspect publisher servers, review submissions and monitor connection health.</p></div><Button variant="outline" size="sm" disabled={loading} onClick={refresh}><RefreshCw className={loading?"animate-spin":""}/>Refresh</Button></div>
 <Tabs value={review} onValueChange={v=>{filter("review",v);}}><TabsList className="h-auto max-w-full flex-wrap">{["all","pending_review","approved","rejected","awaiting_activation"].map(v=><TabsTrigger key={v} value={v}>{v==="all"?"All servers":stateLabel(v)}{data&&" · "+data.counts[v]}</TabsTrigger>)}</TabsList></Tabs>
 <div className="flex flex-wrap items-center gap-3"><ServerSearch value={params.get("search")??""} onChange={v=>filter("search",v)} placeholder="Search name, host or server ID"/><PublisherFilter value={params.get("publisher")??"all"} onChange={v=>filter("publisher",v)}/><UserSelect label="Filter connection" value={params.get("connection")??"all"} onChange={v=>filter("connection",v)} options={["all","online","offline","never_connected"].map(value=>({value,label:value==="all"?"All connection states":stateLabel(value)}))}/><UserSelect label="Filter category" value={params.get("category")??"all"} onChange={v=>filter("category",v)} options={[{value:"all",label:"All categories"},...serverCategories.map(value=>({value,label:value==="smp"?"SMP":stateLabel(value)}))]}/><UserSelect label="Filter operational status" value={params.get("status")??"all"} onChange={v=>filter("status",v)} options={["all","active","inactive","suspended","pending"].map(value=>({value,label:value==="all"?"All server states":stateLabel(value)}))}/>
 {review==="all"&&<label className="flex items-center gap-2 text-xs"><Checkbox checked={params.get("include_deleted")==="1"} onCheckedChange={v=>filter("include_deleted",v?"1":"")}/>Include deleted</label>}
 {params.size>0&&<Button variant="ghost" size="sm" onClick={()=>router.replace(pathname)}>Clear filters</Button>}</div>
 <p className="text-xs text-muted-foreground">{data?.meta.total??"—"} matching servers · {review==="pending_review"?"Oldest submitted first":"Most recently added first"}</p>
 {error&&<UsersError message={error} retry={refresh}/>}
 {!data?!error&&<UsersLoading/>:<><div className="overflow-hidden rounded-lg border bg-card" aria-busy={loading}><Table className="min-w-[1080px] text-xs [&_tr>:first-child]:pl-5 [&_tr>:last-child]:pr-5"><TableHeader className="bg-muted"><TableRow>{["Server","Publisher","Host / Category","Review / Status","Connection","Last seen","Players","Versions","Action"].map(h=><TableHead key={h}>{h}</TableHead>)}</TableRow></TableHeader><TableBody>{data.data.map(s=><TableRow key={s.id}><TableCell className="max-w-52 py-4 whitespace-normal"><Link className="font-medium hover:underline" href={"/admin/servers/"+s.id}>{s.name}</Link><p className="mt-1 text-muted-foreground">SRV-{s.id}</p></TableCell><TableCell className="max-w-36 whitespace-normal"><PublisherLink publisher={s.publisher} canLink={data.can_view_publisher}/></TableCell><TableCell className="max-w-44 whitespace-normal"><p className="break-all">{s.host}</p><p className="mt-1 capitalize text-muted-foreground">{s.category}</p></TableCell><TableCell><div className="flex flex-col items-start gap-2"><ServerState value={s.review_status}/><span className="text-muted-foreground">{stateLabel(s.status)}</span></div></TableCell><TableCell><ServerState value={s.connection}/></TableCell><TableCell className="max-w-32 whitespace-normal text-muted-foreground">{s.last_seen_at?userDateTime(s.last_seen_at):"Never"}</TableCell><TableCell>{s.online_players===null?"—":s.online_players.toLocaleString()+(s.max_players!==null?" / "+s.max_players:"")}</TableCell><TableCell className="text-muted-foreground"><p>MC {s.minecraft_version??"—"}</p><p className="mt-1">Plugin {s.plugin_version??"—"}</p></TableCell><TableCell><Button asChild variant="outline" size="sm"><Link href={"/admin/servers/"+s.id}>{s.review_status==="pending_review"&&!s.has_deleted_parent&&s.status!=="deleted"?"Review":"View"}</Link></Button></TableCell></TableRow>)}{data.data.length===0&&<TableRow><TableCell colSpan={9} className="py-14 text-center text-muted-foreground">{review==="pending_review"?"No servers awaiting review.":"No matching servers. Adjust or clear the filters."}</TableCell></TableRow>}</TableBody></Table></div><CampaignPagination meta={data.meta} noun="servers" onPage={v=>filter("page",String(v),false)} perPage={params.get("per_page")??"10"} onPerPage={v=>filter("per_page",v)}/></>}
 <p className="text-xs text-muted-foreground">Online = heartbeat within 5 minutes · Activation, approval and an active publisher are required for advertising.</p></div>;
}
export function ServerSearch({value,onChange,placeholder}:{value:string;onChange:(v:string)=>void;placeholder:string}) {
 const [draft,setDraft]=useState(value);const timer=useRef<ReturnType<typeof setTimeout>|null>(null);const sent=useRef<string|null>(null);
 useEffect(()=>{if(sent.current===value)sent.current=null;else setDraft(value);},[value]);
 useEffect(()=>()=>{if(timer.current)clearTimeout(timer.current);},[]);
 return <Input aria-label={placeholder} className="h-9 max-w-80 bg-background" value={draft} placeholder={placeholder} maxLength={255} onChange={e=>{const next=e.target.value;setDraft(next);if(timer.current)clearTimeout(timer.current);timer.current=setTimeout(()=>{sent.current=next;onChange(next);},300);}}/>;
}

