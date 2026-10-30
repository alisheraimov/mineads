"use client";
import { useCallback } from "react";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { adminPublisherList } from "@/lib/admin-servers";
import { formatMicros } from "@/lib/advertiser-format";
import { userDateTime } from "@/lib/admin-users";
import { useAdminQuery } from "./use-admin-query";
import { ServerBreadcrumb, ServerState, PublisherLink, useServerFilters } from "./admin-server-shared";
import { ServerSearch } from "./admin-server-list";
import { CampaignPagination } from "./admin-campaign-shared";
import { UsersError, UsersLoading } from "./user-shared";
export function AdminPublisherList() {
 const {params,query,filter}=useServerFilters();
 const loader=useCallback((signal:AbortSignal)=>adminPublisherList(query,signal),[query]);
 const {data,error,loading,refresh}=useAdminQuery(loader,query);
 return <div className="space-y-6"><ServerBreadcrumb publishers/><div className="flex flex-wrap items-center justify-between gap-4"><div><h1 className="text-2xl font-semibold">Publishers</h1><p className="mt-2 text-sm text-muted-foreground">Publisher accounts and server activity across the marketplace.</p></div><Button variant="outline" size="sm" disabled={loading} onClick={refresh}>Refresh</Button></div>
 <Tabs value={params.get("status")??"all"} onValueChange={v=>filter("status",v)}><TabsList><TabsTrigger value="all">All publishers</TabsTrigger><TabsTrigger value="active">Active</TabsTrigger><TabsTrigger value="suspended">Suspended</TabsTrigger><TabsTrigger value="deleted">Deleted</TabsTrigger></TabsList></Tabs>
 <ServerSearch value={params.get("search")??""} onChange={v=>filter("search",v)} placeholder="Search publisher name, email or ID"/>
 {error&&<UsersError message={error} retry={refresh}/>}
 {!data?!error&&<UsersLoading/>:<><div className="overflow-hidden rounded-lg border bg-card" aria-busy={loading}><Table className="text-xs [&_tr>:first-child]:pl-5 [&_tr>:last-child]:pr-5"><TableHeader className="bg-muted"><TableRow>{["Publisher","Email","Status","Servers","Active servers",...(data.can_view_money?["Total earnings"]:[]),"Last heartbeat"].map(h=><TableHead key={h}>{h}</TableHead>)}</TableRow></TableHeader><TableBody>{data.data.map(p=><TableRow key={p.id}><TableCell className="py-4"><PublisherLink publisher={p} canLink={data.can_view_publisher}/><p className="mt-1 text-muted-foreground">U-{p.id}</p></TableCell><TableCell>{p.email}</TableCell><TableCell><ServerState value={p.status}/></TableCell><TableCell><Link className="underline-offset-4 hover:underline" href={"/admin/servers?publisher="+p.id}>{p.servers_count} servers</Link></TableCell><TableCell><Link className="underline-offset-4 hover:underline" href={"/admin/servers?publisher="+p.id+"&status=active&review=approved"}>{p.active_servers_count} active</Link></TableCell>{data.can_view_money&&<TableCell>{formatMicros(p.earnings_micros??0)}</TableCell>}<TableCell className="text-muted-foreground">{p.last_heartbeat_at?userDateTime(p.last_heartbeat_at):"Never"}</TableCell></TableRow>)}{data.data.length===0&&<TableRow><TableCell colSpan={7} className="py-14 text-center text-muted-foreground">No matching publishers.</TableCell></TableRow>}</TableBody></Table></div><CampaignPagination meta={data.meta} noun="publishers" onPage={v=>filter("page",String(v),false)} perPage={params.get("per_page")??"10"} onPerPage={v=>filter("per_page",v)}/></>}
 <p className="text-xs text-muted-foreground">Server counts open the filtered server list.{data?.can_view_money&&" Earnings are lifetime validated earnings, including retained server history."}</p></div>;
}


