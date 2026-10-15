"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Check, ChevronsUpDown, Info, Loader2, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { DateTimePicker } from "@/components/ui/date-picker";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command";
import {
  CampaignOptions,
  campaignOptions,
  getCampaign,
  saveCampaign,
} from "@/lib/campaigns";
import {
  displayCountry,
  displayLanguage,
  dollarsToMicros,
  formatDate,
  formatMicros,
} from "@/lib/advertiser-format";
import { cn } from "@/lib/utils";
import {
  CampaignBreadcrumb,
  CampaignError,
  CampaignLoading,
  Properties,
} from "./campaign-shared";

type Draft = {
  name: string;
  starts_at: string;
  ends_at: string;
  daily: string;
  total: string;
  countries: string[];
  languages: string[];
  server_categories: string[];
};
const steps = ["Basics", "Targeting", "Budget", "Review & submit"];

function initialDraft(): Draft {
  const start = new Date();
  const end = new Date(start);
  end.setUTCDate(end.getUTCDate() + 30);
  return {
    name: "",
    starts_at: start.toISOString().slice(0, 16),
    ends_at: end.toISOString().slice(0, 16),
    daily: "",
    total: "",
    countries: [],
    languages: [],
    server_categories: [],
  };
}

export function CampaignWizard({ id }: { id?: string }) {
  const router = useRouter();
  const [draft, setDraft] = useState<Draft>(initialDraft);
  const [options, setOptions] = useState<CampaignOptions | null>(null);
  const [step, setStep] = useState(0);
  const [confirmed, setConfirmed] = useState(false);
  const [error, setError] = useState("");
  const [loadError, setLoadError] = useState("");
  const [busy, setBusy] = useState(false);
  const [reload, setReload] = useState(0);
  useEffect(() => {
    let mounted = true;
    setLoadError("");
    Promise.all([
      campaignOptions(),
      id ? getCampaign(id) : Promise.resolve(null),
    ])
      .then(([optionsResponse, campaign]) => {
        if (!mounted) return;
        if (campaign?.data.status === "ended")
          throw new Error("Ended campaigns cannot be edited.");
        if (campaign) {
          const data = campaign.data;
          setDraft({
            name: data.name,
            starts_at: data.starts_at?.slice(0, 16) ?? "",
            ends_at: data.ends_at?.slice(0, 16) ?? "",
            daily:
              data.daily_budget_micros === null
                ? ""
                : (data.daily_budget_micros / 1_000_000).toFixed(2),
            total:
              data.total_budget_micros === null
                ? ""
                : (data.total_budget_micros / 1_000_000).toFixed(2),
            countries: data.countries,
            languages: data.languages,
            server_categories: data.server_categories,
          });
        }
        setOptions(optionsResponse.data);
      })
      .catch((error) => {
        if (mounted)
          setLoadError(
            error instanceof Error
              ? error.message
              : "Unable to load campaign settings.",
          );
      });
    return () => {
      mounted = false;
    };
  }, [id, reload]);

  function change<K extends keyof Draft>(key: K, value: Draft[K]) {
    setDraft((previous) => ({ ...previous, [key]: value }));
    setConfirmed(false);
    setError("");
  }
  function validate(current: number): string | null {
    if (current === 0) {
      if (!draft.name.trim()) return "Enter a campaign name.";
      if (!draft.starts_at || !draft.ends_at)
        return "Choose a start and end date.";
      if (
        Number.isNaN(Date.parse(`${draft.starts_at}:00Z`)) ||
        Number.isNaN(Date.parse(`${draft.ends_at}:00Z`))
      )
        return "Choose valid start and end dates and times.";
      if (
        new Date(`${draft.ends_at}:00Z`) <= new Date(`${draft.starts_at}:00Z`)
      )
        return "The end date must be after the start date.";
    }
    if (current === 1) {
      if (
        !draft.countries.length ||
        !draft.languages.length ||
        !draft.server_categories.length
      )
        return "Select at least one country, language and server category.";
      if (draft.languages.length > 20) return "Select up to 20 languages.";
    }
    if (current === 2) {
      const daily = dollarsToMicros(draft.daily);
      const total = dollarsToMicros(draft.total);
      if (daily === null || daily < 10000 || daily > 1_000_000_000_000)
        return "Enter a daily budget between $0.01 and $1,000,000, with up to two decimal places.";
      if (total === null || total < daily || total > 1_000_000_000_000)
        return "The total budget must be at least the daily budget and no more than $1,000,000.";
    }
    return null;
  }
  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setError("");
    if (step < 3) {
      const issue = validate(step);
      if (issue) {
        setError(issue);
        return;
      }
      setStep(step + 1);
      return;
    }
    for (let index = 0; index < 3; index++) {
      const issue = validate(index);
      if (issue) {
        setStep(index);
        setError(issue);
        return;
      }
    }
    if (!confirmed) {
      setError("Confirm that you have reviewed your campaign settings.");
      return;
    }
    setBusy(true);
    try {
      const result = await saveCampaign(
        {
          name: draft.name.trim(),
          starts_at: `${draft.starts_at}:00Z`,
          ends_at: `${draft.ends_at}:00Z`,
          daily_budget_micros: dollarsToMicros(draft.daily)!,
          total_budget_micros: dollarsToMicros(draft.total)!,
          countries: draft.countries,
          languages: draft.languages,
          server_categories: draft.server_categories,
        },
        id,
      );
      router.push(`/advertiser/campaigns/${result.data.id}`);
    } catch (error) {
      setError(
        error instanceof Error ? error.message : "Unable to save campaign.",
      );
      setBusy(false);
    }
  }
  const descriptions = [
    "Give your campaign a name and a schedule.",
    "Choose the audience for your campaign.",
    "Set spending caps for your campaign.",
    "Review your campaign before submitting it.",
  ];
  const cancelHref = id
    ? `/advertiser/campaigns/${id}`
    : "/advertiser/campaigns";

  return (
    <div className="mx-auto flex max-w-[1112px] flex-col gap-6">
      <CampaignBreadcrumb current={id ? "Edit campaign" : "Create campaign"} />
      <div>
        <h1 className="text-2xl font-semibold">
          {id ? "Edit campaign" : "Create campaign"}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Step {step + 1} of 4 · {descriptions[step]}
        </p>
      </div>
      {loadError ? (
        <CampaignError
          message={loadError}
          retry={() => setReload((value) => value + 1)}
        />
      ) : !options ? (
        <CampaignLoading />
      ) : (
        <>
          <ol
            aria-label="Campaign setup progress"
            className="grid grid-cols-2 gap-4 rounded-lg border bg-card p-4 sm:grid-cols-4"
          >
            {steps.map((label, index) => (
              <li key={label}>
                <button
                  type="button"
                  disabled={index > step || busy}
                  aria-current={index === step ? "step" : undefined}
                  onClick={() => {
                    setStep(index);
                    setError("");
                  }}
                  className={cn(
                    "flex items-center gap-2 text-left text-xs sm:text-sm",
                    index !== step && "text-muted-foreground",
                  )}
                >
                  <span
                    className={cn(
                      "grid size-6 shrink-0 place-items-center rounded-full bg-muted text-xs",
                      index === step && "bg-foreground text-background",
                    )}
                  >
                    {index < step ? <Check className="size-3" /> : index + 1}
                  </span>
                  {label}
                </button>
              </li>
            ))}
          </ol>
          <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1.8fr)_minmax(240px,1fr)]">
            <form className="min-w-0 space-y-6" onSubmit={submit}>
              {error && <CampaignError message={error} />}
              {step === 0 && (
                <section className="space-y-5 rounded-lg border bg-card p-6">
                  <div>
                    <h2 className="text-base font-medium">Campaign basics</h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                      Use a name that helps you recognize this campaign later.
                    </p>
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="campaign-name">
                      Campaign name · required
                    </Label>
                    <Input
                      id="campaign-name"
                      autoFocus
                      required
                      maxLength={255}
                      value={draft.name}
                      onChange={(event) => change("name", event.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                      Internal name, not shown to players.
                    </p>
                  </div>
                  <div className="grid gap-4 sm:grid-cols-2">
                    <fieldset className="min-w-0 space-y-3">
                      <legend className="text-sm font-medium">
                        Start date & time · UTC
                      </legend>
                      <DateTimePicker
                        id="campaign-start"
                        label="Start · UTC"
                        value={draft.starts_at}
                        onChange={(value) => change("starts_at", value)}
                      />
                    </fieldset>
                    <fieldset className="min-w-0 space-y-3">
                      <legend className="text-sm font-medium">
                        End date & time · UTC
                      </legend>
                      <DateTimePicker
                        id="campaign-end"
                        label="End · UTC"
                        value={draft.ends_at}
                        min={draft.starts_at}
                        onChange={(value) => change("ends_at", value)}
                      />
                    </fieldset>
                  </div>
                  <p className="text-sm text-muted-foreground">
                    The schedule determines when the campaign can serve.
                    Delivery also depends on creative approval, targeting,
                    budget and account balance.
                  </p>
                </section>
              )}
              {step === 1 && (
                <section className="space-y-5 rounded-lg border bg-card p-6">
                  <div>
                    <h2 className="text-base font-medium">
                      Audience targeting
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                      Choose countries, languages and server categories.
                    </p>
                  </div>
                  <MultiSelect
                    label="Countries"
                    values={draft.countries}
                    options={options.countries}
                    name={displayCountry}
                    onChange={(values) => change("countries", values)}
                  />
                  <MultiSelect
                    label="Languages"
                    values={draft.languages}
                    options={options.languages}
                    name={displayLanguage}
                    max={20}
                    onChange={(values) => change("languages", values)}
                  />
                  <fieldset className="space-y-3">
                    <legend className="text-sm font-medium">
                      Server categories · required
                    </legend>
                    <div className="flex flex-wrap gap-5">
                      {options.server_categories.map((category) => (
                        <label
                          key={category}
                          className="flex items-center gap-2 text-sm capitalize"
                        >
                          <Checkbox
                            checked={draft.server_categories.includes(category)}
                            onCheckedChange={(checked) =>
                              change(
                                "server_categories",
                                checked
                                  ? [...draft.server_categories, category]
                                  : draft.server_categories.filter(
                                      (value) => value !== category,
                                    ),
                              )
                            }
                          />
                          {category}
                        </label>
                      ))}
                    </div>
                  </fieldset>
                  <p className="text-sm text-muted-foreground">
                    Targeting narrows where a campaign is eligible to serve. Use
                    creative languages that match your selected audience.
                  </p>
                </section>
              )}
              {step === 2 && (
                <section className="space-y-5 rounded-lg border bg-card p-6">
                  <div>
                    <h2 className="text-base font-medium">Campaign budget</h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                      Budgets are spending caps, not account deposits.
                    </p>
                  </div>
                  <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                      <Label htmlFor="daily-budget">
                        Daily budget · USD · required
                      </Label>
                      <Input
                        id="daily-budget"
                        inputMode="decimal"
                        required
                        placeholder="80.00"
                        value={draft.daily}
                        onChange={(event) =>
                          change("daily", event.target.value)
                        }
                      />
                      <p className="text-xs text-muted-foreground">
                        Maximum campaign spend in a UTC day.
                      </p>
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="total-budget">
                        Total budget · USD · required
                      </Label>
                      <Input
                        id="total-budget"
                        inputMode="decimal"
                        required
                        placeholder="1200.00"
                        value={draft.total}
                        onChange={(event) =>
                          change("total", event.target.value)
                        }
                      />
                      <p className="text-xs text-muted-foreground">
                        Maximum spend over the campaign’s lifetime.
                      </p>
                    </div>
                  </div>
                  <p className="text-sm text-muted-foreground">
                    Delivery stops when a budget is exhausted. Your account must
                    also have sufficient balance to serve ads.
                  </p>
                  <Properties
                    items={[
                      [
                        "Available account balance",
                        formatMicros(options.available_balance_micros),
                      ],
                      [
                        "Scheduled dates",
                        `${formatDate(`${draft.starts_at}:00Z`)} – ${formatDate(`${draft.ends_at}:00Z`)}`,
                      ],
                      [
                        "Billing model",
                        "Validated impressions decrease your balance",
                      ],
                    ]}
                  />
                </section>
              )}
              {step === 3 && (
                <>
                  {[
                    {
                      title: "Basics",
                      index: 0,
                      items: [
                        ["Campaign name", draft.name],
                        [
                          "Schedule · UTC",
                          `${formatDate(`${draft.starts_at}:00Z`, true)} – ${formatDate(`${draft.ends_at}:00Z`, true)}`,
                        ],
                      ],
                    },
                    {
                      title: "Targeting",
                      index: 1,
                      items: [
                        [
                          "Countries",
                          draft.countries.map(displayCountry).join(", "),
                        ],
                        [
                          "Languages",
                          draft.languages.map(displayLanguage).join(", "),
                        ],
                        [
                          "Server categories",
                          draft.server_categories.join(", "),
                        ],
                      ],
                    },
                    {
                      title: "Budget",
                      index: 2,
                      items: [
                        [
                          "Daily budget",
                          formatMicros(dollarsToMicros(draft.daily)),
                        ],
                        [
                          "Total budget",
                          formatMicros(dollarsToMicros(draft.total)),
                        ],
                      ],
                    },
                  ].map((section) => (
                    <section
                      key={section.title}
                      className="space-y-5 rounded-lg border bg-card p-6"
                    >
                      <div className="flex items-center justify-between">
                        <h2 className="text-base font-medium">
                          {section.title}
                        </h2>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          disabled={busy}
                          onClick={() => setStep(section.index)}
                        >
                          Edit
                        </Button>
                      </div>
                      <Properties
                        items={section.items as [string, React.ReactNode][]}
                      />
                    </section>
                  ))}
                  {!id && (
                    <Alert>
                      <Info />
                      <AlertTitle>
                        Next: submit a creative for review
                      </AlertTitle>
                      <AlertDescription>
                        This campaign has no attached creatives yet. After
                        creation, add a creative and fund your balance before
                        the scheduled start.
                      </AlertDescription>
                    </Alert>
                  )}
                  <label className="flex items-start gap-3 text-sm">
                    <Checkbox
                      checked={confirmed}
                      onCheckedChange={(value) => setConfirmed(value === true)}
                      disabled={busy}
                    />
                    <span>
                      I have reviewed this campaign’s schedule, targeting and
                      spending caps.
                    </span>
                  </label>
                </>
              )}
              <div className="flex justify-between gap-4">
                {step === 0 ? (
                  <Button type="button" variant="outline" asChild>
                    <Link href={cancelHref}>Cancel</Link>
                  </Button>
                ) : (
                  <Button
                    type="button"
                    variant="outline"
                    disabled={busy}
                    onClick={() => {
                      setStep(step - 1);
                      setError("");
                    }}
                  >
                    Back
                  </Button>
                )}
                <Button
                  type="submit"
                  disabled={busy || (step === 3 && !confirmed)}
                >
                  {busy && <Loader2 className="animate-spin" />}
                  {step === 3
                    ? id
                      ? "Save changes"
                      : "Create campaign"
                    : "Continue"}
                </Button>
              </div>
            </form>
            <aside className="space-y-5 rounded-lg border bg-card p-6">
              <h2 className="text-base font-medium">Before you launch</h2>
              <p className="text-sm text-muted-foreground">
                A campaign controls who sees your ads and how much you spend.
              </p>
              <p className="text-sm text-muted-foreground">
                Creatives are submitted separately for review. Creating a
                campaign does not approve an ad.
              </p>
              <p className="text-sm text-muted-foreground">
                Delivery requires an approved creative, an eligible schedule,
                available budget and sufficient account balance.
              </p>
            </aside>
          </div>
        </>
      )}
    </div>
  );
}

function MultiSelect({
  label,
  values,
  options,
  name,
  max,
  onChange,
}: {
  label: string;
  values: string[];
  options: string[];
  name: (value: string) => string;
  max?: number;
  onChange: (values: string[]) => void;
}) {
  const [open, setOpen] = useState(false);
  const sorted = [...new Set([...options, ...values])].sort((a, b) =>
    name(a).localeCompare(name(b)),
  );
  function toggle(value: string) {
    onChange(
      values.includes(value)
        ? values.filter((item) => item !== value)
        : [...values, value],
    );
  }
  return (
    <div className="space-y-3">
      <Label>{label} · required</Label>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            role="combobox"
            aria-label={`Select ${label.toLowerCase()}`}
            aria-expanded={open}
            className="w-full justify-between font-normal"
          >
            <span className="truncate text-muted-foreground">
              Search {label.toLowerCase()}…
            </span>
            <ChevronsUpDown className="size-4 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent
          className="w-[var(--radix-popover-trigger-width)] p-0"
          align="start"
        >
          <Command>
            <CommandInput placeholder={`Search ${label.toLowerCase()}…`} />
            <CommandList>
              <CommandEmpty>No matches found.</CommandEmpty>
              <CommandGroup>
                {sorted.map((value) => (
                  <CommandItem
                    key={value}
                    value={`${name(value)} ${value}`}
                    disabled={
                      !values.includes(value) && !!max && values.length >= max
                    }
                    onSelect={() => toggle(value)}
                  >
                    <Check
                      className={cn(
                        "size-4",
                        !values.includes(value) && "opacity-0",
                      )}
                    />
                    {name(value)}
                  </CommandItem>
                ))}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
      <div className="flex flex-wrap gap-2">
        {values.map((value) => (
          <span
            key={value}
            className="flex max-w-full items-center gap-1 rounded-md bg-muted px-2 py-1 text-xs"
          >
            <span className="break-words">{name(value)}</span>
            <button
              type="button"
              title={`Remove ${name(value)}`}
              aria-label={`Remove ${name(value)}`}
              onClick={() => toggle(value)}
              className="grid size-5 shrink-0 place-items-center rounded-sm hover:bg-border"
            >
              <X className="size-3" />
            </button>
          </span>
        ))}
      </div>
    </div>
  );
}
