import Link from "next/link";
import { cn } from "@/lib/utils";

export function SidebarIcon({ icon: Icon, active = false }: {
  icon: React.ComponentType<{ className?: string; "aria-hidden"?: boolean }>;
  active?: boolean;
}) {
  return <span className={cn(
    "flex size-6 shrink-0 items-center justify-center rounded-md bg-sidebar-foreground/5 text-sidebar-foreground/70 transition-colors",
    active && "bg-background text-sidebar-accent-foreground shadow-sm ring-1 ring-sidebar-border/60",
  )}><Icon className="size-4" aria-hidden={true} /></span>;
}

export function SidebarLink({
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
