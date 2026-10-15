"use client";

import Link from "next/link";
import { useState } from "react";
import { Check, ChevronsUpDown, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
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
import { CampaignStatus } from "./campaign-shared";
import { AdvertiserCreative } from "@/lib/creatives";

export function CreativeBreadcrumb({ current }: { current?: string }) {
  return (
    <nav
      aria-label="Breadcrumb"
      className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
    >
      <Link href="/advertiser">Advertiser</Link>
      <ChevronRight className="size-3.5" />
      <Link
        href="/advertiser/creatives"
        aria-current={!current ? "page" : undefined}
      >
        Creatives
      </Link>
      {current && (
        <>
          <ChevronRight className="size-3.5" />
          <span className="text-foreground">{current}</span>
        </>
      )}
    </nav>
  );
}
export function CreativeStatus({
  creative,
}: {
  creative: Pick<
    AdvertiserCreative,
    "status" | "is_paused" | "rejection_reason"
  >;
}) {
  return (
    <span
      className="inline-flex flex-wrap gap-1"
      title={creative.rejection_reason ?? undefined}
    >
      <CampaignStatus status={creative.status} />
      {creative.is_paused && <CampaignStatus status="paused" />}
    </span>
  );
}
export function ChatPreview({
  message,
  url,
}: {
  message: string;
  url: string | null;
}) {
  return (
    <div
      aria-label="Chat preview"
      className="min-h-52 space-y-4 rounded-md bg-[#202820] p-5 font-mono text-xs leading-6 text-white"
    >
      <p className="text-[#a5b5a4]">[Server] Welcome to the survival world.</p>
      <p className="text-[#b9c2b7]">&lt;Alex&gt; Anyone building near spawn?</p>
      <p className="whitespace-pre-wrap break-words">
        [Ad] {message || "Your message"}
      </p>
      {url && <p className="break-all text-[#9cc1f0]">{url}</p>}
    </div>
  );
}
export function CreativeSelect({
  id,
  label,
  value,
  options,
  onChange,
  disabled,
}: {
  id: string;
  label: string;
  value: string;
  options: { value: string; label: string }[];
  onChange: (value: string) => void;
  disabled?: boolean;
}) {
  const [open, setOpen] = useState(false);
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          role="combobox"
          aria-label={label}
          aria-expanded={open}
          disabled={disabled}
          className="h-auto min-h-9 w-full justify-between text-left font-normal"
        >
          <span className="min-w-0 break-words whitespace-normal">
            {options.find((option) => option.value === value)?.label ??
              `Select ${label.toLowerCase()}`}
          </span>
          <ChevronsUpDown className="shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="w-[var(--radix-popover-trigger-width)] p-0"
        align="start"
      >
        <Command>
          <CommandInput placeholder={`Search ${label.toLowerCase()}`} />
          <CommandList>
            <CommandEmpty>No matches found.</CommandEmpty>
            <CommandGroup>
              {options.map((option) => (
                <CommandItem
                  key={option.value}
                  value={`${option.label} ${option.value}`}
                  onSelect={() => {
                    onChange(option.value);
                    setOpen(false);
                  }}
                >
                  <span className="min-w-0 flex-1 break-words">
                    {option.label}
                  </span>
                  {value === option.value && <Check className="size-4" />}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
