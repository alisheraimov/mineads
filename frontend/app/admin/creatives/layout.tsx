import { AdminShell } from "@/components/admin/admin-shell";
export default function CreativesLayout({ children }: { children: React.ReactNode }) {
  return <AdminShell requiredPermission="admin.creatives.view">{children}</AdminShell>;
}

