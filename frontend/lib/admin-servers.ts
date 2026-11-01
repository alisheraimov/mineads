import { apiRequest, getToken } from "@/lib/auth";
import type { PageMeta } from "@/lib/admin-campaigns";
export type PublisherIdentity = { id: number; name: string; email: string; status: string };
export type AdminServer = {
 id: number; name: string; host: string; category: string; status: string; review_status: string; legacy_approval: boolean; review_reason: string | null; suspension_reason: string | null;
 connection: string; last_seen_at: string | null; online_players: number | null; max_players: number | null; plugin_version: string | null; minecraft_version: string | null; plugin_update_available: boolean;
 publisher: PublisherIdentity | null; has_deleted_parent: boolean; created_at: string; activated_at: string | null; submitted_at: string | null; reviewed_at: string | null; deleted_at: string | null;
};
export type ServerEvent = { id: number; actor_name: string; action: string; created_at: string; details: { host: string; previous_host?: string; name: string; category: string; status: string; review_status: string; review_reason?: string | null; suspension_reason?: string | null } };
export type AdminServerDetail = AdminServer & { version: string; can_manage: boolean; can_view_money: boolean; can_view_publisher: boolean; history: ServerEvent[] };
export type ServerListResult = { data: AdminServer[]; meta: PageMeta; counts: Record<string, number>; can_view_publisher: boolean };
export type PublisherRow = PublisherIdentity & { servers_count: number; active_servers_count: number; last_heartbeat_at: string | null; earnings_micros?: number };
export type PublisherListResult = { data: PublisherRow[]; meta: PageMeta; can_view_money: boolean; can_view_publisher: boolean };
export type ServerActivity = {
 metrics: { valid: number; invalid: number; pending: number; invalid_rate: number | null; earnings_micros?: number };
 points: { date: string; valid: number; invalid: number; players: number | null; minimum: number | null; maximum: number | null; earnings_micros?: number }[];
 reasons: { reason: string | null; count: number; percent: number }[];
 impressions: { id: number; time: string; validation_status: string; invalid_reason: string | null; campaign: { id: number; name: string } | null }[];
 meta: PageMeta; period: { from: string; to: string; hourly: boolean }; can_view_campaign: boolean;
};
export function adminServerList(query: string, signal?: AbortSignal) { return apiRequest<ServerListResult>("/admin/servers?" + query, { token: getToken(), signal }); }
export function adminPublisherList(query: string, signal?: AbortSignal) { return apiRequest<PublisherListResult>("/admin/publishers?" + query, { token: getToken(), signal }); }
export function adminServerDetail(id: number, signal?: AbortSignal) { return apiRequest<{ data: AdminServerDetail }>("/admin/servers/" + id, { token: getToken(), signal }); }
export function adminServerActivity(id: number, query: string, signal?: AbortSignal) { return apiRequest<{ data: ServerActivity }>("/admin/servers/" + id + "/activity?" + query, { token: getToken(), signal }); }
export function adminServerHistory(id: number, page: string, signal?: AbortSignal) { return apiRequest<{ data: ServerEvent[]; meta: PageMeta }>("/admin/servers/" + id + "/history?page=" + page, { token: getToken(), signal }); }
export type ServerAction = "approve" | "reject" | "suspend" | "reactivate" | "delete";
export function adminServerAction(data: AdminServerDetail, action: ServerAction, reason: string, confirmation: string) { return apiRequest<{ data: AdminServerDetail; message: string }>("/admin/servers/" + data.id + (action === "delete" ? "" : "/moderate"), { token: getToken(), method: action === "delete" ? "DELETE" : "POST", body: JSON.stringify({ version: data.version, action: action === "delete" ? undefined : action, reason: reason || undefined, confirmation: confirmation || undefined }) }); }
export const stateLabel = (value: string) => ({ awaiting_activation: "Awaiting activation", pending_review: "Pending review", never_connected: "Never connected", inactive: "Disabled", valid: "Validated" }[value] ?? value.charAt(0).toUpperCase() + value.slice(1).replaceAll("_", " "));

