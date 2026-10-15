import { AdvertiserShell } from "@/components/advertiser/advertiser-shell";
export default function BillingLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return <AdvertiserShell>{children}</AdvertiserShell>;
}
