import { AdminShell } from "@/components/admin/admin-shell";

export default function UsersLayout({ children }: { children: React.ReactNode }) {
  return <AdminShell>{children}</AdminShell>;
}
