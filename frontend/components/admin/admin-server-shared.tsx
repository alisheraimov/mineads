"use client";
import { useCallback, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { Check, ChevronsUpDown, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Command, CommandInput, CommandList, CommandGroup, CommandItem, CommandEmpty } from "@/components/ui/command";
import { adminPublisherList, stateLabel, type PublisherIdentity } from "@/lib/admin-servers";
import { useAdminQuery } from "./use-admin-query";
export function useServerFilters() {
 const params = useSearchParams(), router = useRouter(), pathname = usePathname();
 const query = params.toString();
 const filter = (key: string, value: string, reset = true) => { const next = new URLSearchParams(query); if (!value || value === "all") next.delete(key); else next.set(key,value); if(reset) next.delete("page"); router.replace(pathname + (next.size ? "?" + next : ""), { scroll: false }); };
 return { params, router, pathname, query, filter };
}
export function ServerState({ value }: { value: string }) { return <Badge variant="outline" className={["approved","online","active","valid"].includes(value) ? "border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400" : ["rejected","suspended","invalid"].includes(value) ? "border-rose-500/20 bg-rose-500/10 text-rose-700 dark:text-rose-400" : "bg-muted text-muted-foreground"}>{value === "online" && <span aria-hidden="true" className="mr-1 size-1.5 rounded-full bg-emerald-500" />}{stateLabel(value)}</Badge>; }
export function ServerBreadcrumb({ name, publishers = false }: { name?: string; publishers?: boolean }) { return <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"><span>Admin</span><ChevronRight className="size-3.5" />{publishers ? <><span>Users & accounts</span><ChevronRight className="size-3.5" /><span aria-current="page" className="text-foreground">Publishers</span></> : name ? <><Link href="/admin/servers">Servers</Link><ChevronRight className="size-3.5" /><span aria-current="page" className="text-foreground">{name}</span></> : <span aria-current="page" className="text-foreground">Servers</span>}</nav>; }
export function PublisherLink({ publisher, canLink }: { publisher: PublisherIdentity | null; canLink: boolean }) { return publisher ? canLink ? <Link className="hover:underline" href={"/admin/users/" + publisher.id}>{publisher.name}</Link> : <span>{publisher.name}</span> : <span className="text-muted-foreground">Unavailable publisher</span>; }
export function PublisherFilter({ value, onChange }: { value: string; onChange: (v: string) => void }) {
 const [open,setOpen]=useState(false),[search,setSearch]=useState("");
 const loader=useCallback((signal:AbortSignal)=>adminPublisherList(new URLSearchParams({search,per_page:"25"}).toString(),signal),[search]);
 const {data,error,refresh}=useAdminQuery(loader,search);
 const label=data?.data.find(p=>String(p.id)===value)?.name;
 return <Popover open={open} onOpenChange={setOpen}><PopoverTrigger asChild><Button variant="outline" role="combobox" aria-expanded={open} aria-label="Filter publishers" className="h-9 w-52 justify-between font-normal"><span className="truncate">{value==="all"?"All publishers":label??"U-"+value}</span><ChevronsUpDown className="size-4 shrink-0 opacity-50" /></Button></PopoverTrigger><PopoverContent align="start" side="bottom" className="w-80 p-0"><Command shouldFilter={false}><CommandInput placeholder="Search publishers…" value={search} onValueChange={setSearch}/><CommandList><CommandGroup><CommandItem value="all" onSelect={()=>{onChange("all");setOpen(false);}}>All publishers{value==="all"&&<Check className="ml-auto"/>}</CommandItem>{data?.data.map(p=><CommandItem key={p.id} value={String(p.id)} onSelect={()=>{onChange(String(p.id));setOpen(false);}}><div className="min-w-0 flex-1"><p>{p.name}</p><p className="break-all text-xs text-muted-foreground">{p.email}</p></div>{value===String(p.id)&&<Check/>}</CommandItem>)}</CommandGroup>{data?.data.length===0&&<CommandEmpty>No publishers found.</CommandEmpty>}{!data&&!error&&<p className="p-3 text-xs">Loading publishers…</p>}{error&&<div className="p-3"><p role="alert" className="text-xs text-destructive">{error}</p><Button size="sm" variant="outline" onClick={refresh}>Retry</Button></div>}</CommandList></Command></PopoverContent></Popover>;
}

