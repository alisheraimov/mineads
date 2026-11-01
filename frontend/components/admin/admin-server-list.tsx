"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { Info, RefreshCw, SlidersHorizontal, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import { Popover, PopoverTrigger, PopoverContent } from "@/components/ui/popover";
import { Tooltip, TooltipTrigger, TooltipContent } from "@/components/ui/tooltip";
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
 const updateFilters=(values:Record<string,string>)=>{const next=new URLSearchParams(query);for(const [key,value] of Object.entries(values)){if(value&&value!=="all")next.set(key,value);else next.delete(key);}next.delete("page");router.replace(pathname+(next.size?"?"+next:""),{scroll:false});};
 const chips=[
  ...["search","publisher","connection","category","status","review"].flatMap(key=>{const value=params.get(key);if(!value||value==="all")return [];return [{key,label:key==="search"?"Search: "+value:key==="publisher"?"Publisher: U-"+value:(key==="status"?"Access: ":key==="connection"?"Connection: ":key==="category"?"Category: ":"Review: ")+(value==="smp"?"SMP":stateLabel(value)),clear:()=>updateFilters({[key]:""})}];}),
  ...(params.get("include_deleted")==="1"?[{key:"include_deleted",label:"Include deleted",clear:()=>updateFilters({include_deleted:""})}]:[]),
 ];
 const secondaryCount=["connection","category","status"].filter(key=>params.has(key)&&params.get(key)!=="all").length+Number(params.get("include_deleted")==="1");
 return <div className="space-y-6"><ServerBreadcrumb/><div><h1 className="text-2xl font-semibold">Servers</h1><p className="mt-2 text-sm text-muted-foreground">Inspect publisher servers, review submissions and monitor connection health.</p></div>
 <div className="space-y-3"><div className="flex flex-wrap items-center gap-3"><ServerSearch value={params.get("search")??""} onChange={v=>filter("search",v)} placeholder="Search name, host or server ID"/><PublisherFilter value={params.get("publisher")??"all"} onChange={v=>filter("publisher",v)}/>
 <Popover><PopoverTrigger asChild><Button variant="outline" className="h-9"><SlidersHorizontal className="size-4"/>Filters{secondaryCount>0&&<span className="rounded bg-muted px-1.5 text-xs tabular-nums">{secondaryCount}</span>}</Button></PopoverTrigger><PopoverContent align="start" className="w-80 space-y-4"><h2 className="text-sm font-medium">Filter servers</h2>
 <div className="space-y-2"><p className="text-sm font-medium">Connection</p><UserSelect className="w-full bg-background data-[size=default]:h-9" label="Filter connection" value={params.get("connection")??"all"} onChange={v=>filter("connection",v)} options={["all","online","offline","never_connected"].map(value=>({value,label:value==="all"?"All connection states":stateLabel(value)}))}/></div>
 <div className="space-y-2"><p className="text-sm font-medium">Category</p><UserSelect className="w-full bg-background data-[size=default]:h-9" label="Filter category" value={params.get("category")??"all"} onChange={v=>filter("category",v)} options={[{value:"all",label:"All categories"},...serverCategories.map(value=>({value,label:value==="smp"?"SMP":stateLabel(value)}))]}/></div>
 <div className="space-y-2"><p className="text-sm font-medium">Server access</p><UserSelect className="w-full bg-background data-[size=default]:h-9" label="Filter operational status" value={params.get("status")??"all"} onChange={v=>filter("status",v)} options={["all","active","inactive","suspended","pending"].map(value=>({value,label:value==="all"?"All server states":stateLabel(value)}))}/></div>
 <div className="space-y-2"><label className="flex items-center gap-2 text-sm"><Checkbox disabled={review!=="all"} checked={params.get("include_deleted")==="1"} onCheckedChange={v=>filter("include_deleted",v?"1":"")}/>Include deleted</label>{review!=="all"&&<p className="text-xs text-muted-foreground">Available in All servers.</p>}</div>
 {secondaryCount>0&&<Button variant="ghost" size="sm" onClick={()=>updateFilters({connection:"",category:"",status:"",include_deleted:""})}>Reset filters</Button>}</PopoverContent></Popover>
 <Button className="ml-auto h-9" variant="outline" size="sm" disabled={loading} onClick={refresh}><RefreshCw className={loading?"animate-spin":""}/>Refresh</Button></div>
 {chips.length>0&&<div className="flex flex-wrap items-center gap-2" aria-label="Active filters">{chips.map(chip=><Button key={chip.key} variant="outline" size="sm" className="h-7 max-w-full gap-1.5 rounded-full px-2.5 text-xs font-normal" onClick={chip.clear} aria-label={"Remove "+chip.label+" filter"}><span className="truncate">{chip.label}</span><X className="size-3 shrink-0"/></Button>)}<Button variant="ghost" size="sm" className="h-7 px-2 text-xs" onClick={()=>updateFilters({search:"",publisher:"",connection:"",category:"",status:"",review:"",include_deleted:""})}>Clear filters</Button></div>}
 </div>
 <div className="flex flex-wrap items-center justify-between gap-3"><Tabs value={review} onValueChange={v=>updateFilters({review:v,include_deleted:v==="all"?params.get("include_deleted")??"":""})}><TabsList className="h-auto max-w-full flex-wrap">{["all","pending_review","approved","rejected","awaiting_activation"].map(v=><TabsTrigger key={v} value={v}>{v==="all"?"All servers":stateLabel(v)}{data&&" · "+data.counts[v]}</TabsTrigger>)}</TabsList></Tabs><span className="text-xs text-muted-foreground">{review==="pending_review"?"Oldest submitted first":"Newest first"}</span></div>
 {error&&<UsersError message={error} retry={refresh}/>}
 {!data?!error&&<UsersLoading/>:<><div className="overflow-hidden rounded-lg border bg-card" aria-busy={loading}><Table className="min-w-[960px] text-xs [&_tr>:first-child]:pl-5 [&_tr>:last-child]:pr-5"><TableHeader className="bg-muted"><TableRow>{["Server","Publisher","Host / Category","Approval / Access","Connection","Players","Action"].map(h=><TableHead key={h}>{h}</TableHead>)}</TableRow></TableHeader><TableBody>{data.data.map(s=><TableRow key={s.id}><TableCell className="max-w-52 py-4 whitespace-normal"><Link className="font-medium hover:underline" href={"/admin/servers/"+s.id}>{s.name}</Link><p className="mt-1 text-muted-foreground">SRV-{s.id}</p></TableCell><TableCell className="max-w-36 whitespace-normal"><PublisherLink publisher={s.publisher} canLink={data.can_view_publisher}/></TableCell><TableCell className="max-w-44 whitespace-normal"><p className="break-all">{s.host}</p><p className="mt-1 capitalize text-muted-foreground">{s.category}</p></TableCell><TableCell><div className="flex flex-col items-start gap-2"><ServerState value={s.review_status}/><span className="text-muted-foreground">Access: {stateLabel(s.status)}</span></div></TableCell><TableCell className="max-w-48 whitespace-normal"><ServerState value={s.connection}/>{s.last_seen_at&&<p className="mt-2 text-muted-foreground">Last heartbeat {userDateTime(s.last_seen_at)}</p>}</TableCell><TableCell>{s.online_players===null?"—":s.online_players.toLocaleString()+(s.max_players!==null?" / "+s.max_players:"")}</TableCell><TableCell><Button asChild variant="outline" size="sm"><Link href={"/admin/servers/"+s.id}>{s.review_status==="pending_review"&&!s.has_deleted_parent&&s.status!=="deleted"?"Review":"View"}</Link></Button></TableCell></TableRow>)}{data.data.length===0&&<TableRow><TableCell colSpan={7} className="py-14 text-center text-muted-foreground">{review==="pending_review"?"No servers awaiting review.":"No matching servers. Adjust or clear the filters."}</TableCell></TableRow>}</TableBody></Table></div><CampaignPagination meta={data.meta} noun="servers" onPage={v=>filter("page",String(v),false)} perPage={params.get("per_page")??"10"} onPerPage={v=>filter("per_page",v)}/></>}
 <Tooltip><TooltipTrigger asChild><Button variant="ghost" size="sm" className="h-7 px-0 text-xs text-muted-foreground"><Info className="size-3.5"/>About connection and delivery</Button></TooltipTrigger><TooltipContent className="max-w-80">Online means a heartbeat within 5 minutes. Plugin activation, approval and an active publisher are required for advertising. Minecraft and plugin versions are available in server details.</TooltipContent></Tooltip></div>;
}
export function ServerSearch({value,onChange,placeholder}:{value:string;onChange:(v:string)=>void;placeholder:string}) {
 const [draft,setDraft]=useState(value);const timer=useRef<ReturnType<typeof setTimeout>|null>(null);const sent=useRef<string|null>(null);
 useEffect(()=>{if(sent.current===value)sent.current=null;else setDraft(value);},[value]);
 useEffect(()=>()=>{if(timer.current)clearTimeout(timer.current);},[]);
 return <Input aria-label={placeholder} className="h-9 max-w-80 bg-background" value={draft} placeholder={placeholder} maxLength={255} onChange={e=>{const next=e.target.value;setDraft(next);if(timer.current)clearTimeout(timer.current);timer.current=setTimeout(()=>{sent.current=next;onChange(next);},300);}}/>;
}

