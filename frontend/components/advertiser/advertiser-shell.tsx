"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import {
  Bell,
  BookOpen,
  ChevronDown,
  CircleHelp,
  CreditCard,
  LayoutDashboard,
  Loader2,
  LogOut,
  Megaphone,
  Plus,
  Sparkles,
} from "lucide-react";
import { BrandLogo } from "@/components/brand-logo";
import { Button } from "@/components/ui/button";
import { startRouteProgress } from "@/components/ui/route-progress";
import { User, getCurrentUser, logout, clearToken } from "@/lib/auth";
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
        <main className="min-w-0 flex-1 bg-[#f8f9fa] px-5 py-8 sm:px-8 lg:px-10">
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
  return (
    <header className="flex h-[72px] items-center justify-between border-b border-border bg-background px-6 lg:px-8">
      <BrandLogo />
      <div className="flex items-center gap-3 sm:gap-6">
        <Button
          asChild
          variant="ghost"
          size="sm"
          className="hidden sm:inline-flex"
        >
          <Link href="#">Documentation</Link>
        </Button>
        <Button variant="ghost" size="icon-sm" aria-label="Notifications">
          <Bell aria-hidden="true" />
        </Button>
        <div className="hidden items-center gap-2 sm:flex">
          <Avatar name={user.name} />
          <span className="max-w-32 truncate text-sm font-medium">
            {user.name}
          </span>
          <ChevronDown
            className="size-4 text-muted-foreground"
            aria-hidden="true"
          />
        </div>
        <Button
          variant="outline"
          size="sm"
          aria-label="Log out"
          onClick={onLogout}
        >
          <LogOut aria-hidden="true" />
          <span className="hidden sm:inline">Log out</span>
        </Button>
      </div>
    </header>
  );
}

export function AdvertiserSidebar({ balance }: { balance: number }) {
  const pathname = usePathname();
  return (
    <aside className="hidden w-[248px] shrink-0 flex-col border-r border-sidebar-border bg-sidebar px-3 py-4 lg:flex">
      <div className="rounded-lg p-2">
        <p className="text-sm font-medium text-sidebar-foreground">
          Advertiser
        </p>
        <p className="mt-1 text-xs text-muted-foreground">
          Advertising workspace
        </p>
      </div>

      <Button asChild className="mt-4 w-full">
        <Link href="/advertiser/campaigns/new">
          <Plus aria-hidden="true" />
          Create campaign
        </Link>
      </Button>

      <nav className="mt-6 space-y-1">
        <p className="px-2 py-2 text-xs font-medium text-sidebar-foreground/70">
          Workspace
        </p>
        {navItems.map((item) => (
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

      <div className="my-4 border-t border-sidebar-border" />

      <nav className="space-y-1">
        <p className="px-2 py-2 text-xs font-medium text-sidebar-foreground/70">
          Resources
        </p>
        {resourceItems.map((item) => (
          <SidebarLink key={item.label} {...item} />
        ))}
      </nav>

      <div className="min-h-8 flex-1" />

      <div className="rounded-lg border border-border bg-background p-3">
        <p className="text-xs text-muted-foreground">Available balance</p>
        <p className="mt-3 text-xl font-semibold">{formatMicros(balance)}</p>
        <Button asChild variant="outline" className="mt-3 w-full">
          <Link href="/advertiser/billing#funding">Add funds</Link>
        </Button>
      </div>

    </aside>
  );
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
        active && "bg-sidebar-accent text-sidebar-accent-foreground",
      )}
    >
      <Icon className="size-4" aria-hidden={true} />
      <span className="truncate">{label}</span>
    </Link>
  );
}

function Avatar({ name }: { name: string }) {
  const initials = name
    .split(" ")
    .filter(Boolean)
    .map((part) => part[0])
    .join("")
    .slice(0, 2)
    .toUpperCase();

  return (
    <span className="grid size-8 place-items-center rounded-full bg-foreground text-xs font-semibold text-background">
      {initials || "AD"}
    </span>
  );
}
