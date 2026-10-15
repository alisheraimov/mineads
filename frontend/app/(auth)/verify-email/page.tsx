import { Suspense } from "react";

import { VerifyEmailPanel } from "@/components/auth/verify-email-panel";

export default function VerifyEmailPage() {
  return (
    <Suspense>
      <VerifyEmailPanel />
    </Suspense>
  );
}
