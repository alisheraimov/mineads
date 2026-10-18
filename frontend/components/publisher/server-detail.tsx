"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogTitle, DialogDescription, DialogFooter } from "@/components/ui/dialog";
import { ServerDetail, SetupResult, getServer, regenerateServerToken, removeServer, setServerEnabled } from "@/lib/servers";
import { ServerBreadcrumb, ServerMetric, ServerStatus, UnavailablePluginLinks, money, panelClass, utcDate } from "@/components/publisher/server-shared";
import { ServerSetup } from "@/components/publisher/server-setup";
import { ServerPerformancePanel } from "@/components/publisher/server-performance";

export function ServerDetailPage({ id }: { id: number }) {
  const router = useRouter();
  const [server, setServer] = useState<ServerDetail | null>(null);
  const [setup, setSetup] = useState<SetupResult | null>(null);
  const [error, setError] = useState("");
  const [action, setAction] = useState<"regenerate" | "remove" | "status" | null>(null);
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);
  const [retry, setRetry] = useState(0);
  const load = useCallback(async () => {
    const { data } = await getServer(id); setServer(data);
  }, [id]);
  useEffect(() => {
    let mounted = true;
    const fetchServer = () => getServer(id).then(({ data }) => { if (mounted) { setServer(data); setError(""); } }).catch((err) => { if (mounted) setError(err instanceof Error ? err.message : "Unable to load server."); });
    void fetchServer();
    const timer = setInterval(() => { if (document.visibilityState === "visible") void fetchServer(); }, 30000);
    return () => { mounted = false; clearInterval(timer); };
  }, [id, retry]);

  async function confirm() {
    if (!server || !action) return;
    setBusy(true); setError("");
    try {
      if (action === "remove") { await removeServer(id, name); router.push("/publisher/servers"); }
      else if (action === "regenerate") { const { data } = await regenerateServerToken(id); setSetup(data); await load(); }
      else { const { data } = await setServerEnabled(id, !server.enabled); setServer(data); }
      setAction(null); setName("");
    } catch (err) { setError(err instanceof Error ? err.message : "Unable to update server."); }
    finally { setBusy(false); }
  }

  if (!server) return <><ServerBreadcrumb current="Server details" />{error ? <div role="alert"><p>{error}</p><Button className="mt-4" onClick={() => setRetry((value) => value + 1)}>Try again</Button></div> : <p role="status">Loading server…</p>}</>;
  const issues: string[] = [];
  if (server.plugin_update_available) issues.push("Your MineAds plugin is outdated. Install the latest version when the plugin download is available.");
  if (server.status === "pending") issues.push(server.setup_token_expires_at && new Date(server.setup_token_expires_at) <= new Date() ? "Your setup token has expired. Regenerate a token to activate the plugin." : "The plugin has not been activated. Complete setup to connect this server.");
  if (server.status === "offline") issues.push("No heartbeat received in the last five minutes. Check that the server and plugin are running.");
  if (server.status === "suspended") issues.push("This server is suspended. Contact support before reconnecting.");
  if (server.status === "inactive") issues.push("This connection is disabled. Enable it to resume ad delivery.");
  if (server.status === "active" && server.impressions_today === 0) issues.push("The plugin is connected, but no validated impressions have arrived today. Check player activity and ad availability.");
  if (server.impressions_today === 0) issues.push("No earnings today because there are no validated impressions.");

  return <>
    <ServerBreadcrumb current={server.name} />
    <div className="flex flex-wrap items-center justify-between gap-4"><div><h1 className="text-2xl font-semibold">{server.name}</h1><p className="mt-2 text-sm text-muted-foreground">Monitor performance, connection health and server setup.</p></div><Button asChild variant="outline"><Link href="/publisher/servers">Back to servers</Link></Button></div>
    {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
    {setup && <ServerSetup key={setup.setup_token} setup={setup} onActivated={load} />}
    <section className={panelClass}><div className="mb-5 flex justify-between gap-4"><h2 className="font-semibold">Server overview</h2><ServerStatus status={server.status} /></div><dl className="grid gap-4 text-sm sm:grid-cols-2 xl:grid-cols-4">{[["Server name", server.name], ["Host / domain", server.host], ["Category", server.category], ["Last seen", utcDate(server.last_seen_at)]].map(([label, value]) => <div key={label}><dt className="text-xs text-muted-foreground">{label}</dt><dd className="mt-2 break-words">{value}</dd></div>)}</dl></section>
    <section aria-label="Server metrics" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><ServerMetric label="Online players" value={server.online_players} caption="Recent heartbeat only" /><ServerMetric label="Validated impressions today" value={server.impressions_today.toLocaleString("en-US")} caption="UTC · Validated only" /><ServerMetric label="Earnings today" value={money(server.earnings_today_micros, server.currency)} caption="Current estimate · UTC" /><ServerMetric label="Earnings this month" value={money(server.earnings_month_micros, server.currency)} caption="Calendar month · UTC" /></section>
    <ServerPerformancePanel id={id} currency={server.currency} />
    <div className="grid gap-6 lg:grid-cols-2"><section className={`${panelClass} space-y-4`}><h2 className="font-semibold">Plugin connection</h2><ServerStatus status={server.status} /><p className="text-xs text-muted-foreground">Last heartbeat: {utcDate(server.last_seen_at)}</p><dl className="grid grid-cols-2 gap-4 text-sm"><div><dt className="text-xs text-muted-foreground">Plugin version</dt><dd className="mt-1">{server.plugin_version ?? "Not detected"}</dd></div><div><dt className="text-xs text-muted-foreground">Minecraft version</dt><dd className="mt-1">{server.minecraft_version ?? "Not detected"}</dd></div></dl>{issues.length ? <ul className="list-disc space-y-2 pl-4 text-sm text-muted-foreground">{issues.map((issue) => <li key={issue}>{issue}</li>)}</ul> : <p className="text-sm text-muted-foreground">Validated impressions are arriving. No connection issues detected.</p>}<UnavailablePluginLinks /></section>
      <section className={`${panelClass} space-y-4`}><h2 className="font-semibold">Activation management</h2><p className="text-sm text-muted-foreground">{server.activated_at ? `Activated ${utcDate(server.activated_at)}. Your setup token is hidden after setup.` : "Generate a new setup token if you did not save the original."}</p><p className="text-sm text-muted-foreground">Regenerating disconnects the existing plugin and requires activation again. The new token expires in 24 hours.</p><Button variant="outline" disabled={server.status === "suspended" || busy} onClick={() => { setError(""); setAction("regenerate"); }}>Regenerate setup token</Button></section></div>
    <section className={`${panelClass} flex flex-wrap items-center justify-between gap-4`}><div><h2 className="font-semibold">Danger zone</h2><p className="mt-2 text-sm text-muted-foreground">Disable the connection or remove this server. Historical earnings and impressions are preserved.</p></div><div className="flex flex-wrap gap-2"><Button variant="outline" disabled={server.status === "suspended" || busy} onClick={() => { setError(""); setAction("status"); }}>{server.enabled ? "Disable server" : "Enable server"}</Button><Button variant="destructive" onClick={() => { setError(""); setName(""); setAction("remove"); }}>Remove server</Button></div></section>
    <Dialog open={action !== null} onOpenChange={(open) => { if (!open && !busy) setAction(null); }}>{action && <DialogContent><DialogTitle>{action === "remove" ? "Remove server?" : action === "regenerate" ? "Regenerate setup token?" : server.enabled ? "Disable server?" : "Enable server?"}</DialogTitle><DialogDescription>{action === "remove" ? "The server will disappear from your workspace and its plugin credentials will be revoked. Historical reports are preserved." : action === "regenerate" ? "This revokes existing plugin credentials and disconnects the server until you activate it with the new token." : server.enabled ? "Ad delivery will stop until you enable the server again." : "Ad delivery can resume when the activated plugin is connected."}</DialogDescription>
      {action === "remove" && <label className="space-y-2 text-sm"><span>Type {server.name} to confirm</span><Input value={name} onChange={(event) => setName(event.target.value)} autoComplete="off" /></label>}{error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <DialogFooter><Button variant="outline" disabled={busy} onClick={() => setAction(null)}>Cancel</Button><Button variant={action === "remove" ? "destructive" : "default"} disabled={busy || (action === "remove" && name !== server.name)} onClick={() => void confirm()}>{busy ? "Saving…" : "Confirm"}</Button></DialogFooter>
    </DialogContent>}</Dialog>
  </>;
}
