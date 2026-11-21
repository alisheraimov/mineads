"use client";

import { Eye, EyeOff } from "lucide-react";
import { useState, type ComponentProps } from "react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

export function PasswordInput({ id, disabled, ...props }: Omit<ComponentProps<"input">, "type" | "className">) {
  const [isVisible, setIsVisible] = useState(false);
  const Icon = isVisible ? EyeOff : Eye;

  return (
    <div className="relative">
      <Input
        {...props}
        id={id}
        disabled={disabled}
        type={isVisible ? "text" : "password"}
        className="pr-11 [&::-ms-reveal]:hidden"
        autoCapitalize="none"
        spellCheck={false}
      />
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="absolute inset-y-1 right-1 text-muted-foreground"
        aria-label={isVisible ? "Hide password" : "Show password"}
        aria-controls={id}
        disabled={disabled}
        onClick={() => setIsVisible((visible) => !visible)}
      >
        <Icon aria-hidden="true" />
      </Button>
    </div>
  );
}
