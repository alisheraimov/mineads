import { ServerDetailPage } from "@/components/publisher/server-detail";
export default async function ServerPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <ServerDetailPage id={Number(id)} />;
}
