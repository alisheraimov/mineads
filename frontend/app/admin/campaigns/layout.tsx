import { AdminShell } from "@/components/admin/admin-shell";

export default function CampaignsLayout({ children }: { children: React.ReactNode }) {
  return <AdminShell requiredPermission="admin.campaigns.view">{children}</AdminShell>;
}
