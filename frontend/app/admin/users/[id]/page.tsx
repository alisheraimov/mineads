import { AdminUserDetails } from "@/components/admin/admin-user-details";

export default async function UserPage({ params }: { params: Promise<{id: string}> }) {
  const {id} = await params;
  return <AdminUserDetails id={id} />;
}
