import { apiRequest, getToken } from "@/lib/auth";

export type AdminRole = { value: string; label: string; description: string; permissions: string[] };
export type AdminUser = {
  id: number; name: string; email: string; role: "admin" | "advertiser" | "publisher";
  admin_role: string | null; status: "active" | "suspended" | "deleted";
  created_at: string; email_verified_at: string | null; deleted_at: string | null;
  suspension_reason: string | null; suspended_at: string | null;
};
export type UserList = {
  data: AdminUser[];
  meta: { current_page: number; last_page: number; total: number; from: number | null; to: number | null };
  roles: AdminRole[]; can_create_admin: boolean;
};
export type UserDetail = AdminUser & {
  permissions: string[]; can_manage: boolean; can_change_role: boolean; protected_reason: string | null;
  can_view_finance: boolean;
  account: null | { type: "advertiser" | "publisher"; status: string; currency: string; active_campaigns?: number;
    servers?: number; active_servers?: number; available_micros?: number; pending_micros?: number; lifetime_spend_micros?: number; lifetime_earnings_micros?: number };
  history: { id: number; action: string; details: { reason?: string; after?: string }; created_at: string }[];
};
export function listAdminUsers(query: string, signal?: AbortSignal) {
  return apiRequest<UserList>("/admin/users?" + query, { token: getToken(), signal });
}
export function getAdminUser(id: string, signal?: AbortSignal) {
  return apiRequest<{ data: UserDetail; roles: AdminRole[] }>("/admin/users/" + id, { token: getToken(), signal });
}
export function createAdmin(payload: { name: string; email: string; password: string; password_confirmation: string; admin_role: string }) {
  return apiRequest<{ data: AdminUser }>("/admin/users", { method: "POST", token: getToken(), body: JSON.stringify(payload) });
}
export function changeAdminUser(id: number, action: "suspend" | "reactivate" | "delete" | "role", payload: Record<string, string>) {
  return apiRequest<{ message: string }>("/admin/users/" + id + (action === "delete" ? "" : "/" + action), {
    method: action === "delete" ? "DELETE" : action === "role" ? "PATCH" : "POST", token: getToken(), body: JSON.stringify(payload),
  });
}
export const adminRoleLabels: Record<string, string> = { "admin-owner": "Owner", "ad-ops": "Ad Operations", finance: "Finance", support: "Support" };
export const roleLabel = (role: string) => role.charAt(0).toUpperCase() + role.slice(1);
export const userDate = (date: string) => new Date(date).toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric", timeZone: "UTC" });
export const userDateTime = (date: string) => userDate(date) + " · " + new Date(date).toLocaleTimeString("en-GB", {hour:"2-digit", minute:"2-digit", timeZone:"UTC"}) + " UTC";

export function permissionLabel(permission: string) {
  const [, section, action] = permission.split(".");
  return { section: ({users:"Users & accounts", campaigns:"Campaigns", creatives:"Creatives", servers:"Publishers & servers", analytics:"Analytics", finance:"Finance", plugins:"Plugins", "rate-tiers":"Rate tiers"} as Record<string,string>)[section] ?? section,
    action: action === "view" ? "View" : action === "review" ? "Review" : "Manage" };
}
