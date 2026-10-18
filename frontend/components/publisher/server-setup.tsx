"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Check, Copy, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { SetupResult, getServer } from "@/lib/servers";
import { ServerStatus, UnavailablePluginLinks, panelClass, utcDate } from "@/components/publisher/server-shared";

export function ServerSetup({ setup, onActivated }: { setup: SetupResult; onActivated?: () => void }) {
  const [activated, setActivated] = useState(false);
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState("");
  const [checking, setChecking] = useState(false);
  const expired = new Date(setup.setup_token_expires_at).getTime() <= Date.now();
  const check = useCallback(async () => {
    setChecking(true);
    try {
      const { data } = await getServer(setup.server.id);
      setError("");
      if (data.activated_at) { setActivated(true); onActivated?.(); }
    } catch (err) { setError(err instanceof Error ? err.message : "Unable to check activation."); }
    finally { setChecking(false); }
  }, [setup.server.id, onActivated]);
  useEffect(() => {
    if (activated || expired) return;
    const interval = setInterval(() => { if (document.visibilityState === "visible") void check(); }, 5000);
    return () => clearInterval(interval);
  }, [check, activated, expired]);

  if (activated) return <section className={panelClass}><Check className="mb-4 size-8 text-emerald-600" /><h2 className="text-xl font-semibold">Your server is activated</h2><p className="my-3 text-sm text-muted-foreground">The plugin connection is ready. Connection health and performance are available in server details.</p><Button asChild><Link href={`/publisher/servers/${setup.server.id}`}>View server details</Link></Button></section>;
  return <div className="grid gap-6 lg:grid-cols-[1.65fr_1fr]">
    <div className="space-y-6">
      <section className={panelClass}><h2 className="font-semibold">1. Download and install the plugin</h2><p className="my-4 text-sm text-muted-foreground">Install the MineAds plugin on your Minecraft server. The download and setup guide will be available later.</p><UnavailablePluginLinks /></section>
      <section className={panelClass}><h2 className="font-semibold">2. Activate with your setup token</h2><div className="my-4 rounded-md border border-amber-300/40 bg-amber-100/40 p-4 text-sm"><p className="font-medium">Save this token now</p><p className="mt-1">This token is shown only once. Copy it before leaving this page and keep it private.</p></div>
        <p className="text-sm font-medium">One-time setup token</p><div className="my-2 flex flex-wrap items-center gap-2"><code className="min-w-0 flex-1 break-all rounded-md border bg-muted/30 p-3 text-xs">{setup.setup_token}</code><Button variant="outline" onClick={async () => { try { await navigator.clipboard.writeText(setup.setup_token); setCopied(true); } catch { setError("Copy failed. Select and copy the token manually."); } }}><Copy className="size-4" />{copied ? "Copied" : "Copy token"}</Button></div>
        <p className="text-xs text-muted-foreground">Expires {utcDate(setup.setup_token_expires_at)}. If you leave without saving it, regenerate a new token from server details.</p>
      </section>
      <section className={panelClass}><h2 className="font-semibold">3. Wait for activation</h2><p className="my-4 flex items-center gap-2 text-sm">{expired ? "This setup token has expired. Regenerate a token from server details." : <><Loader2 className="size-4 animate-spin" />Waiting for plugin activation…</>}</p><p className="mb-4 text-sm text-muted-foreground">This page checks automatically while open. Activate the plugin with your setup token to continue.</p><Button variant="outline" disabled={checking || expired} onClick={() => void check()}>Check activation</Button>{error && <p role="alert" className="mt-3 text-sm text-destructive">{error}</p>}</section>
    </div>
    <section className={`${panelClass} h-fit space-y-4`}><h2 className="font-semibold">Server record</h2><ServerStatus status="pending" /><dl className="space-y-4 text-sm">{[["Name", setup.server.name], ["Host", setup.server.host], ["Category", setup.server.category]].map(([label, value]) => <div key={label}><dt className="text-xs text-muted-foreground">{label}</dt><dd className="mt-1 break-words">{value}</dd></div>)}</dl><Button asChild variant="outline"><Link href={`/publisher/servers/${setup.server.id}`}>Go to server details</Link></Button></section>
  </div>;
}
