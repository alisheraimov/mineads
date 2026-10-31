"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { Info, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { DateRangePicker } from "@/components/ui/date-picker";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Tooltip, TooltipTrigger, TooltipContent } from "@/components/ui/tooltip";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { getFinanceLedger, exactUsd, financeLabel, financeTime } from "@/lib/admin-finance";
import { userDate, userDateTime } from "@/lib/admin-users";
import { useAdminQuery } from "./use-admin-query";
import { useServerFilters } from "./admin-server-shared";
import { CampaignPagination } from "./admin-campaign-shared";
import { FinanceHeader, FinanceAccountFilter, FinanceRelatedFilter, FinanceExport, FinanceStatus, FinanceIdentityLink } from "./finance-shared";
import { UsersError } from "./user-shared";
import { CampaignLoading } from "@/components/advertiser/campaign-shared";

export function AdminFinanceLedger({ kind }: { kind: "advertiser" | "publisher" }) {
 const publisher = kind === "publisher", title = publisher ? "Publisher ledger" : "Advertiser ledger";
 const { params, query, filter, router, pathname } = useServerFilters();
 const [search, setSearch] = useState(params.get("search") ?? "");
 const appliedSearch=params.get("search")??"";
 useEffect(() => setSearch(appliedSearch), [appliedSearch]);
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
 const loader = useCallback((signal: AbortSignal) => getFinanceLedger(kind, query, signal), [kind, query]);
 const { data: result, error, loading, refresh } = useAdminQuery(loader, kind + query);
 const data = error ? undefined : result;
 const account = params.get("account") ?? "all", today = new Date().toISOString().slice(0,10);
 const updateFilters=(values:Record<string,string>)=>{const next=new URLSearchParams(query);for(const [key,value] of Object.entries(values)){if(value&&value!=="all")next.set(key,value);else next.delete(key);}next.delete("page");router.replace(pathname+(next.size?"?"+next:""),{scroll:false});};
 const chips=[
  ...(params.get("search")?[{label:"Search: "+params.get("search"),clear:()=>updateFilters({search:""})}]:[]),
  ...(account!=="all"?[{label:(publisher?"Publisher: U-":"Advertiser: U-")+account,clear:()=>updateFilters({account:"",related:""})}]:[]),
  ...(params.get("related")?[{label:(publisher?"Server: SRV-":"Campaign: CMP-")+params.get("related"),clear:()=>updateFilters({related:""})}]:[]),
  ...(params.has("from")||params.has("to")?[{label:params.get("from")?userDate(params.get("from")??"")+(params.get("to")?" – "+userDate(params.get("to")??""):" onward"):"Through "+userDate(params.get("to")??""),clear:()=>updateFilters({from:"",to:""})}]:[]),
  ...(params.get("type")?[{label:financeLabel(params.get("type")??""),clear:()=>updateFilters({type:""})}]:[]),
  ...(params.get("held")==="1"?[{label:"Held earnings only",clear:()=>updateFilters({held:""})}]:[]),
 ];
 return <div className="space-y-6"><FinanceHeader title={title} description={publisher ? "Trace earnings, release eligibility, and server-level holds." : "Inspect advertiser funding and charges for validated impressions."} loading={loading} refresh={refresh} />
 <div className="space-y-3"><div className="flex flex-wrap items-center gap-3">
 <div className="min-w-0 flex-1 basis-56 sm:max-w-64"><Label htmlFor="ledger-search" className="sr-only">Find a record</Label><Input id="ledger-search" className="h-9 bg-background" placeholder="Entry ID or source type:ID" value={search} maxLength={255} onChange={e=>setSearch(e.target.value)}/></div>
 <div className="w-44 [&>button]:w-full"><FinanceAccountFilter kind={kind} value={account} onChange={v=>updateFilters({account:v,related:""})}/></div>
 <Tooltip><TooltipTrigger asChild><span tabIndex={account==="all"?0:undefined} className="inline-flex w-44 [&>button]:w-full"><FinanceRelatedFilter kind={kind} account={account} value={params.get("related")??"all"} onChange={v=>filter("related",v)}/></span></TooltipTrigger>{account==="all"&&<TooltipContent>Select {publisher?"a publisher":"an advertiser"} to filter its {publisher?"servers":"campaigns"}.</TooltipContent>}</Tooltip>
 <div className="w-64 max-w-full"><DateRangePicker id="ledger-date-range" label="Ledger posting date range · UTC" value={{from:params.get("from")??"",to:params.get("to")??""}} max={today} onChange={range=>updateFilters(range)}/></div>
 <div className="ml-auto"><FinanceExport path={"ledgers/"+kind} query={query}/></div></div>
 {chips.length>0&&<div className="flex flex-wrap items-center gap-2" aria-label="Active filters">{chips.map(chip=><Button key={chip.label} size="sm" variant="outline" className="h-7 max-w-full gap-1.5 rounded-full px-2.5 text-xs font-normal" onClick={chip.clear} aria-label={"Remove "+chip.label+" filter"}><span className="truncate">{chip.label}</span><X className="size-3 shrink-0"/></Button>)}<Button size="sm" variant="ghost" className="h-7 px-2 text-xs" onClick={()=>updateFilters({search:"",account:"",related:"",from:"",to:"",type:"",held:""})}>Clear filters</Button></div>}
 </div>
 <div className="flex flex-wrap items-center gap-4"><Tabs value={params.get("type")??"all"} onValueChange={v=>filter("type",v)}><TabsList className="h-auto flex-wrap"><TabsTrigger value="all">All</TabsTrigger>{(publisher?["pending_earning","payable"]:["deposit","spend"]).map(t=><TabsTrigger key={t} value={t}>{financeLabel(t)}</TabsTrigger>)}{data?.types.filter(t=>!(publisher?["pending_earning","payable"]:["deposit","spend"]).includes(t)).map(t=><TabsTrigger key={t} value={t}>{financeLabel(t)}</TabsTrigger>)}</TabsList></Tabs>{publisher&&<><label className="flex items-center gap-2 text-sm"><Checkbox checked={params.get("held")==="1"} onCheckedChange={v=>filter("held",v?"1":"")} />Held earnings only</label><Tooltip><TooltipTrigger asChild><Button size="icon" variant="ghost" className="-ml-3 size-6 text-muted-foreground" aria-label="About server-level holds"><Info className="size-3.5"/></Button></TooltipTrigger><TooltipContent className="max-w-80">Only a suspended server’s unsettled earnings are held. Other servers settle normally. Reactivation resumes settlement eligibility; deleted suspended servers remain held. Existing payouts remain unchanged.</TooltipContent></Tooltip></>}</div>
 {error&&<UsersError message={error} retry={refresh} />}
 {!data ? !error&&<CampaignLoading label={"Loading "+title.toLowerCase()} /> : <>
 <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm" aria-label={publisher?"Matching earnings summary":"Matching ledger summary"}>{(publisher?[{label:"Earned",value:data.summary.earned_micros},{label:"Released",value:data.summary.released_micros},{label:"Awaiting release",value:data.summary.awaiting_micros}]:[{label:"Deposits",value:data.summary.deposits_micros},{label:"Spend",value:data.summary.spend_micros},{label:"Net movement",value:data.summary.net_micros}]).map(item=><p key={item.label} className="text-muted-foreground">{item.label} <span className="ml-1 font-medium tabular-nums text-foreground">{exactUsd(item.value,item.label==="Net movement")}</span></p>)}<Tooltip><TooltipTrigger asChild><Button size="icon" variant="ghost" className="-ml-3 size-6 text-muted-foreground" aria-label="About ledger totals"><Info className="size-3.5"/></Button></TooltipTrigger><TooltipContent className="max-w-80">{publisher?"Totals include every entry matching the current filters across all pages, using its original posting date. Released and Awaiting release are portions of Earned, not additional revenue. Awaiting release includes held earnings. Amounts retain up to six decimal places.":"Totals include every entry matching the current filters across all pages, using its posting date. Deposits are positive funding entries. Spend shows charges as a positive total. Net movement sums signed ledger amounts, including legacy entry types; it is not the current account balance. Amounts retain up to six decimal places."}</TooltipContent></Tooltip></div>
 <div className="overflow-hidden rounded-lg border bg-card" aria-busy={loading}><Table className="min-w-[1020px] text-xs [&_th]:text-xs [&_tr>:first-child]:pl-5 [&_tr>:last-child]:pr-5"><TableHeader className="bg-muted"><TableRow><TableHead>Entry / Posted · UTC</TableHead><TableHead>{publisher?"Publisher":"Advertiser"}</TableHead><TableHead>{publisher?"Server":"Campaign"}</TableHead><TableHead>{publisher?"Earning state":"Type"}</TableHead><TableHead className="text-right">Amount · USD</TableHead>{publisher&&<TableHead>Released · UTC</TableHead>}<TableHead>Source</TableHead></TableRow></TableHeader><TableBody>{data.data.map(e=><TableRow key={e.id}><TableCell className="py-4"><p className="text-sm font-medium">{publisher?"PLE":"ALE"}-{e.id}</p><p className="mt-1 text-muted-foreground">{financeTime(e.created_at)}</p></TableCell><TableCell className="max-w-48 whitespace-normal break-words"><FinanceIdentityLink data={e.account} canLink={data.permissions.users} /></TableCell><TableCell className="max-w-52 whitespace-normal break-words">{e.related?<>{(publisher?data.permissions.servers:data.permissions.campaigns)?<Link className="hover:underline" href={(publisher?"/admin/servers/":"/admin/campaigns/")+e.related.id}>{e.related.name}</Link>:e.related.name}<p className="mt-1 text-muted-foreground">{publisher?"SRV":"CMP"}-{e.related.id}{e.related.deleted?" · Deleted":""}</p></>:<span className="text-muted-foreground">—</span>}</TableCell><TableCell>{publisher?<FinanceStatus value={e.held?"held":e.type} />:financeLabel(e.type)}</TableCell><TableCell className="text-right tabular-nums">{exactUsd(e.amount_micros,true)}</TableCell>{publisher&&<TableCell>{e.settled_at?financeTime(e.settled_at):e.type==="payable"?"Not recorded":"—"}</TableCell>}<TableCell className="max-w-48 whitespace-normal break-all">{e.reference_type?<><span>{financeLabel(e.reference_type)}</span><p className="mt-1 text-muted-foreground">{e.reference_id!==null?"ID ":""}{e.reference_id??"—"}</p></>:"—"}</TableCell></TableRow>)}{!data.data.length&&<TableRow><TableCell colSpan={publisher?7:6} className="h-40 text-center"><p className="text-sm font-medium">No matching ledger entries</p><p className="mt-2 text-muted-foreground">Choose another account, period, or clear filters.</p></TableCell></TableRow>}</TableBody></Table></div>
 <CampaignPagination meta={data.meta} noun="entries" perPage={params.get("per_page")??"10"} onPerPage={v=>filter("per_page",v)} onPage={p=>filter("page",String(p),false)} />
 <p className="text-xs text-muted-foreground">Posting dates · UTC · USD · Updated {userDateTime(data.generated_at)}</p>
 </>}
 </div>;
}
