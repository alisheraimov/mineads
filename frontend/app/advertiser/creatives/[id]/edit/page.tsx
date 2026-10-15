import { Suspense } from "react";
import { CreativeForm } from "@/components/advertiser/creative-form";
export default async function Page({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  return (
    <Suspense>
      <CreativeForm key={id} id={id} />
    </Suspense>
  );
}
