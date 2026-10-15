import { CampaignDetailsPage } from "@/components/advertiser/campaign-detail";

export default async function CampaignPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  return <CampaignDetailsPage key={id} id={id} />;
}
