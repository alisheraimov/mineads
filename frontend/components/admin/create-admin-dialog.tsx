"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiError, clearToken } from "@/lib/auth";
import { createAdmin, type AdminRole, type AdminUser } from "@/lib/admin-users";
import { UserSelect } from "./user-shared";

export function CreateAdminDialog({ open, onOpenChange, roles, onCreated }: {open:boolean; onOpenChange:(open:boolean)=>void; roles:AdminRole[]; onCreated:(user:AdminUser)=>void}) {
  const router = useRouter();
  const [role, setRole] = useState("support");
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<Record<string,string[]>>({});
  const [busy, setBusy] = useState(false);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    setBusy(true); setError(""); setErrors({});
    try {
      const {data: user} = await createAdmin({name:String(data.get("name")), email:String(data.get("email")), password:String(data.get("password")), password_confirmation:String(data.get("password_confirmation")), admin_role:role});
      onCreated(user); onOpenChange(false);
    } catch(error) {
      if (error instanceof ApiError && error.status === 401) { clearToken(); router.replace("/login"); return; }
      setError(error instanceof Error ? error.message : "Unable to create admin."); if (error instanceof ApiError) setErrors(error.errors);
    }
    finally { setBusy(false); }
  }
  return <Dialog open={open} onOpenChange={value => { if (!busy) onOpenChange(value); }}><DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-lg"><DialogHeader><DialogTitle>Create admin</DialogTitle><DialogDescription>Create a separate admin account with an initial password.</DialogDescription></DialogHeader>
    <form onSubmit={submit} className="space-y-4">
      {([{name:"name",label:"Full name",type:"text"},{name:"email",label:"Email address",type:"email"},{name:"password",label:"Password",type:"password"},{name:"password_confirmation",label:"Confirm password",type:"password"}] as const).map(field => <div key={field.name} className="space-y-2"><Label htmlFor={"admin-"+field.name}>{field.label}</Label><Input id={"admin-"+field.name} name={field.name} type={field.type} required disabled={busy} maxLength={255} minLength={field.type === "password" ? 8 : undefined} autoComplete={field.type === "password" ? "new-password" : "off"} aria-invalid={!!errors[field.name]} aria-describedby={errors[field.name] ? field.name+"-error" : undefined} />{errors[field.name] && <p id={field.name+"-error"} className="text-xs text-destructive">{errors[field.name][0]}</p>}</div>)}
      <div className="space-y-2"><Label>Admin role</Label><UserSelect label="Admin role" value={role} onChange={setRole} options={roles} className="w-full bg-background" /></div>
      <div className="rounded-md bg-muted p-3 text-sm"><p className="font-medium">{roles.find(item => item.value === role)?.label} permissions</p><p className="mt-1 text-muted-foreground">{roles.find(item => item.value === role)?.description}</p></div>
      <p className="text-xs leading-5 text-muted-foreground">The account is active and the email is marked verified immediately. The admin can change their password in Account settings after signing in.</p>
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <div className="flex justify-end gap-2"><Button type="button" variant="outline" disabled={busy} onClick={() => onOpenChange(false)}>Cancel</Button><Button disabled={busy}>{busy ? "Creating…" : "Create admin"}</Button></div>
    </form>
  </DialogContent></Dialog>;
}
