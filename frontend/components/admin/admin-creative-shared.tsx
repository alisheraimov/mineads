"use client";

import { useCallback, useState } from "react";
import Link from "next/link";
import { Check, ChevronRight, ChevronsUpDown } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { creativeOptions, type CreativeOption } from "@/lib/admin-creatives";
import { displayLanguage } from "@/lib/advertiser-format";
import { useAdminQuery } from "./use-admin-query";

export function CreativeBreadcrumb({ id }: { id?: number }) {
  return <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"><span>Admin</span><ChevronRight className="size-3.5" aria-hidden="true" />{id ? <><Link href="/admin/creatives" className="hover:text-foreground">Creatives</Link><ChevronRight className="size-3.5" aria-hidden="true" /><span aria-current="page" className="text-foreground">CR-{id}</span></> : <span aria-current="page" className="text-foreground">Creatives</span>}</nav>;
}
export function CreativeLanguageFilter({ value, languages, onChange }: {
  value: string; languages: string[]; onChange: (value: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const options = [
    { value: "all", label: "All languages" },
    { value: "none", label: "Not specified (all languages)" },
    ...languages.map(code => ({ value: code, label: displayLanguage(code) })).sort((a, b) => a.label.localeCompare(b.label)),
  ];
  const selected = options.find(option => option.value === value)?.label ?? displayLanguage(value);
  return <Popover open={open} onOpenChange={setOpen}>
    <PopoverTrigger asChild><Button variant="outline" role="combobox" aria-expanded={open} aria-label="Filter creative language" className="h-9 w-56 justify-between font-normal"><span className="truncate">{selected}</span><ChevronsUpDown className="size-4 shrink-0 opacity-50" /></Button></PopoverTrigger>
    <PopoverContent side="bottom" align="start" className="w-80 max-w-[calc(100vw-2rem)] p-0">
      <Command><CommandInput placeholder="Search languages…" /><CommandList><CommandEmpty>No matching languages.</CommandEmpty><CommandGroup>
        {options.map(option => <CommandItem key={option.value} value={option.value} keywords={[option.label]} onSelect={() => { onChange(option.value); setOpen(false); }}><span className="min-w-0 flex-1">{option.label}</span>{value === option.value && <Check />}</CommandItem>)}
      </CommandGroup></CommandList></Command>
    </PopoverContent>
  </Popover>;
}
export function CreativeFilter({ kind, value, selected, advertiser = "", onChange }: {
  kind: "advertisers" | "campaigns"; value: string; selected: CreativeOption | null; advertiser?: string; onChange: (value: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const key = kind + ":" + advertiser + ":" + search;
  const loader = useCallback((signal: AbortSignal) => creativeOptions(kind, search, advertiser, signal), [kind, search, advertiser]);
  const { data, error, refresh } = useAdminQuery(loader, key);
  const all = "All " + kind;
  return <Popover open={open} onOpenChange={setOpen}><PopoverTrigger asChild><Button variant="outline" role="combobox" aria-expanded={open} aria-label={"Filter " + kind} className="h-9 w-56 justify-between font-normal"><span className="truncate">{value === "all" ? all : selected?.name ?? (kind === "campaigns" ? "CMP-" : "U-") + value}</span><ChevronsUpDown className="size-4 shrink-0 opacity-50" /></Button></PopoverTrigger><PopoverContent side="bottom" align="start" className="w-80 max-w-[calc(100vw-2rem)] p-0"><Command shouldFilter={false}><CommandInput placeholder={"Search " + kind + "…"} value={search} onValueChange={setSearch} /><CommandList><CommandGroup><CommandItem value="all" onSelect={() => { onChange("all"); setOpen(false); }}><span className="flex-1">{all}</span>{value === "all" && <Check />}</CommandItem>{data?.data.map(item => <CommandItem key={item.id} value={String(item.id)} onSelect={() => { onChange(String(item.id)); setOpen(false); }}><div className="min-w-0 flex-1"><p className="break-words">{item.name}</p><p className="break-all text-xs text-muted-foreground">{item.email ?? "CMP-" + item.id}</p></div>{value === String(item.id) && <Check />}</CommandItem>)}</CommandGroup>{!data && !error && <p role="status" className="p-3 text-xs text-muted-foreground">Loading…</p>}{data?.data.length === 0 && <CommandEmpty>No matches.</CommandEmpty>}{error && <div className="space-y-2 p-3"><p role="alert" className="text-xs text-destructive">{error}</p><Button size="sm" variant="outline" onClick={refresh}>Retry</Button></div>}</CommandList></Command></PopoverContent></Popover>;
}

