import { Suspense } from "react";
import { AdminCampaignList } from "@/components/admin/admin-campaign-list";

export default function CampaignsPage() {
  return <Suspense><AdminCampaignList /></Suspense>;
}
