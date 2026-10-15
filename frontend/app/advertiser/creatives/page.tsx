import { Suspense } from "react";
import { CreativesListPage } from "@/components/advertiser/creative-list";
export default function Page() {
  return (
    <Suspense>
      <CreativesListPage />
    </Suspense>
  );
}
