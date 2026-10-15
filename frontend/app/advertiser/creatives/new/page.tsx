import { Suspense } from "react";
import { CreativeForm } from "@/components/advertiser/creative-form";
export default function Page() {
  return (
    <Suspense>
      <CreativeForm />
    </Suspense>
  );
}
