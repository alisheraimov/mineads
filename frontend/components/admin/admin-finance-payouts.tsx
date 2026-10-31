"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { Info, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { DateRangePicker } from "@/components/ui/date-picker";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { getFinancePayouts, payoutStatuses, financeLabel } from "@/lib/admin-finance";
import { formatMicros } from "@/lib/advertiser-format";
import { userDate, userDateTime } from "@/lib/admin-users";
import { useAdminQuery } from "./use-admin-query";
import { useServerFilters } from "./admin-server-shared";
import { CampaignPagination } from "./admin-campaign-shared";
import { FinanceHeader, FinanceAccountFilter, FinanceExport, FinanceStatus, FinanceIdentityLink } from "./finance-shared";
import { UsersError } from "./user-shared";
import { CampaignLoading } from "@/components/advertiser/campaign-shared";

export function AdminFinancePayouts() {
 const { params, query: rawQuery, filter, router, pathname } = useServerFilters();
 const creationParams=new URLSearchParams(rawQuery);
 creationParams.delete("date_field");
 const query=creationParams.toString(), appliedSearch=params.get("search")??"";
 const [search,setSearch] = useState(params.get("search")??"");
 useEffect(()=>setSearch(appliedSearch),[appliedSearch]);
 useEffect(()=>{
  if(rawQuery!==query)router.replace(pathname+(query?"?"+query:""),{scroll:false});
 },[rawQuery,query,pathname,router]);
 useEffect(()=>{
  if(search.trim()===appliedSearch)return;
  const timer=setTimeout(()=>{
   const next=new URLSearchParams(query);
   if(search.trim())next.set("search",search.trim());else next.delete("search");
   next.delete("page");
   router.replace(pathname+(next.size?"?"+next:""),{scroll:false});
  },350);
  return ()=>clearTimeout(timer);
 },[search,appliedSearch,query,pathname,router]);
 const loader=useCallback((signal:AbortSignal)=>getFinancePayouts(query,signal),[query]);
 const {data:result,error,loading,refresh}=useAdminQuery(loader,query);
 const data=error?undefined:result, today=new Date().toISOString().slice(0,10);
 const status=params.get("status")??"all";
 const updateFilters=(values:Record<string,string>)=>{const next=new URLSearchParams(query);for(const [key,value] of Object.entries(values)){if(value&&value!=="all")next.set(key,value);else next.delete(key);}next.delete("page");router.replace(pathname+(next.size?"?"+next:""),{scroll:false});};
 const chips=[
  ...(params.get("search")?[{label:`Search: ${params.get("search")}`,clear:()=>updateFilters({search:""})}]:[]),
  ...(params.get("account")?[{label:`Publisher: U-${params.get("account")}`,clear:()=>updateFilters({account:""})}]:[]),
  ...(params.has("from")||params.has("to")?[{label:params.get("from")?`${userDate(params.get("from")??"")}${params.get("to")?` – ${userDate(params.get("to")??"")}`:" onward"}`:`Through ${userDate(params.get("to")??"")}`,clear:()=>updateFilters({from:"",to:""})}]:[]),
  ...(status!=="all"?[{label:`Status: ${financeLabel(status)}`,clear:()=>updateFilters({status:""})}]:[]),
 ];
 const total=data?(status==="all"?Object.values(data.totals).reduce((sum,t)=>({count:sum.count+t.count,amount_micros:sum.amount_micros+t.amount_micros}),{count:0,amount_micros:0}):data.totals[status as keyof typeof data.totals]):undefined;
 return <div className="space-y-6"><FinanceHeader title="Payouts" description="Review automatically prepared payouts and record external payment outcomes." loading={loading} refresh={refresh}/>
 <div className="space-y-3">
 <div className="flex flex-wrap items-center gap-3">
 <div className="min-w-0 flex-1 basis-64 sm:max-w-xs"><Label htmlFor="payout-search" className="sr-only">Find a payout</Label><Input id="payout-search" value={search} onChange={e=>setSearch(e.target.value)} maxLength={255} className="h-9 min-w-0 bg-background" placeholder="Payout ID or exact payment reference"/></div>
 <FinanceAccountFilter kind="publisher" value={params.get("account")??"all"} onChange={v=>filter("account",v)}/>
 <div className="w-72 max-w-full"><DateRangePicker id="payout-date-range" label="Payout creation date range · UTC" value={{from:params.get("from")??"",to:params.get("to")??""}} max={today} onChange={range=>updateFilters(range)}/></div>
 <div className="ml-auto"><FinanceExport path="payouts" query={query}/></div>
 </div>
 {chips.length>0&&<div className="flex flex-wrap items-center gap-2" aria-label="Active filters">{chips.map(chip=><Button key={chip.label} size="sm" variant="outline" className="h-7 max-w-full gap-1.5 rounded-full px-2.5 text-xs font-normal" onClick={chip.clear} aria-label={`Remove ${chip.label} filter`}><span className="truncate">{chip.label}</span><X className="size-3 shrink-0"/></Button>)}<Button size="sm" variant="ghost" className="h-7 px-2 text-xs" onClick={()=>updateFilters({search:"",account:"",date_field:"",from:"",to:"",status:""})}>Clear filters</Button></div>}
 </div>
 {error&&<UsersError message={error} retry={refresh}/>}
 {!data?!error&&<CampaignLoading label="Loading payouts"/>:<>
 <div className="flex flex-wrap items-center justify-between gap-3"><Tabs value={status} onValueChange={v=>filter("status",v)}><TabsList className="h-auto flex-wrap"><TabsTrigger value="all">All · {Object.values(data.totals).reduce((n,t)=>n+t.count,0).toLocaleString()}</TabsTrigger>{payoutStatuses.map(s=><TabsTrigger key={s} value={s}>{financeLabel(s)} · {data.totals[s].count.toLocaleString()}</TabsTrigger>)}</TabsList></Tabs>{total&&<div className="flex items-center gap-2 text-sm"><p className="text-muted-foreground">{status==="all"?"All":financeLabel(status)} · {total.count.toLocaleString()} {total.count===1?"payout":"payouts"} · <span className="font-medium tabular-nums text-foreground">{formatMicros(total.amount_micros)}</span></p><Tooltip><TooltipTrigger asChild><Button size="icon" variant="ghost" className="size-6 text-muted-foreground" aria-label="About payout totals"><Info className="size-3.5"/></Button></TooltipTrigger><TooltipContent className="max-w-72">Totals include all matching payouts across pages. Payouts are prepared automatically after settlement. Pending payouts reserve funds; Failed and Cancelled payouts release them. An all-status total includes historical attempts and is not a balance.</TooltipContent></Tooltip></div>}</div>
 <div className="overflow-hidden rounded-lg border bg-card" aria-busy={loading}><Table className="min-w-[1000px] text-xs [&_th]:text-xs [&_tr>:first-child]:pl-5 [&_tr>:last-child]:pr-5"><TableHeader className="bg-muted"><TableRow><TableHead>Payout / Created · UTC</TableHead><TableHead>Publisher</TableHead><TableHead className="text-right">Amount · USD</TableHead><TableHead>Earnings period</TableHead><TableHead>Status</TableHead><TableHead>Paid · UTC</TableHead><TableHead>Actions</TableHead></TableRow></TableHeader><TableBody>{data.data.map(p=><TableRow key={p.id}><TableCell className="py-4"><Link href={"/admin/finance/payouts/"+p.id} className="text-sm font-medium hover:underline">PAY-{p.id}</Link><p className="mt-1 text-muted-foreground">{userDateTime(p.created_at)}</p></TableCell><TableCell className="max-w-48 whitespace-normal break-words"><FinanceIdentityLink data={p.publisher} canLink={data.permissions.users}/>{p.publisher?.status!=="active"&&<p className="mt-1 text-xs text-destructive">Payment actions blocked</p>}</TableCell><TableCell className="text-right tabular-nums">{formatMicros(p.amount_micros)}</TableCell><TableCell>{userDate(p.period_start)}<p className="mt-1 text-muted-foreground">{userDate(p.period_end)}</p></TableCell><TableCell><FinanceStatus value={p.status}/></TableCell><TableCell>{p.paid_at?userDateTime(p.paid_at):"—"}</TableCell><TableCell><Button asChild size="sm" variant="outline"><Link href={"/admin/finance/payouts/"+p.id}>View</Link></Button></TableCell></TableRow>)}{!data.data.length&&<TableRow><TableCell colSpan={7} className="h-40 text-center"><p className="text-sm font-medium">No matching payouts</p><p className="mt-2 text-muted-foreground">Try another status, publisher, or creation date range, or clear filters.</p></TableCell></TableRow>}</TableBody></Table></div>
 <CampaignPagination meta={data.meta} noun="payouts" perPage={params.get("per_page")??"10"} onPerPage={v=>filter("per_page",v)} onPage={p=>filter("page",String(p),false)}/>
 <p className="text-xs text-muted-foreground">USD · UTC · Updated {userDateTime(data.generated_at)}</p></>}
 </div>;
}
