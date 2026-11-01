"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";
import { useRouter } from "@/lib/navigation";
import { ArrowDown, ArrowUp } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ApiError, clearToken } from "@/lib/auth";
import { adminRoleLabels, listAdminUsers, roleLabel, userDate, type AdminUser, type UserList } from "@/lib/admin-users";
import { useAdmin } from "./admin-shell";
import { CreateAdminDialog } from "./create-admin-dialog";
import { UserSelect, UserStatus, UsersBreadcrumb, UsersError, UsersLoading } from "./user-shared";

export function AdminUsers() {
  const viewer = useAdmin();
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const query = params.toString();
  const [result, setResult] = useState<{query:string;value:UserList}>();
  const [failure, setFailure] = useState<{query:string;message:string}>();
  const [attempt, setAttempt] = useState(0);
  const [createOpen, setCreateOpen] = useState(false);
  const [created, setCreated] = useState<AdminUser>();
  const data = result?.query === query ? result.value : undefined;
  const error = failure?.query === query ? failure.message : undefined;
  useEffect(() => {
    const controller = new AbortController();
    listAdminUsers(query, controller.signal).then(value => { if (!controller.signal.aborted) {setResult({query,value}); setFailure(undefined);} }).catch(error => {
      if (controller.signal.aborted) return;
      if (error instanceof ApiError && error.status === 401) { clearToken(); router.replace("/login"); return; }
      setFailure({query,message:error instanceof Error ? error.message : "Unable to load users."});
    });
    return () => controller.abort();
  }, [query, attempt, router]);
  function filter(key:string, value:string, reset = true) {
    const next = new URLSearchParams(query);
    if (value === "all" || !value) next.delete(key); else next.set(key,value);
    if (reset) next.delete("page");
    router.replace(pathname + (next.size ? "?"+next : ""), {scroll:false});
  }
  const page = data?.meta.current_page ?? Number(params.get("page") ?? 1);
  const pages = data?.meta.last_page ?? 1;
  return <div className="space-y-6">
    <UsersBreadcrumb />
    <div className="flex flex-wrap items-center justify-between gap-4"><div><h1 className="text-2xl font-semibold">Users & accounts</h1><p className="mt-2 text-sm text-muted-foreground">Find users, review their accounts and manage access.</p></div>{viewer.admin_role === "admin-owner" && <Button disabled={!data} onClick={() => setCreateOpen(true)}>Create admin</Button>}</div>
    {created && <div role="status" className="flex items-center justify-between gap-4 rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-4 text-sm"><p><Link className="font-medium underline underline-offset-4" href={"/admin/users/"+created.id}>{created.name}</Link> can now sign in as a {adminRoleLabels[created.admin_role ?? ""]} admin. Their email is verified.</p><Button variant="ghost" size="sm" onClick={() => setCreated(undefined)}>Dismiss</Button></div>}
    <div className="flex flex-wrap items-center gap-3"><Input aria-label="Search users" placeholder="Search by name, email or user ID" value={params.get("search") ?? ""} onChange={event => filter("search", event.target.value)} className="h-8 w-80 max-w-full bg-background" maxLength={255} />
      <UserSelect label="Filter by role" value={params.get("role") ?? "all"} onChange={value=>filter("role",value)} options={[{value:"all",label:"All roles"},...['advertiser','publisher','admin'].map(value=>({value,label:roleLabel(value)}))]} />
      <UserSelect label="Filter by status" value={params.get("status") ?? "all"} onChange={value=>filter("status",value)} options={[{value:"all",label:"All statuses"},...['active','suspended','deleted'].map(value=>({value,label:roleLabel(value)}))]} />
      {(params.has("search") || params.has("role") || params.has("status")) && <Button variant="ghost" onClick={()=>router.replace(pathname)}>Clear filters</Button>}
    </div>
    {error ? <UsersError message={error} retry={()=>setAttempt(value=>value+1)} /> : !data ? <UsersLoading /> : <div>
      <div className="overflow-hidden rounded-lg border bg-card"><Table className="min-w-[800px] text-xs [&_th]:text-xs [&_tr>:first-child]:pl-5 [&_tr>:last-child]:pr-5"><TableHeader className="bg-muted"><TableRow><TableHead>Name</TableHead><TableHead>Email</TableHead><TableHead>Role</TableHead><TableHead>Status</TableHead><TableHead><button className="flex items-center gap-1 py-2" onClick={()=>{ const next=new URLSearchParams(query); next.set("sort","created_at"); next.set("direction", params.get("direction") === "asc" ? "desc" : "asc"); next.delete("page"); router.replace(pathname+"?"+next,{scroll:false}); }}>Created{params.get("direction") === "asc" ? <ArrowUp className="size-3.5" /> : <ArrowDown className="size-3.5" />}</button></TableHead><TableHead>Actions</TableHead></TableRow></TableHeader>
        <TableBody>{data.data.map(user => <TableRow key={user.id}><TableCell className="max-w-64 py-4"><Link className="block whitespace-normal break-words text-sm font-medium hover:underline" href={"/admin/users/"+user.id}>{user.name}</Link><p className="mt-1 text-xs text-muted-foreground">U-{user.id}{user.id === viewer.id ? " · You" : ""}</p></TableCell><TableCell>{user.email}</TableCell><TableCell>{roleLabel(user.role)}{user.admin_role && <p className="mt-1 text-xs text-muted-foreground">{adminRoleLabels[user.admin_role] ?? user.admin_role}</p>}</TableCell><TableCell><UserStatus status={user.status} /></TableCell><TableCell className="text-xs text-muted-foreground">{userDate(user.created_at)}</TableCell><TableCell><Button asChild variant="outline" size="sm"><Link href={"/admin/users/"+user.id} aria-label={"View "+user.name}>View</Link></Button></TableCell></TableRow>)}
          {!data.data.length && <TableRow><TableCell colSpan={6} className="h-40 text-center"><p className="font-medium">No users found</p><p className="mt-1 text-muted-foreground">Try another search or clear the filters.</p></TableCell></TableRow>}
        </TableBody></Table></div>
      <div className="mt-5 flex flex-wrap items-center justify-between gap-4"><p className="text-xs text-muted-foreground">Showing {data.meta.from ?? 0}–{data.meta.to ?? 0} of {data.meta.total.toLocaleString()} users</p><div className="flex items-center gap-2"><UserSelect label="Users per page" value={params.get("per_page") ?? "10"} onChange={value=>filter("per_page",value)} options={[10,25,50].map(n=>({value:String(n),label:n+" per page"}))} className="bg-background" /><Button variant="outline" disabled={page<=1} onClick={()=>filter("page",String(page-1),false)}>Previous</Button><span className="px-2 text-xs">{page} / {pages}</span><Button variant="outline" disabled={page>=pages} onClick={()=>filter("page",String(page+1),false)}>Next</Button></div></div>
    </div>}
    <p className="text-xs leading-5 text-muted-foreground">Only Owners can create admins or change admin access. Advertiser and publisher roles cannot be converted to admin.</p>
    {data && <CreateAdminDialog key={String(createOpen)} open={createOpen} onOpenChange={setCreateOpen} roles={data.roles} onCreated={user=>{setCreated(user);setAttempt(value=>value+1);}} />}
  </div>;
}
