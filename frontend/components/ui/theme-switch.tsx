"use client";

import { useState } from "react";
import { Check, Monitor, Moon, Sun } from "lucide-react";

import { useTheme } from "@/components/theme-provider";
import { Button } from "@/components/ui/button";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import type { ThemePreference } from "@/lib/theme";

const options = [
  { value: "light", label: "Light", icon: Sun },
  { value: "dark", label: "Dark", icon: Moon },
  { value: "system", label: "System", icon: Monitor },
] as const;

export function ThemeSwitch() {
  const { preference, setPreference } = useTheme();
  const [open, setOpen] = useState(false);

  function chooseTheme(value: ThemePreference) {
    setPreference(value);
    setOpen(false);
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          aria-label="Change theme"
          title="Change theme"
        >
          <Sun className="dark:hidden" aria-hidden="true" />
          <Moon className="hidden dark:block" aria-hidden="true" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align="end"
        className="w-40 gap-1 p-1"
        aria-label="Theme preference"
      >
        {options.map(({ value, label, icon: Icon }) => (
          <Button
            key={value}
            variant="ghost"
            className="h-9 justify-start"
            aria-pressed={preference === value}
            onClick={() => chooseTheme(value)}
          >
            <Icon aria-hidden="true" />
            {label}
            {preference === value && (
              <Check className="ml-auto" aria-hidden="true" />
            )}
          </Button>
        ))}
      </PopoverContent>
    </Popover>
  );
}
