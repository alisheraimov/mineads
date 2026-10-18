import { ServerWorkspace } from "@/components/publisher/server-shared";
export default function ServerLayout({ children }: { children: React.ReactNode }) {
  return <ServerWorkspace>{children}</ServerWorkspace>;
}
