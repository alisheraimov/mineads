"use client";
import { useServerCategories, ServerCategoryFeedback } from "@/components/server-categories";

import Link from "next/link";
import { FormEvent, useCallback, useState } from "react";
import { Check } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { ServerBreadcrumb, UnavailablePluginLinks, panelClass } from "@/components/publisher/server-shared";
import { ServerSetup } from "@/components/publisher/server-setup";
import { ApiError } from "@/lib/auth";
import { SetupResult, createServer } from "@/lib/servers";
import { cn } from "@/lib/utils";

export function ServerCreatePage() {
 const { categories: serverCategories, ...categoryState } = useServerCategories();
  const [setup, setSetup] = useState<SetupResult | null>(null);
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const [activated, setActivated] = useState(false);
  const handleActivated = useCallback(() => setActivated(true), []);
  const currentStep = activated ? 2 : setup ? 1 : 0;
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setBusy(true); setError(""); setFields({});
    try { const { data } = await createServer({ name: String(form.get("name")), host: String(form.get("host")), category: String(form.get("category")) }); setSetup(data); }
    catch (err) { setError(err instanceof Error ? err.message : "Unable to create server."); if (err instanceof ApiError) setFields(err.errors); }
    finally { setBusy(false); }
  }
  return <>
    <ServerBreadcrumb current={setup ? `${setup.server.name} / Setup` : "Add server"} />
    <div><h1 className="text-2xl font-semibold">{setup ? `Connect ${setup.server.name}` : "Add server"}</h1><p className="mt-2 text-sm text-muted-foreground">{setup ? "Server created. Complete the plugin setup to activate the connection." : "Create a server record, then activate the VoxelAds plugin."}</p></div>
    <ol aria-label="Server setup progress" className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-3">
      {["Server details", "Plugin setup", "Activation"].map((label, index) => {
        const completed = activated || index < currentStep;
        return <li key={label} aria-current={index === currentStep ? "step" : undefined} className={cn("flex items-center gap-2 text-xs sm:text-sm", index !== currentStep && "text-muted-foreground")}>
          <span className={cn("grid size-6 shrink-0 place-items-center rounded-full bg-muted text-xs", index === currentStep && "bg-foreground text-background")}>
            {completed ? <Check className="size-3" aria-hidden="true" /> : index + 1}
          </span>
          {label}
          {completed && <span className="sr-only">Completed</span>}
        </li>;
      })}
    </ol>
    {setup ? <ServerSetup setup={setup} onActivated={handleActivated} /> : <div className="grid gap-6 lg:grid-cols-[1.6fr_1fr]">
      <form onSubmit={submit} className={`${panelClass} space-y-5`}><h2 className="font-semibold">Server details</h2><p className="text-sm text-muted-foreground">All three fields are required.</p>
        <label className="block space-y-2 text-sm"><span>Server name</span><Input name="name" required maxLength={255} placeholder="Emerald Survival" aria-invalid={!!fields.name} /><span className="block text-xs text-muted-foreground">A friendly name to identify the server in your workspace.</span>{fields.name && <span className="text-xs text-destructive">{fields.name[0]}</span>}</label>
        <label className="block space-y-2 text-sm"><span>Server host</span><Input name="host" required maxLength={253} placeholder="play.example.com:25565" aria-invalid={!!fields.host} /><span className="block text-xs text-muted-foreground">The domain or IP players use to connect. An optional port is supported.</span>{fields.host && <span className="text-xs text-destructive">{fields.host[0]}</span>}</label>
        <div className="space-y-2 text-sm">
          <label htmlFor="server-category">Server category</label>
          <Select name="category" required defaultValue="" disabled={categoryState.loading || !!categoryState.error}>
            <SelectTrigger id="server-category" className="w-full data-[size=default]:h-10" aria-invalid={!!fields.category} aria-describedby={fields.category ? "server-category-error" : undefined}>
              <SelectValue placeholder="Select a category" />
            </SelectTrigger>
            <SelectContent position="popper" align="start">
              <SelectGroup>
                {serverCategories.map((category) => (
                  <SelectItem key={category} value={category}>{category === "smp" ? "SMP" : category.charAt(0).toUpperCase() + category.slice(1)}</SelectItem>
                ))}
              </SelectGroup>
            </SelectContent>
          </Select>
          <ServerCategoryFeedback {...categoryState} />
          {fields.category && <p id="server-category-error" className="text-xs text-destructive">{fields.category[0]}</p>}
        </div>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <div className="flex gap-2"><Button type="submit" disabled={busy || categoryState.loading || !!categoryState.error}>{busy ? "Creating…" : "Create server"}</Button><Button asChild variant="outline"><Link href="/publisher/servers">Cancel</Link></Button></div>
      </form><aside className={`${panelClass} h-fit space-y-4`}><h2 className="font-semibold">What happens next</h2><p className="text-sm">Your server starts as Pending until the plugin connects.</p><p className="text-sm text-muted-foreground">Use the one-time setup token to activate the plugin. Minecraft and plugin versions are detected from the first heartbeat.</p><UnavailablePluginLinks /></aside>
    </div>}
  </>;
}
