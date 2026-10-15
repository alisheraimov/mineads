"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { Loader2, LogOut, ShieldCheck } from "lucide-react";

import { BrandLogo } from "@/components/brand-logo";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { PrimaryButton } from "@/components/ui/primary-button";
import { startRouteProgress } from "@/components/ui/route-progress";
import { User, clearToken, getCurrentUser, logout } from "@/lib/auth";

export function AccountPanel() {
  const router = useRouter();
  const [user, setUser] = useState<User | null>(null);
  const [error, setError] = useState("");
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    getCurrentUser()
      .then((response) => setUser(response.data))
      .catch((err) => {
        clearToken();
        setError(err instanceof Error ? err.message : "Unable to load account.");
      })
      .finally(() => setIsLoading(false));
  }, []);

  const account = useMemo(() => {
    if (!user) {
      return null;
    }

    return user.role === "publisher" ? user.publisher_account : user.advertiser_account;
  }, [user]);

  async function handleLogout() {
    await logout().catch(() => clearToken());
    startRouteProgress();
    router.push("/");
  }

  if (isLoading) {
    return (
      <main className="grid min-h-screen place-items-center bg-background">
        <Loader2 className="size-5 animate-spin" aria-label="Loading account" />
      </main>
    );
  }

  if (!user) {
    return (
      <main className="grid min-h-screen place-items-center bg-background px-6">
        <div className="w-full max-w-md rounded-lg border border-border bg-white p-6 text-center shadow-soft">
          <p className="text-sm text-muted-foreground">{error || "Please log in to continue."}</p>
          <PrimaryButton asChild className="mt-6">
            <Link href="/login">Log in</Link>
          </PrimaryButton>
        </div>
      </main>
    );
  }

  return (
    <main className="min-h-screen bg-background">
      <header className="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-6">
        <BrandLogo />
        <Button variant="outline" size="sm" onClick={handleLogout}>
          <LogOut aria-hidden="true" />
          Log out
        </Button>
      </header>

      <section className="mx-auto w-full max-w-3xl px-6 pb-16 pt-10">
        <div className="rounded-lg border border-border bg-white p-6 shadow-soft">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p className="text-sm text-muted-foreground">Signed in as</p>
              <h1 className="mt-2 text-3xl font-semibold tracking-tight">{user.name}</h1>
              <p className="mt-2 text-sm text-muted-foreground">{user.email}</p>
            </div>
            <Badge className="w-fit capitalize">
              <ShieldCheck aria-hidden="true" className="mr-1 size-3" />
              {user.status}
            </Badge>
          </div>

          <div className="mt-8 grid gap-3 sm:grid-cols-3">
            <Summary label="Role" value={user.role} />
            <Summary label="Workspace" value={account?.name ?? "Pending"} />
            <Summary label="Currency" value={account?.currency ?? "USD"} />
          </div>
        </div>
      </section>
    </main>
  );
}

function Summary({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-md border border-border bg-background p-4">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="mt-3 text-sm font-semibold capitalize text-foreground">{value}</p>
    </div>
  );
}
