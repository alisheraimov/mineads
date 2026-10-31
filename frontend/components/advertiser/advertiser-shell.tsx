"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import {
  BookOpen,
  CircleHelp,
  CreditCard,
  LayoutDashboard,
  Loader2,
  Megaphone,
  Plus,
  Sparkles,
  Users,
  Server,
  Wallet,
  Settings,
} from "lucide-react";
import { AccountHeader } from "@/components/account/account-header";
import { Button } from "@/components/ui/button";
import { startRouteProgress } from "@/components/ui/route-progress";
import { User, getCurrentUser, logout, clearToken, adminCan } from "@/lib/auth";
import { campaignOptions } from "@/lib/campaigns";
import { formatMicros } from "@/lib/advertiser-format";
import { cn } from "@/lib/utils";

export function AdvertiserShell({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const [account, setAccount] = useState<{
    user: User;
    balance: number;
  } | null>(null);
  const [error, setError] = useState("");
  useEffect(() => {
    let mounted = true;
    Promise.all([getCurrentUser(), campaignOptions()])
      .then(([user, options]) => {
        if (!mounted) return;
        if (user.data.role !== "advertiser")
          throw new Error("This workspace is available to advertisers only.");
        setAccount({
          user: user.data,
          balance: options.data.available_balance_micros,
        });
      })
      .catch((error) => {
        if (mounted)
          setError(
            error instanceof Error
              ? error.message
              : "Unable to load workspace.",
          );
      });
    return () => {
      mounted = false;
    };
  }, []);
  async function handleLogout() {
    await logout().catch(() => clearToken());
    startRouteProgress();
    router.push("/login");
  }
  if (error)
    return (
      <main className="grid min-h-screen place-items-center p-6">
        <div className="space-y-4 text-center">
          <p role="alert">{error}</p>
          <Button asChild>
            <Link href="/login">Log in</Link>
          </Button>
        </div>
      </main>
    );
  if (!account)
    return (
      <main className="grid min-h-screen place-items-center">
        <Loader2
          className="size-5 animate-spin"
          aria-label="Loading advertiser workspace"
        />
      </main>
    );
  return (
    <div className="min-h-screen bg-background text-foreground">
      <AdvertiserHeader user={account.user} onLogout={handleLogout} />
      <div className="flex min-h-[calc(100vh-72px)]">
        <AdvertiserSidebar balance={account.balance} />
        <main className="min-w-0 flex-1 bg-canvas px-5 py-8 sm:px-8 lg:px-10">
          <nav
            aria-label="Workspace"
            className="mb-6 flex flex-wrap gap-4 border-b pb-3 text-sm lg:hidden"
          >
            <Link href="/advertiser">Dashboard</Link>
            <Link href="/advertiser/campaigns">Campaigns</Link>
            <Link href="/advertiser/creatives">Creatives</Link>
            <Link href="/advertiser/billing">Billing &amp; funding</Link>
            <Link href="/advertiser/campaigns/new">Create campaign</Link>
          </nav>
          {children}
        </main>
      </div>
    </div>
  );
}

const navItems = [
  { label: "Dashboard", href: "/advertiser", icon: LayoutDashboard },
  { label: "Campaigns", href: "/advertiser/campaigns", icon: Megaphone },
  { label: "Creatives", href: "/advertiser/creatives", icon: Sparkles },
  { label: "Billing & funding", href: "/advertiser/billing", icon: CreditCard },
];

const resourceItems = [
  { label: "Help center", href: "#", icon: CircleHelp },
  { label: "Advertising policies", href: "#", icon: BookOpen },
];

export function AdvertiserHeader({
  user,
  onLogout,
}: {
  user: User;
  onLogout: () => void;
}) {
  return <AccountHeader user={user} onLogout={onLogout} showNotifications />;
}

export function AdvertiserSidebar({ balance, workspace = "advertiser", user }: {
  balance: number;
  workspace?: "advertiser" | "admin";
  onComingSoon?: (section: string) => void;
  user?: User;
}) {
  const pathname = usePathname();
  const isAdmin = workspace === "admin";
  return (
    <aside className="hidden w-[248px] shrink-0 flex-col border-r border-sidebar-border bg-sidebar px-3 py-4 lg:flex">
      <div className="rounded-lg p-2">
        <p className="text-sm font-medium text-sidebar-foreground">
          {isAdmin ? "Admin Console" : "Advertiser"}
        </p>
        <p className="mt-1 text-xs text-muted-foreground">
          {isAdmin ? "Marketplace operations" : "Advertising workspace"}
        </p>
      </div>

      {!isAdmin && <Button asChild className="mt-4 w-full">
        <Link href="/advertiser/campaigns/new">
          <Plus aria-hidden="true" />
          Create campaign
        </Link>
      </Button>}

      <nav className="mt-6 space-y-1">
        <p className="px-2 py-2 text-xs font-medium text-sidebar-foreground/70">
          Workspace
        </p>
        {isAdmin ? <>
          {(!user || adminCan(user, "admin.analytics.view")) && <SidebarLink label="Overview" href="/admin" icon={LayoutDashboard} active={pathname === "/admin"} />}
          {(!user || adminCan(user, "admin.users.view")) && <SidebarLink label="Users & accounts" href="/admin/users" icon={Users} active={pathname.startsWith("/admin/users")} />}
          {user && !adminCan(user, "admin.users.view") && adminCan(user, "admin.servers.view") && <p className="px-2 py-2 text-sm text-sidebar-foreground">Users & accounts</p>}
          {(!user || adminCan(user, "admin.servers.view")) && <div className="ml-4 space-y-1 border-l border-sidebar-border pl-3"><Link href="/admin/publishers" aria-current={pathname.startsWith("/admin/publishers") ? "page" : undefined} className={cn("flex min-h-9 items-center rounded-md px-3 text-sm hover:bg-sidebar-accent", pathname.startsWith("/admin/publishers") && "bg-sidebar-foreground/[0.06] text-sidebar-accent-foreground hover:bg-sidebar-foreground/[0.06] dark:bg-sidebar-foreground/10 dark:hover:bg-sidebar-foreground/10")}>Publishers</Link></div>}
          {(!user || adminCan(user, "admin.campaigns.view")) && <SidebarLink label="Campaigns" href="/admin/campaigns" icon={Megaphone} active={pathname.startsWith("/admin/campaigns")} />}
          {(!user || adminCan(user, "admin.creatives.view") || adminCan(user, "admin.creatives.review")) && <SidebarLink label="Creatives" href="/admin/creatives" icon={Sparkles} active={pathname.startsWith("/admin/creatives")} />}
          {(!user || adminCan(user, "admin.servers.view")) && <SidebarLink label="Servers" href="/admin/servers" icon={Server} active={pathname.startsWith("/admin/servers")} />}
          {(!user || adminCan(user, "admin.finance.view")) && <div>
            <div id="admin-finance-label" className="flex h-9 items-center gap-2 px-2 text-sm font-normal"><SidebarIcon icon={Wallet} active={pathname.startsWith("/admin/finance")} />Finance</div>
            <div id="admin-finance-navigation" role="group" aria-labelledby="admin-finance-label" className="ml-4 space-y-1 border-l border-sidebar-border pl-3">{[{label:"Overview",href:"/admin/finance"},{label:"Advertiser ledger",href:"/admin/finance/advertiser-ledger"},{label:"Publisher ledger",href:"/admin/finance/publisher-ledger"},{label:"Payouts",href:"/admin/finance/payouts"}].map(item => <Link key={item.href} href={item.href} aria-current={(item.href === "/admin/finance" ? pathname === item.href : pathname.startsWith(item.href)) ? "page" : undefined} className={cn("flex min-h-9 items-center rounded-md px-3 text-sm hover:bg-sidebar-accent", (item.href === "/admin/finance" ? pathname === item.href : pathname.startsWith(item.href)) && "bg-sidebar-foreground/[0.06] text-sidebar-accent-foreground hover:bg-sidebar-foreground/[0.06] dark:bg-sidebar-foreground/10 dark:hover:bg-sidebar-foreground/10")}>{item.label}</Link>)}</div>
          </div>}
        </> : navItems.map((item) => (
          <SidebarLink
            key={item.label}
            {...item}
            active={
              item.href !== "#" &&
              (item.href === "/advertiser"
                ? pathname === item.href
                : pathname.startsWith(item.href))
            }
          />
        ))}
      </nav>

      {!isAdmin && <><div className="my-4 border-t border-sidebar-border" />

      <nav className="space-y-1">
        <p className="px-2 py-2 text-xs font-medium text-sidebar-foreground/70">
          Resources
        </p>
        {resourceItems.map((item) => (
          <SidebarLink key={item.label} {...item} />
        ))}
      </nav></>}

      <div className="min-h-8 flex-1" />

      {isAdmin ? <div className="space-y-1 rounded-lg border border-border bg-background p-3">
        <p className="text-xs font-medium">{user?.admin_role ? ({"admin-owner":"Owner", "ad-ops":"Ad Operations", finance:"Finance", support:"Support"}[user.admin_role] ?? "Admin") : "Admin"} access</p>
        <p className="text-xs text-muted-foreground">{user?.admin_role === "admin-owner" ? "Manage accounts and admins" : "Access based on assigned role"}</p>
      </div> : <div className="rounded-lg border border-border bg-background p-3">
        <p className="text-xs text-muted-foreground">Available balance</p>
        <p className="mt-3 text-xl font-semibold">{formatMicros(balance)}</p>
        <Button asChild variant="outline" className="mt-3 w-full">
          <Link href="/advertiser/billing#funding">Add funds</Link>
        </Button>
      </div>}

      <div className="mt-4">
        <SidebarLink
          label="Account settings"
          href="/account"
          icon={Settings}
          active={pathname === "/account"}
        />
      </div>
    </aside>
  );
}

function SidebarIcon({ icon: Icon, active = false }: {
  icon: React.ComponentType<{ className?: string; "aria-hidden"?: boolean }>;
  active?: boolean;
}) {
  return <span className={cn(
    "flex size-6 shrink-0 items-center justify-center rounded-md bg-sidebar-foreground/5 text-sidebar-foreground/70 transition-colors",
    active && "bg-background text-sidebar-accent-foreground shadow-sm ring-1 ring-sidebar-border/60",
  )}><Icon className="size-4" aria-hidden={true} /></span>;
}

function SidebarLink({
  label,
  href,
  icon: Icon,
  active = false,
}: {
  label: string;
  href: string;
  icon: React.ComponentType<{ className?: string; "aria-hidden"?: boolean }>;
  active?: boolean;
}) {
  return (
    <Link
      href={href}
      aria-current={active ? "page" : undefined}
      className={cn(
        "flex h-9 items-center gap-2 rounded-md px-2 text-sm text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
        active && "bg-sidebar-foreground/[0.06] text-sidebar-accent-foreground hover:bg-sidebar-foreground/[0.06] dark:bg-sidebar-foreground/10 dark:hover:bg-sidebar-foreground/10",
      )}
    >
      <SidebarIcon icon={Icon} active={active} />
      <span className="truncate">{label}</span>
    </Link>
  );
}
