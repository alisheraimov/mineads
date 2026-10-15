import { CampaignWizard } from "@/components/advertiser/campaign-wizard";

export default async function EditCampaignPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  return <CampaignWizard key={id} id={id} />;
}
