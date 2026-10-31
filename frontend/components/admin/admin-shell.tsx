"use client";

import { createContext, useContext, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";
import { AccountHeader } from "@/components/account/account-header";
import { AdvertiserSidebar } from "@/components/advertiser/advertiser-shell";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { adminCan, adminHome, clearToken, getCurrentUser, getToken, logout, type User } from "@/lib/auth";

const AdminContext = createContext<User | null>(null);
export function useAdmin() {
  const user = useContext(AdminContext);
  if (!user) throw new Error("Admin context is unavailable.");
  return user;
}
export function AdminShell({ children, requiredPermission = "admin.users.view" }: { children: React.ReactNode; requiredPermission?: string }) {
  const router = useRouter();
  const [user, setUser] = useState<User | null>(null);
  const [comingSoon, setComingSoon] = useState("");
  useEffect(() => {
    let active = true;
    if (!getToken()) { router.replace("/login"); return; }
    getCurrentUser().then(({data}) => {
      if (!active) return;
      if (data.status !== "active") { router.replace("/account-suspended"); return; }
      if (data.role !== "admin") { router.replace(data.role === "publisher" ? "/publisher" : "/advertiser"); return; }
      if (!data.email_verified_at) { router.replace("/verify-email?email=" + encodeURIComponent(data.email)); return; }
      if (!adminCan(data, requiredPermission) && !(requiredPermission === "admin.creatives.view" && adminCan(data, "admin.creatives.review"))) { router.replace(adminHome(data)); return; }
      setUser(data);
    }).catch(() => { if (active) router.replace("/login"); });
    return () => { active = false; };
  }, [router, requiredPermission]);
  if (!user) return <main className="grid min-h-screen place-items-center"><Loader2 className="size-5 animate-spin" aria-label="Verifying access" /></main>;
  return <AdminContext.Provider value={user}>
    <div className="min-h-screen bg-background text-foreground">
      <AccountHeader user={user} onLogout={() => { void logout().finally(() => { clearToken(); setUser(null); router.replace("/login"); }).catch(() => {}); }} />
      <div className="flex min-h-[calc(100vh-72px)]">
        <AdvertiserSidebar balance={0} workspace="admin" user={user} onComingSoon={setComingSoon} />
        <main className="min-w-0 flex-1 bg-canvas px-5 py-8 sm:px-8 lg:px-10">
          <div className="mx-auto max-w-[1112px]">
            <nav className="mb-6 flex gap-4 border-b pb-3 text-sm lg:hidden" aria-label="Admin navigation">
              {adminCan(user, "admin.analytics.view") && <Link href="/admin">Overview</Link>}
              {adminCan(user, "admin.users.view") && <Link href="/admin/users">Users & accounts</Link>}
              {adminCan(user, "admin.servers.view") && <><Link href="/admin/publishers">Publishers</Link><Link href="/admin/servers">Servers</Link></>}
              {adminCan(user, "admin.campaigns.view") && <Link href="/admin/campaigns">Campaigns</Link>}
              {(adminCan(user, "admin.creatives.view") || adminCan(user, "admin.creatives.review")) && <Link href="/admin/creatives">Creatives</Link>}
              {adminCan(user, "admin.finance.view") && <Link href="/admin/finance">Finance</Link>}
            </nav>
            {children}
          </div>
        </main>
      </div>
      <Dialog open={!!comingSoon} onOpenChange={open => { if (!open) setComingSoon(""); }}>
        <DialogContent><DialogHeader><DialogTitle>{comingSoon}</DialogTitle><DialogDescription>This section is coming soon.</DialogDescription></DialogHeader><Button variant="outline" onClick={() => setComingSoon("")}>Close</Button></DialogContent>
      </Dialog>
    </div>
  </AdminContext.Provider>;
}
