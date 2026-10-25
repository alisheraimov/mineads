"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowRight } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { ApiError, clearToken } from "@/lib/auth";
import { adminRoleLabels, changeAdminUser, getAdminUser, permissionLabel, roleLabel, userDateTime, type AdminRole, type UserDetail } from "@/lib/admin-users";
import { UserField, UserPanel, UserSelect, UserStatus, UsersBreadcrumb, UsersError, UsersLoading } from "./user-shared";

type Action = "suspend" | "reactivate" | "delete" | "role";
const titles: Record<Action,string> = {suspend:"Suspend user", reactivate:"Reactivate user", delete:"Delete user", role:"Change admin role"};

export function AdminUserDetails({ id }: {id:string}) {
  const router = useRouter();
  const [loaded, setLoaded] = useState<{id:string;data:UserDetail;roles:AdminRole[]}>();
  const [error, setError] = useState("");
  const [attempt, setAttempt] = useState(0);
  const [action, setAction] = useState<Action>();
  const [notice, setNotice] = useState("");
  const [operational, setOperational] = useState("");
  const user = loaded?.id === id ? loaded.data : undefined;
  useEffect(() => {
    const controller = new AbortController();
    getAdminUser(id,controller.signal).then(result=>{if(!controller.signal.aborted){setLoaded({id,...result});setError("");}}).catch(error=>{
      if(controller.signal.aborted) return;
      if(error instanceof ApiError && error.status === 401){clearToken();router.replace("/login");return;}
      setError(error instanceof ApiError && error.status === 404 ? "This user could not be found." : error instanceof Error ? error.message : "Unable to load user.");
    });
    return ()=>controller.abort();
  },[id,attempt,router]);
  if(error && !user) return <div className="space-y-6"><UsersBreadcrumb /><Button asChild variant="outline"><Link href="/admin/users">Back to users</Link></Button><UsersError message={error} retry={()=>setAttempt(v=>v+1)} /></div>;
  if(!user) return <UsersLoading />;
  const account = user.account;
  const money = (amount: number) => new Intl.NumberFormat("en-US",{style:"currency",currency:account?.currency ?? "USD"}).format(amount/1_000_000);
  const permissionGroups = new Map<string, string>();
  user.permissions.map(permissionLabel).forEach(({section,action}) => {
    if (!permissionGroups.has(section) || action !== "View") permissionGroups.set(section,action);
  });
  const permissions = Array.from(permissionGroups, ([section,action])=>({section,action}));
  return <div className="space-y-6">
    <UsersBreadcrumb name={user.name} />
    <div className="flex flex-wrap items-center justify-between gap-4"><div><h1 className="text-2xl font-semibold">{user.name}</h1><div className="mt-2 flex items-center gap-2 text-xs text-muted-foreground"><span>U-{user.id}</span><Badge variant="secondary">{roleLabel(user.role)}</Badge><UserStatus status={user.status} /></div></div><Button asChild variant="outline"><Link href="/admin/users">Back to users</Link></Button></div>
    {notice && <p role="status" className="rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-4 text-sm">{notice}</p>}
    {error && <UsersError message={error} retry={()=>setAttempt(v=>v+1)} />}
    {user.status === "suspended" && <div className="space-y-1 rounded-lg border border-amber-500/20 bg-amber-500/10 p-4 text-sm"><p className="font-medium">User suspended</p><p className="whitespace-pre-wrap break-words">{user.suspension_reason}</p>{user.suspended_at && <p className="text-xs text-muted-foreground">{userDateTime(user.suspended_at)}</p>}</div>}
    {user.status === "deleted" && <div className="rounded-lg border bg-muted p-4 text-sm">User deleted{user.deleted_at ? " on "+userDateTime(user.deleted_at) : ""}. Access and ad delivery are disabled. Financial and reporting history is retained. This record is read-only.</div>}
    <UserPanel title="User information" description="Basic identity and account registration details."><dl className="grid gap-6 sm:grid-cols-2 xl:grid-cols-3"><UserField label="Name">{user.name}</UserField><UserField label="Email">{user.email}</UserField><UserField label="Role">{roleLabel(user.role)}</UserField><UserField label="Created">{userDateTime(user.created_at)}</UserField><UserField label="Email verification">{user.email_verified_at ? "Verified" : "Not verified"}</UserField><UserField label="User ID">U-{user.id}</UserField></dl></UserPanel>
    <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]"><div className="space-y-6">
      {user.role === "admin" ? <><UserPanel title="Admin access" description="This role controls which console sections the admin can use."><div className="flex items-center justify-between gap-4"><div><p className="mb-2 text-sm text-muted-foreground">Assigned role</p><Badge variant="secondary">{adminRoleLabels[user.admin_role ?? ""] ?? "Unassigned"}</Badge></div>{user.can_change_role && <Button variant="outline" onClick={()=>setAction("role")}>Change role</Button>}</div><div><h3 className="mb-3 text-sm font-medium">Included permissions</h3><ul className="space-y-3">{permissions.map(permission=><li key={permission.section} className="flex items-center justify-between gap-4 text-sm"><span>{permission.section}</span><Badge variant="secondary">{permission.action}</Badge></li>)}</ul>{!permissions.length && <p className="text-sm text-muted-foreground">No permissions assigned.</p>}</div><p className="text-xs leading-5 text-muted-foreground">{loaded?.roles.find(role=>role.value === user.admin_role)?.description}</p></UserPanel><UserPanel title="Commercial accounts" description="Admin accounts are created separately from advertiser and publisher accounts."><p className="text-sm text-muted-foreground">No advertiser or publisher accounts.</p></UserPanel></> : account ? <UserPanel title={roleLabel(account.type)+" account"}>
        {account.status !== "active" && <div className="rounded-md bg-muted p-4 text-sm"><p className="font-medium">{roleLabel(account.type)} account suspended</p><p className="mt-1 text-muted-foreground">Ad delivery is blocked for this account. This restriction is separate from user sign-in.</p></div>}
        <dl className="grid gap-6 sm:grid-cols-2">
          {account.type === "advertiser" ? <><UserField label="Active campaigns">{account.active_campaigns}</UserField>{account.available_micros !== undefined && <UserField label="Available funds">{money(account.available_micros)}</UserField>}{account.lifetime_spend_micros !== undefined && <UserField label="Lifetime spend">{money(account.lifetime_spend_micros)}</UserField>}<UserField label="Currency">{account.currency}</UserField></> : <><UserField label="Servers">{account.servers} total · {account.active_servers} active</UserField>{account.lifetime_earnings_micros !== undefined && <UserField label="Lifetime earnings">{money(account.lifetime_earnings_micros)}</UserField>}{account.pending_micros !== undefined && <UserField label="Earnings awaiting release">{money(account.pending_micros)}</UserField>}{account.available_micros !== undefined && <UserField label="Available for payout">{money(account.available_micros)}</UserField>}</>}
        </dl>
        {!user.can_view_finance && <p className="text-xs text-muted-foreground">Financial totals are restricted for your role.</p>}
        <Button variant="outline" onClick={()=>setOperational(account.type === "advertiser" ? "Campaigns" : "Publishers & servers")}>View {account.type === "advertiser" ? "campaigns" : "servers"}<ArrowRight className="size-4" aria-hidden="true" /></Button>
      </UserPanel> : <UserPanel title={roleLabel(user.role)+" account"}><p className="text-sm text-muted-foreground">This user has not set up their commercial account yet.</p></UserPanel>}
      {user.history.length > 0 && <details className="rounded-lg border bg-card p-5 text-sm"><summary className="cursor-pointer font-medium">Access history</summary><ul className="mt-4 space-y-4">{user.history.map(event=><li key={event.id}><p>{roleLabel(event.action.replaceAll("_"," "))}{event.details.after ? " · "+adminRoleLabels[event.details.after] : ""}</p><p className="mt-1 text-xs text-muted-foreground">{userDateTime(event.created_at)}</p>{event.details.reason && <p className="mt-1 whitespace-pre-wrap break-words text-muted-foreground">{event.details.reason}</p>}</li>)}</ul></details>}
    </div>
      <UserPanel title="Account controls" description={user.role === "admin" ? "Owner only · managing another admin" : "User-level access and marketplace activity"}>
        {user.can_manage ? <><p className="text-sm leading-6 text-muted-foreground">{user.status === "suspended" ? "Restore sign-in and delivery eligibility. Existing campaign, server and account restrictions still apply." : "Suspend this user to block sign-in and stop campaign or server ad delivery."}</p><Button variant="outline" className="w-full" onClick={()=>setAction(user.status === "suspended" ? "reactivate" : "suspend")}>{user.status === "suspended" ? "Reactivate user" : "Suspend user"}</Button><div className="space-y-3 border-t pt-4"><h3 className="text-sm font-medium">Delete account</h3><p className="text-xs leading-5 text-muted-foreground">Disable access and delivery while retaining financial and reporting history.</p><Button variant="destructive" className="w-full" onClick={()=>setAction("delete")}>Delete user</Button></div></> : <p className="text-sm leading-6 text-muted-foreground">{user.protected_reason}</p>}
      </UserPanel>
    </div>
    <p className="text-xs text-muted-foreground">Account identity is read-only here. Operational details stay in the relevant console section.</p>
    {action && <UserActionDialog key={action} action={action} user={user} roles={loaded?.roles ?? []} onClose={()=>setAction(undefined)} onSaved={()=>{setAction(undefined);setNotice(action === "delete" ? "User deleted. Historical records have been retained." : action === "role" ? "Admin role updated. Existing sessions have been signed out." : action === "suspend" ? "User suspended. Existing sessions have been signed out." : "User reactivated. Existing restrictions still apply.");setAttempt(v=>v+1);}} />}
    <Dialog open={!!operational} onOpenChange={open=>{if(!open)setOperational("");}}><DialogContent><DialogHeader><DialogTitle>{operational}</DialogTitle><DialogDescription>This management section is coming soon. The account summary above shows the current totals for this user.</DialogDescription></DialogHeader><Button variant="outline" onClick={()=>setOperational("")}>Back to user</Button></DialogContent></Dialog>
  </div>;
}

function UserActionDialog({action,user,roles,onClose,onSaved}:{action:Action;user:UserDetail;roles:AdminRole[];onClose:()=>void;onSaved:()=>void}) {
  const router = useRouter();
  const [reason,setReason]=useState("");
  const [email,setEmail]=useState("");
  const [role,setRole]=useState(user.admin_role ?? "support");
  const [error,setError]=useState("");
  const [busy,setBusy]=useState(false);
  async function submit(event:FormEvent) {
    event.preventDefault();setBusy(true);setError("");
    try {await changeAdminUser(user.id,action,{reason,email,admin_role:role});onSaved();}
    catch(error){
      if(error instanceof ApiError && error.status === 401){clearToken();router.replace("/login");return;}
      setError(error instanceof Error ? error.message : "Unable to update user.");
    }
    finally{setBusy(false);}
  }
  return <Dialog open onOpenChange={open=>{if(!open&&!busy)onClose();}}><DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-lg"><DialogHeader><DialogTitle>{titles[action]}</DialogTitle><DialogDescription>{action === "delete" ? `Delete ${user.name}’s account while retaining its history.` : action === "role" ? `Update ${user.name}’s access to the Admin Console.` : action === "suspend" ? `Suspend ${user.name}’s access and marketplace activity.` : `Restore ${user.name}’s sign-in and delivery eligibility?`}</DialogDescription></DialogHeader>
    <form className="space-y-4" onSubmit={submit}>
      {action === "suspend" && <><ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground"><li>Block sign-in and revoke current sessions.</li><li>Stop campaign and server ad delivery.</li><li>Preserve account settings and financial records.</li></ul><div className="space-y-2"><Label htmlFor="suspension-reason">Reason for suspension</Label><Textarea id="suspension-reason" value={reason} onChange={event=>setReason(event.target.value)} required maxLength={2000} disabled={busy} placeholder="Explain why this user is being suspended…" /><p className="text-xs text-muted-foreground">Required. Recorded with the acting admin and timestamp.</p></div></>}
      {action === "delete" && <><div className="space-y-2 pt-2"><Label htmlFor="delete-confirm">Type {user.email} to confirm</Label><Input id="delete-confirm" className="h-8 py-1 text-sm" value={email} onChange={event=>setEmail(event.target.value)} autoComplete="off" disabled={busy} required /></div><p className="text-xs leading-5 text-muted-foreground">This soft-deletes the user record. Existing account, ledger and payout records are retained.</p></>}
      {action === "reactivate" && <p className="text-sm leading-6 text-muted-foreground">Campaign and server settings remain unchanged. Existing restrictions still apply.</p>}
      {action === "role" && <><UserSelect label="New admin role" value={role} onChange={setRole} options={roles} className="w-full bg-background" /><p className="rounded-md bg-muted p-3 text-sm text-muted-foreground">{roles.find(item=>item.value === role)?.description}</p><p className="text-xs leading-5 text-muted-foreground">This replaces the current role. Existing sessions are revoked so new permissions apply on the next sign-in. Only Owners can change admin roles.</p></>}
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <DialogFooter><Button type="button" variant="outline" disabled={busy} onClick={onClose}>Cancel</Button><Button variant={action === "delete" || action === "suspend" ? "destructive" : "default"} disabled={busy || (action === "delete" && email !== user.email) || (action === "suspend" && !reason.trim()) || (action === "role" && role === user.admin_role)}>{busy ? "Saving…" : action === "role" ? "Save role" : titles[action]}</Button></DialogFooter>
    </form>
  </DialogContent></Dialog>;
}
