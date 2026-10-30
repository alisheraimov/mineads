import { Suspense } from "react";
import { AdminCreativeDetails } from "@/components/admin/admin-creative-details";
export default async function CreativePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <Suspense><AdminCreativeDetails key={id} id={id} /></Suspense>;
}

