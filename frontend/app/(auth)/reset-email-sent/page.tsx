import { Suspense } from "react";

import { ResetEmailSentPanel } from "@/components/auth/auth-status-panel";

export default function ResetEmailSentPage() {
  return (
    <Suspense>
      <ResetEmailSentPanel />
    </Suspense>
  );
}
