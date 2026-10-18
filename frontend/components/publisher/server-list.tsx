"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { AddServerButton } from "@/components/publisher/publisher-shell";
import { ServerBreadcrumb, ServerMetric, ServerStatus, utcDate } from "@/components/publisher/server-shared";
import { ServerList, listServers, serverCategories } from "@/lib/servers";

export function ServerListPage() {
  const [data, setData] = useState<ServerList | null>(null);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [category, setCategory] = useState("");
  const [page, setPage] = useState(1);
  const [refresh, setRefresh] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  useEffect(() => {
    let mounted = true;
    const timer = setTimeout(() => {
      setLoading(true);
      setError("");
      const params = new URLSearchParams({ page: String(page) });
      if (search) params.set("search", search);
      if (status) params.set("status", status);
      if (category) params.set("category", category);
      listServers(params).then((result) => { if (mounted) setData(result); })
        .catch((err) => { if (mounted) setError(err instanceof Error ? err.message : "Unable to load servers."); })
        .finally(() => { if (mounted) setLoading(false); });
    }, 250);
    return () => { mounted = false; clearTimeout(timer); };
  }, [search, status, category, page, refresh]);

  return <>
    <ServerBreadcrumb />
    <div className="flex flex-wrap items-center justify-between gap-4"><div><h1 className="text-2xl font-semibold">Servers</h1><p className="mt-2 text-sm text-muted-foreground">Manage connected servers and monitor plugin health.</p></div><AddServerButton /></div>
    {data && <>
      <p className="text-xs text-muted-foreground">Last updated {utcDate(data.meta.generated_at)}</p>
      <section aria-label="Server summary" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <ServerMetric label="Total servers" value={data.meta.total_servers} caption="Across your workspace" />
        <ServerMetric label="Active servers" value={data.meta.active_servers} caption="Receiving recent heartbeats" />
        <ServerMetric label="Players online" value={data.meta.online_players} caption="Across active servers" />
        <ServerMetric label="Needs attention" value={data.meta.needs_attention} caption="Setup, suspension or connection" />
      </section>
    </>}
    <div className="flex flex-wrap items-end gap-3">
      <label className="flex min-w-48 flex-1 flex-col gap-2 text-sm">Search servers<Input value={search} placeholder="Search name or host…" onChange={(event) => { setSearch(event.target.value); setPage(1); }} /></label>
      <Select value={status || "all"} onValueChange={(value) => { setStatus(value === "all" ? "" : value); setPage(1); }}>
        <SelectTrigger aria-label="Filter by status" className="min-w-36 data-[size=default]:h-10">
          <SelectValue placeholder="All statuses" />
        </SelectTrigger>
        <SelectContent position="popper" align="start">
          <SelectGroup>
            <SelectItem value="all">All statuses</SelectItem>
            {["active", "pending", "offline", "inactive", "suspended"].map((value) => (
              <SelectItem key={value} value={value}>{value === "inactive" ? "Disabled" : value.charAt(0).toUpperCase() + value.slice(1)}</SelectItem>
            ))}
          </SelectGroup>
        </SelectContent>
      </Select>
      <Select value={category || "all"} onValueChange={(value) => { setCategory(value === "all" ? "" : value); setPage(1); }}>
        <SelectTrigger aria-label="Filter by category" className="min-w-40 data-[size=default]:h-10">
          <SelectValue placeholder="All categories" />
        </SelectTrigger>
        <SelectContent position="popper" align="start">
          <SelectGroup>
            <SelectItem value="all">All categories</SelectItem>
            {serverCategories.map((value) => (
              <SelectItem key={value} value={value}>{value === "smp" ? "SMP" : value.charAt(0).toUpperCase() + value.slice(1)}</SelectItem>
            ))}
          </SelectGroup>
        </SelectContent>
      </Select>
      <Button variant="outline" disabled={loading} onClick={() => setRefresh((value) => value + 1)}>Refresh status</Button>
    </div>
    {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
    {loading && <p role="status" className="flex items-center gap-2 text-sm text-muted-foreground"><Loader2 className="size-4 animate-spin" />Loading servers…</p>}
    {data && <>
      <div className="overflow-x-auto rounded-lg border bg-card" aria-busy={loading}>
        <table className="w-full min-w-[950px] text-left text-xs"><thead className="border-b bg-muted/40 text-muted-foreground"><tr>{["Server name", "Host / domain", "Category", "Status", "Last seen", "Online players", "Plugin version", "Minecraft version"].map((label) => <th key={label} className="px-3 py-4 font-medium">{label}</th>)}</tr></thead>
          <tbody>{data.data.map((server) => <tr key={server.id} className="border-b last:border-0 hover:bg-muted/30">
            <td className="px-3 py-4"><Link className="font-medium underline-offset-4 hover:underline" href={`/publisher/servers/${server.id}`}>{server.name}</Link></td>
            <td className="px-3 py-4">{server.host}</td><td className="px-3 py-4 capitalize">{server.category}</td><td className="px-3 py-4"><ServerStatus status={server.status} /></td><td className="px-3 py-4">{utcDate(server.last_seen_at)}</td><td className="px-3 py-4">{server.online_players}</td><td className="px-3 py-4">{server.plugin_version ?? "Not detected"}</td><td className="px-3 py-4">{server.minecraft_version ?? "Not detected"}</td>
          </tr>)}{!data.data.length && <tr><td colSpan={8} className="px-6 py-12 text-center text-sm text-muted-foreground">{data.meta.total_servers ? "No servers match these filters." : "No servers yet. Add your first Minecraft server to get started."}</td></tr>}</tbody>
        </table>
      </div>
      {data.meta.last_page > 1 && <div className="flex items-center justify-end gap-3 text-sm"><Button variant="outline" disabled={page === 1 || loading} onClick={() => setPage(page - 1)}>Previous</Button><span>Page {page} of {data.meta.last_page}</span><Button variant="outline" disabled={page >= data.meta.last_page || loading} onClick={() => setPage(page + 1)}>Next</Button></div>}
    </>}
  </>;
}
