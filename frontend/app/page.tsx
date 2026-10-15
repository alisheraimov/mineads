import Link from "next/link";
import { ArrowRight, BarChart3, Server, ShieldCheck } from "lucide-react";

import { BrandLogo } from "@/components/brand-logo";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { PrimaryButton } from "@/components/ui/primary-button";

export default function Home() {
  return (
    <main className="min-h-screen bg-background">
      <header className="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-6">
        <BrandLogo />
        <nav className="flex items-center gap-2">
          <Button asChild variant="ghost" size="sm">
            <Link href="/login">Log in</Link>
          </Button>
          <PrimaryButton asChild size="sm">
            <Link href="/register">Get started</Link>
          </PrimaryButton>
        </nav>
      </header>

      <section className="mx-auto grid min-h-[calc(100vh-88px)] w-full max-w-6xl items-center gap-12 px-6 pb-16 pt-8 lg:grid-cols-[1.05fr_0.95fr]">
        <div className="max-w-2xl">
          <Badge>Ads for Minecraft servers</Badge>
          <h1 className="mt-6 max-w-2xl text-5xl font-semibold leading-[1.02] tracking-tight text-foreground sm:text-6xl lg:text-7xl">
            MineAds
          </h1>
          <p className="mt-6 max-w-xl text-lg leading-8 text-muted-foreground">
            A clean marketplace for advertisers to reach players and for server
            owners to monetize attention without cluttering the game.
          </p>
          <div className="mt-8 flex flex-col gap-3 sm:flex-row">
            <PrimaryButton asChild size="lg">
              <Link href="/register">
                Start now <ArrowRight aria-hidden="true" />
              </Link>
            </PrimaryButton>
            <Button asChild variant="outline" size="lg">
              <Link href="/login">I already have an account</Link>
            </Button>
          </div>
        </div>

        <div className="relative">
          <div className="absolute inset-0 -z-10 translate-y-8 rounded-full bg-neutral-200/70 blur-3xl" />
          <div className="rounded-lg border border-border bg-white p-4 shadow-soft">
            <div className="flex items-center justify-between border-b border-border pb-4">
              <div>
                <p className="text-sm font-medium">Campaign overview</p>
                <p className="mt-1 text-xs text-muted-foreground">Live network snapshot</p>
              </div>
              <Badge className="text-foreground">Active</Badge>
            </div>
            <div className="grid gap-3 py-4 sm:grid-cols-3">
              {[
                ["Impressions", "1.2M", BarChart3],
                ["Servers", "248", Server],
                ["Verified", "99.8%", ShieldCheck],
              ].map(([label, value, Icon]) => (
                <div key={label as string} className="rounded-md border border-border bg-background p-4">
                  <Icon className="size-5 text-foreground" aria-hidden="true" />
                  <p className="mt-6 text-2xl font-semibold">{value as string}</p>
                  <p className="mt-1 text-xs text-muted-foreground">{label as string}</p>
                </div>
              ))}
            </div>
            <div className="space-y-3 rounded-md bg-foreground p-4 text-primary-foreground">
              <div className="flex items-center justify-between text-sm">
                <span>Advertiser budget</span>
                <span>$4,800</span>
              </div>
              <div className="h-2 rounded-full bg-white/15">
                <div className="h-full w-2/3 rounded-full bg-white" />
              </div>
              <p className="text-xs text-white/70">Spend pacing is on track for this week.</p>
            </div>
          </div>
        </div>
      </section>
    </main>
  );
}
