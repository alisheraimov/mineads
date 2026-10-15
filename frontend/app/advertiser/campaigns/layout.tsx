import { AdvertiserShell } from "@/components/advertiser/advertiser-shell";

export default function CampaignsLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return <AdvertiserShell>{children}</AdvertiserShell>;
}
