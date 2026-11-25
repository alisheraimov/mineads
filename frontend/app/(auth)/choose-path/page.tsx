import { Suspense } from "react";

import { ChoosePathPanel } from "@/components/auth/choose-path-panel";

export default function ChoosePathPage() {
  return (
    <Suspense>
      <ChoosePathPanel />
    </Suspense>
  );
}
