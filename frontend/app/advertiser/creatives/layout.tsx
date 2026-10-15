import { AdvertiserShell } from "@/components/advertiser/advertiser-shell";
export default function CreativesLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return <AdvertiserShell>{children}</AdvertiserShell>;
}
