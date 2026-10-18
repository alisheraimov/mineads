"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Plus } from "lucide-react";
import { AccountHeader } from "@/components/account/account-header";
import { Button } from "@/components/ui/button";
import type { User } from "@/lib/auth";

export function AddServerButton() {
  return <Button asChild><Link href="/publisher/servers/new"><Plus aria-hidden="true" />Add server</Link></Button>;
}

const sections = [
  { label: "Workspace", items: ["Servers", "Earnings", "Payouts"] },
  { label: "Resources", items: ["Plugin setup", "Documentation", "Download plugin", "Help center"] },
];

export function PublisherShell({ user, onLogout, children }: {
  user: User;
  onLogout: () => void;
  children: React.ReactNode;
}) {
  const pathname = usePathname();
  const isServers = pathname.startsWith("/publisher/servers");
  const isDashboard = pathname === "/publisher";
  const isPayouts = pathname.startsWith("/publisher/payouts");
  return (
    <div className="min-h-screen bg-background text-foreground">
      <AccountHeader user={user} onLogout={onLogout} />
      <div className="flex min-h-[calc(100vh-72px)]">
        <aside aria-label="Publisher navigation" className="hidden w-[248px] shrink-0 flex-col border-r border-sidebar-border bg-sidebar px-3 py-4 lg:flex">
          <div className="mb-4 flex h-[68px] flex-col justify-center px-4">
            <p className="text-sm font-medium">Publisher</p>
            <p className="text-xs text-muted-foreground">Server workspace</p>
          </div>
          <AddServerButton />
          {sections.map((section, index) => (
            <nav key={section.label} aria-label={section.label} className={index ? "mt-4 border-t border-sidebar-border pt-4" : "mt-4"}>
              <p className="px-2 py-2 text-xs font-medium text-muted-foreground">{section.label}</p>
              {index === 0 && <Link href="/publisher" aria-current={isDashboard ? "page" : undefined} className={`flex h-9 items-center rounded-md px-2 text-sm ${isDashboard ? "bg-sidebar-accent font-medium" : "hover:bg-sidebar-accent"}`}>Dashboard</Link>}
              {section.items.map((label) => {
                const href = label === "Servers" ? "/publisher/servers" : label === "Payouts" ? "/publisher/payouts" : null;
                const active = label === "Servers" ? isServers : isPayouts;
                return href ? <Link key={label} href={href} aria-current={active ? "page" : undefined} className={`flex h-9 items-center rounded-md px-2 text-sm ${active ? "bg-sidebar-accent font-medium" : "hover:bg-sidebar-accent"}`}>{label}</Link> : <button key={label} type="button" disabled title="Coming soon" className="flex h-9 w-full items-center rounded-md px-2 text-sm text-muted-foreground disabled:cursor-not-allowed disabled:opacity-60">{label}</button>;
              })}
            </nav>
          ))}
          <div className="min-h-16 flex-1" />
          <Link href="/account" className="flex h-9 items-center rounded-md px-2 text-sm hover:bg-sidebar-accent">Account settings</Link>
        </aside>
        <main className="min-w-0 flex-1 bg-canvas px-5 py-8 sm:px-8 lg:p-10">
          <nav aria-label="Publisher navigation" className="mb-6 flex flex-wrap items-center gap-4 border-b pb-3 text-sm lg:hidden">
            <Link href="/publisher" aria-current={isDashboard ? "page" : undefined}>Dashboard</Link>
            <Link href="/publisher/servers" aria-current={isServers ? "page" : undefined}>Servers</Link>
            <span aria-disabled="true" className="text-muted-foreground/60">Earnings</span>
            <Link href="/publisher/payouts" aria-current={isPayouts ? "page" : undefined}>Payouts</Link>
            <Link href="/account">Account settings</Link>
          </nav>
          {children}
        </main>
      </div>
    </div>
  );
}
