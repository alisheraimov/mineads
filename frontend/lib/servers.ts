import { apiRequest, getToken } from "@/lib/auth";

export const serverCategories = ["survival", "skyblock", "factions", "minigames", "creative", "anarchy", "smp", "other"] as const;
export type ManagedServer = {
  id: number; name: string; host: string; category: string;
  status: "pending" | "active" | "offline" | "inactive" | "suspended";
  enabled: boolean; last_seen_at: string | null; online_players: number;
  plugin_version: string | null; minecraft_version: string | null;
  plugin_update_available: boolean | null;
};
export type ServerDetail = ManagedServer & {
  currency: string; created_at: string; activated_at: string | null;
  setup_token_expires_at: string | null; earnings_today_micros: number;
  earnings_month_micros: number; impressions_today: number; generated_at: string;
};
export type SetupResult = { server: ManagedServer; setup_token: string; setup_token_expires_at: string };
export type ServerList = { data: ManagedServer[]; meta: {
  page: number; per_page: number; total: number; last_page: number;
  total_servers: number; active_servers: number; online_players: number;
  needs_attention: number; generated_at: string;
} };
export type PerformancePoint = {
  date: string; label: string; earnings_micros: number; impressions: number; effective_cpm_micros: number;
  previous_earnings_micros: number; previous_impressions: number; previous_effective_cpm_micros: number;
};
export type ServerPerformance = {
  from: string; to: string; group: string; compare: boolean; timezone: string;
  previous_from: string; previous_to: string; earnings_micros: number; impressions: number;
  points: PerformancePoint[];
};
export function listServers(params: URLSearchParams) {
  return apiRequest<ServerList>(`/publisher/servers?${params}`, { token: getToken() });
}
export function getServer(id: number) {
  return apiRequest<{ data: ServerDetail }>(`/publisher/servers/${id}`, { token: getToken() });
}
export function createServer(data: { name: string; host: string; category: string }) {
  return apiRequest<{ data: SetupResult }>("/publisher/servers", { token: getToken(), method: "POST", body: JSON.stringify(data) });
}
export function regenerateServerToken(id: number) {
  return apiRequest<{ data: SetupResult }>(`/publisher/servers/${id}/setup-token`, { token: getToken(), method: "POST" });
}
export function setServerEnabled(id: number, enabled: boolean) {
  return apiRequest<{ data: ServerDetail }>(`/publisher/servers/${id}/status`, { token: getToken(), method: "PATCH", body: JSON.stringify({ enabled }) });
}
export function removeServer(id: number, name: string) {
  return apiRequest<{ message: string }>(`/publisher/servers/${id}`, { token: getToken(), method: "DELETE", body: JSON.stringify({ name }) });
}
export function serverPerformance(id: number, params: URLSearchParams) {
  return apiRequest<{ data: ServerPerformance }>(`/publisher/servers/${id}/performance?${params}`, { token: getToken() });
}
