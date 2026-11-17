import type { ReactNode } from "react";
import Link from "next/link";
import { ExternalLink, Info } from "lucide-react";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { cn } from "@/lib/utils";

export type SummaryCardProps = {
  label: string;
  value: ReactNode;
  caption?: ReactNode;
  href?: string;
  help?: string;
  icon?: ReactNode;
  indicator?: ReactNode;
  valueClassName?: string;
};

export function SummaryCard({ label, value, caption, href, help, icon, indicator, valueClassName }: SummaryCardProps) {
  return (
    <div className="summary-card" data-linked={href ? "true" : undefined}>
      <dl className="flex h-full min-w-0 flex-col">
        <dt className="summary-label flex items-start gap-2">
          {icon && <span aria-hidden="true" className="shrink-0">{icon}</span>}
          {indicator}
          <span className="min-w-0 flex-1">{label}</span>
          {help && <Tooltip><TooltipTrigger asChild>
            <button type="button" className="relative z-10 shrink-0 rounded-sm focus-visible:outline-2 focus-visible:outline-ring" aria-label={`How ${label.toLowerCase()} is calculated`}>
              <Info className="size-3.5" aria-hidden="true" />
            </button>
          </TooltipTrigger><TooltipContent className="max-w-72">{help}</TooltipContent></Tooltip>}
          {href && <ExternalLink aria-hidden="true" className="size-4 shrink-0 text-foreground/60" />}
        </dt>
        <dd className={cn("summary-value mt-3", valueClassName)}>{value}</dd>
        {caption && <dd className="summary-caption mt-auto pt-2">{caption}</dd>}
      </dl>
      {href && <Link href={href} className="absolute inset-0 rounded-xl outline-none" aria-label={`View ${label.toLowerCase()}`} />}
    </div>
  );
}
