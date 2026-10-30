import { AdminCampaignDetails } from "@/components/admin/admin-campaign-details";

export default async function CampaignPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <AdminCampaignDetails key={id} id={id} />;
}
