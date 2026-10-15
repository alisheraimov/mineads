"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { Loader2, Send } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Alert, AlertDescription } from "@/components/ui/alert";
import {
  AdvertiserCreative,
  CreativeOptions,
  CreativePayload,
  creativeRequest,
} from "@/lib/creatives";
import { displayLanguage } from "@/lib/advertiser-format";
import { CampaignError, CampaignLoading } from "./campaign-shared";
import {
  ChatPreview,
  CreativeBreadcrumb,
  CreativeSelect,
} from "./creative-shared";

export function CreativeForm({ id }: { id?: string }) {
  const router = useRouter();
  const params = useSearchParams();
  const campaignId = params.get("campaign");
  const [options, setOptions] = useState<CreativeOptions | null>(null);
  const [original, setOriginal] = useState<AdvertiserCreative | null>(null);
  const [form, setForm] = useState({
    name: "",
    campaign: campaignId ?? "",
    language: "",
    message: "",
    url: "",
  });
  const [error, setError] = useState("");
  const [loadError, setLoadError] = useState("");
  const [busy, setBusy] = useState(false);
  const [reload, setReload] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    setLoadError("");
    Promise.all([
      creativeRequest<{ data: CreativeOptions }>("/options", {
        signal: controller.signal,
      }),
      id
        ? creativeRequest<{ data: AdvertiserCreative }>(`/${id}`, {
            signal: controller.signal,
          })
        : Promise.resolve(null),
    ])
      .then(([response, existing]) => {
        setOptions(response.data);
        if (existing) {
          const creative = existing.data;
          setOriginal(creative);
          setForm({
            name: creative.name,
            campaign: String(creative.campaign_id),
            language: creative.language ?? "",
            message: creative.message_text,
            url: creative.click_url ?? "",
          });
        }
      })
      .catch((error) => {
        if (!controller.signal.aborted) setLoadError(error.message);
      });
    return () => controller.abort();
  }, [id, reload]);
  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setError("");
    if (!form.campaign || !form.language) {
      setError("Choose a campaign and language.");
      return;
    }
    if ([...form.message.trim()].length > 512) {
      setError("The message must not exceed 512 characters.");
      return;
    }
    setBusy(true);
    const payload: CreativePayload = {
      name: form.name.trim(),
      language: form.language,
      message_text: form.message.trim(),
      click_url: form.url.trim() || null,
      ...(!id ? { campaign_id: Number(form.campaign) } : {}),
    };
    try {
      const response = await creativeRequest<{ data: AdvertiserCreative }>(
        id ? `/${id}` : "",
        { method: id ? "PUT" : "POST", body: JSON.stringify(payload) },
      );
      router.push(`/advertiser/creatives/${response.data.id}`);
    } catch (error) {
      setError(
        error instanceof Error ? error.message : "Unable to save creative.",
      );
      setBusy(false);
    }
  }
  const campaignOptions =
    options?.campaigns.map((campaign) => ({
      value: String(campaign.id),
      label: `${campaign.name} · CMP-${campaign.id}`,
    })) ?? [];
  if (
    original &&
    !campaignOptions.some(
      (option) => option.value === String(original.campaign_id),
    )
  )
    campaignOptions.push({
      value: String(original.campaign_id),
      label: original.campaign_name,
    });
  return (
    <div className="mx-auto flex max-w-[1112px] flex-col gap-6">
      <CreativeBreadcrumb current={id ? "Edit creative" : "Create creative"} />
      <h1 className="text-2xl font-semibold">
        {id ? "Edit creative" : "Create creative"}
      </h1>
      {loadError ? (
        <CampaignError
          message={loadError}
          retry={() => setReload((value) => value + 1)}
        />
      ) : !options ? (
        <CampaignLoading label="Loading creative form" />
      ) : (
        <form
          onSubmit={submit}
          className="grid items-start gap-8 lg:grid-cols-[1.15fr_1fr]"
        >
          <div className="space-y-6">
            <h2 className="text-base font-medium">Creative setup</h2>
            {!id && !options.campaigns.length && (
              <Alert>
                <AlertDescription>
                  No eligible campaigns yet.{" "}
                  <Link href="/advertiser/campaigns/new" className="underline">
                    Create a campaign
                  </Link>
                </AlertDescription>
              </Alert>
            )}
            <div className="space-y-2">
              <Label htmlFor="creative-name">Internal creative name</Label>
              <Input
                id="creative-name"
                required
                maxLength={255}
                value={form.name}
                onChange={(event) =>
                  setForm({ ...form, name: event.target.value })
                }
                disabled={busy}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="creative-campaign">Campaign</Label>
              <CreativeSelect
                id="creative-campaign"
                label="Campaign"
                value={form.campaign}
                options={campaignOptions}
                disabled={!!id || busy}
                onChange={(campaign) => setForm({ ...form, campaign })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="creative-language">Language</Label>
              <CreativeSelect
                id="creative-language"
                label="Language"
                value={form.language}
                options={options.languages.map((language) => ({
                  value: language,
                  label: displayLanguage(language),
                }))}
                disabled={busy}
                onChange={(language) => setForm({ ...form, language })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="creative-message">Chat message</Label>
              <Textarea
                id="creative-message"
                required
                rows={5}
                value={form.message}
                onChange={(event) =>
                  setForm({ ...form, message: event.target.value })
                }
                disabled={busy}
                aria-describedby="message-count"
              />
              <p
                id="message-count"
                className="text-right text-xs text-muted-foreground"
              >
                {[...form.message].length} / 512
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="creative-url">Click URL (optional)</Label>
              <Input
                id="creative-url"
                type="url"
                maxLength={255}
                placeholder="https://"
                value={form.url}
                disabled={busy}
                onChange={(event) =>
                  setForm({ ...form, url: event.target.value })
                }
              />
            </div>
            <Alert>
              <AlertDescription>
                {id
                  ? "Changes to the message, URL or language require another review. Renaming preserves approval."
                  : "New creatives require approval before they can serve."}
              </AlertDescription>
            </Alert>
            {error && <CampaignError message={error} />}
            <div className="flex flex-wrap justify-between gap-3">
              <Button
                type="button"
                variant="outline"
                disabled={busy}
                onClick={() =>
                  router.push(
                    id
                      ? `/advertiser/creatives/${id}`
                      : "/advertiser/creatives",
                  )
                }
              >
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={busy || (!id && !options.campaigns.length)}
              >
                {busy ? <Loader2 className="animate-spin" /> : <Send />}
                {id ? "Save changes" : "Submit for review"}
              </Button>
            </div>
          </div>
          <aside className="min-w-0 space-y-5 lg:sticky lg:top-6">
            <h2 className="text-base font-medium">Chat preview</h2>
            <ChatPreview message={form.message} url={form.url} />
          </aside>
        </form>
      )}
    </div>
  );
}
