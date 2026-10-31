"use client";

import * as React from "react";
import { Tooltip as TooltipPrimitive } from "radix-ui";
import { cn } from "@/lib/utils";

export function TooltipProvider(props: React.ComponentProps<typeof TooltipPrimitive.Provider>) {
 return <TooltipPrimitive.Provider delayDuration={200} {...props} />;
}
export function Tooltip(props: React.ComponentProps<typeof TooltipPrimitive.Root>) {
 return <TooltipProvider><TooltipPrimitive.Root {...props} /></TooltipProvider>;
}
export function TooltipTrigger(props: React.ComponentProps<typeof TooltipPrimitive.Trigger>) {
 return <TooltipPrimitive.Trigger data-slot="tooltip-trigger" {...props} />;
}
export function TooltipContent({ className, sideOffset = 4, ...props }: React.ComponentProps<typeof TooltipPrimitive.Content>) {
 return <TooltipPrimitive.Portal><TooltipPrimitive.Content data-slot="tooltip-content" sideOffset={sideOffset} className={cn("z-50 max-w-72 rounded-md bg-foreground px-3 py-1.5 text-xs text-background shadow-md animate-in fade-in-0 zoom-in-95", className)} {...props} /></TooltipPrimitive.Portal>;
}
