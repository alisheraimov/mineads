import * as React from "react";

import { Button } from "@/components/ui/button";

export function PrimaryButton({
  className = "",
  ...props
}: React.ComponentProps<typeof Button>) {
  return (
    <Button
      className={`!text-primary-foreground hover:!text-primary-foreground ${className}`}
      {...props}
    />
  );
}
