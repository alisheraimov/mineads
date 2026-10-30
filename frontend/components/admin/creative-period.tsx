"use client";

import { useState } from "react";
import { format, parseISO } from "date-fns";
import { CalendarDays } from "lucide-react";
import { Calendar } from "@/components/ui/calendar";
import { DatePicker } from "@/components/ui/date-picker";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { UserSelect } from "./user-shared";
import { userDate } from "@/lib/admin-users";

const periods = [{ value: "today", label: "Today" }, { value: "yesterday", label: "Yesterday" }, { value: "last7", label: "Last 7 days" }, { value: "last30", label: "Last 30 days" }, { value: "custom", label: "Custom range" }];
export function CreativePeriod({ value, period, onChange }: { value: string; period?: { from: string; to: string }; onChange: (value: string, range?: { from: string; to: string }) => void }) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState({ from: "", to: "" });
  const today = new Date().toISOString().slice(0, 10);
  const valid = !!draft.from && !!draft.to && draft.from <= draft.to && draft.to <= today && (Date.parse(draft.to) - Date.parse(draft.from)) / 86400000 < 366;
  function edit() { setDraft(period ?? { from: today, to: today }); setOpen(true); }
  return <><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-base font-semibold">Performance</h2><p className="mt-1 text-xs text-muted-foreground">{period ? userDate(period.from) + " – " + userDate(period.to) : "Selected period"} · UTC</p></div><div className="flex flex-wrap items-center gap-2"><UserSelect label="Creative reporting period" value={value} options={periods} onChange={next => next === "custom" ? edit() : onChange(next)} className="min-w-44 bg-background" />{value === "custom" && <Button variant="outline" size="sm" aria-label="Edit custom date range" onClick={edit}><CalendarDays className="size-4" />Edit dates</Button>}</div></div>
    <Dialog open={open} onOpenChange={setOpen}><DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-md"><DialogHeader><DialogTitle>Custom date range</DialogTitle><DialogDescription>Metrics and chart use the same range · UTC</DialogDescription></DialogHeader><div className="grid grid-cols-2 gap-3"><div className="space-y-2"><p className="text-sm font-medium">From</p><DatePicker id="creative-date-from" label="Start date" value={draft.from} max={draft.to || today} onChange={from => setDraft(old => ({ ...old, from }))} /></div><div className="space-y-2"><p className="text-sm font-medium">Through</p><DatePicker id="creative-date-to" label="End date" value={draft.to} min={draft.from} max={today} onChange={to => setDraft(old => ({ ...old, to }))} /></div></div><Calendar className="mx-auto" mode="range" selected={draft.from ? { from: parseISO(draft.from), to: draft.to ? parseISO(draft.to) : undefined } : undefined} defaultMonth={draft.from ? parseISO(draft.from) : undefined} max={365} disabled={{ after: parseISO(today) }} onSelect={range => setDraft({ from: range?.from ? format(range.from, "yyyy-MM-dd") : "", to: range?.to ? format(range.to, "yyyy-MM-dd") : "" })} /><p className="text-xs text-muted-foreground">Select up to 366 days, ending no later than today in UTC.</p><DialogFooter><Button variant="outline" onClick={() => setOpen(false)}>Cancel</Button><Button disabled={!valid} onClick={() => { onChange("custom", draft); setOpen(false); }}>Apply range</Button></DialogFooter></DialogContent></Dialog>
  </>;
}

