import { Suspense } from "react";
import { AdminUsers } from "@/components/admin/admin-users";

export default function UsersPage() {
  return <Suspense><AdminUsers /></Suspense>;
}
