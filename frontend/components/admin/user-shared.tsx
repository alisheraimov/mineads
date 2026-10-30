"use client";

import Link from "next/link";
import { ChevronRight, Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { roleLabel } from "@/lib/admin-users";

export function UserStatus({ status }: { status: string }) {
  return <Badge variant="secondary" className={status === "active" ? "border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400" : status === "suspended" ? "border-amber-500/20 bg-amber-500/10 text-amber-700 dark:text-amber-400" : ""}>{roleLabel(status)}</Badge>;
}
export function UsersBreadcrumb({ name }: {name?: string}) {
  return <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"><span>Admin</span><ChevronRight className="size-3.5" aria-hidden="true" />{name ? <><Link href="/admin/users" className="hover:text-foreground">Users & accounts</Link><ChevronRight className="size-3.5" aria-hidden="true" /><span aria-current="page" className="text-foreground">{name}</span></> : <span aria-current="page" className="text-foreground">Users & accounts</span>}</nav>;
}
export function UserSelect({ label, value, options, onChange, className, placeholder, id, disabled }: {label: string; value: string; options: {value:string;label:string}[]; onChange:(value:string)=>void; className?: string; placeholder?: string; id?: string; disabled?: boolean}) {
  return <Select value={value} onValueChange={onChange} disabled={disabled}><SelectTrigger id={id} aria-label={label} className={className ?? "min-w-40 bg-background"}><SelectValue placeholder={placeholder} /></SelectTrigger><SelectContent position="popper" side="bottom" align="start"><SelectGroup>{options.map(option => <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>)}</SelectGroup></SelectContent></Select>;
}
export function UsersError({ message, retry }: {message: string; retry:()=>void}) {
  return <div role="alert" className="flex items-center justify-between gap-4 rounded-lg border border-destructive/30 bg-card p-4 text-sm"><p>{message}</p><Button variant="outline" onClick={retry}>Retry</Button></div>;
}
export function UsersLoading() { return <div role="status" className="grid min-h-48 place-items-center"><Loader2 className="size-5 animate-spin" aria-label="Loading users" /></div>; }
export function UserPanel({ title, description, children }: {title: string; description?: string; children: React.ReactNode}) {
  return <section className="space-y-5 rounded-lg border bg-card p-6"><div><h2 className="text-base font-semibold">{title}</h2>{description && <p className="mt-1 text-xs leading-5 text-muted-foreground">{description}</p>}</div>{children}</section>;
}
export function UserField({label, children}: {label:string; children:React.ReactNode}) {
  return <div className="min-w-0 space-y-1.5"><dt className="text-xs text-muted-foreground">{label}</dt><dd className="break-words text-sm">{children}</dd></div>;
}
