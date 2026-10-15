"use client";

import type { ComponentProps } from "react";

import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

export function TimePicker({
  className,
  step = 60,
  ...props
}: Omit<ComponentProps<typeof Input>, "type">) {
  return (
    <Input
      {...props}
      type="time"
      step={step}
      className={cn(
        "appearance-none bg-background [&::-webkit-calendar-picker-indicator]:hidden [&::-webkit-calendar-picker-indicator]:appearance-none",
        className,
      )}
    />
  );
}
