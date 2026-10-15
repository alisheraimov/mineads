import { Suspense } from "react";

import { AccountReadyPanel } from "@/components/auth/auth-status-panel";

export default function AccountReadyPage() {
  return (
    <Suspense>
      <AccountReadyPanel />
    </Suspense>
  );
}
