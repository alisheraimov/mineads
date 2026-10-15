import { CreativeDetailsPage } from "@/components/advertiser/creative-detail";
export default async function Page({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  return <CreativeDetailsPage key={id} id={id} />;
}
