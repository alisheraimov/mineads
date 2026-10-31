import { AdminShell } from "@/components/admin/admin-shell";
export default function Layout({children}:{children:React.ReactNode}) { return <AdminShell requiredPermission="admin.finance.view">{children}</AdminShell>; }

