import { apiRequest, getToken } from "@/lib/auth";

export type CampaignAdvertiser = { id: number; name: string; email: string; status?: string };
export type PageMeta = { current_page: number; last_page: number; total: number; from?: number | null; to?: number | null };
export type AdminCampaign = {
  id: number; name: string; status: string;
  advertiser: CampaignAdvertiser | null; starts_at: string | null; ends_at: string | null;
  created_at: string; updated_at: string; deleted_at: string | null;
  daily_budget_micros?: number | null; total_budget_micros?: number | null;
  spend_micros?: number; impressions: number; creatives_count: number; pending_creatives_count: number; last_delivery_at: string | null;
};
export type AdminCampaignListResponse = {
  data: AdminCampaign[]; meta: PageMeta; can_view_money: boolean; can_view_advertiser: boolean;
  selected_advertiser: CampaignAdvertiser | null; generated_at: string;
};
export type CampaignPoint = { date: string; impressions: number; spend_micros?: number; effective_cpm_micros?: number | null };
export type AdminCampaignDetail = Omit<AdminCampaign, "spend_micros" | "impressions" | "creatives_count" | "pending_creatives_count" | "last_delivery_at"> & {
  can_view_money: boolean; can_delete: boolean; can_view_advertiser: boolean;
  delivery_eligible: boolean; delivery_issues: string[];
  targeting_rules: { countries: string[]; server_category: string | null }[];
  metrics: { reported_impressions: number; validated_impressions: number; invalid_impressions: number; pending_impressions: number;
    invalid_rate: number; served_deliveries: number; last_delivery_at: string | null; spend_micros?: number; effective_cpm_micros?: number | null };
  budget?: { spent_today_micros: number; lifetime_spend_micros: number; available_balance_micros: number; daily_remaining_micros: number | null; total_remaining_micros: number | null };
  creative_counts: { total: number; approved: number; pending_review: number; rejected: number; deleted: number };
  performance: { interval: "day" | "month"; points: CampaignPoint[] };
  deleted_by: { id: number; name: string } | null;
  history: { id: number; actor_id: number | null; actor_name: string; action: string; created_at: string }[];
  generated_at: string;
};
export type AdminCampaignCreative = { id: number; name: string | null; message_text: string; click_url: string | null; language: string | null;
  status: string; is_paused: boolean; rejection_reason: string | null; created_at: string };
export function listAdminCampaigns(query: string, signal?: AbortSignal) {
  return apiRequest<AdminCampaignListResponse>("/admin/campaigns?" + query, { token: getToken(), signal });
}
export function findCampaignAdvertisers(search: string, signal?: AbortSignal) {
  return apiRequest<{ data: CampaignAdvertiser[] }>("/admin/campaigns/advertisers?" + new URLSearchParams({ search }), { token: getToken(), signal });
}
export function getAdminCampaign(id: string, signal?: AbortSignal) {
  return apiRequest<{ data: AdminCampaignDetail }>("/admin/campaigns/" + id, { token: getToken(), signal });
}
export function getAdminCampaignCreatives(id: number, page: number, signal?: AbortSignal) {
  return apiRequest<{ data: AdminCampaignCreative[]; meta: PageMeta }>(`/admin/campaigns/${id}/creatives?page=${page}`, { token: getToken(), signal });
}
export function deleteAdminCampaign(id: number, name: string) {
  return apiRequest<{ message: string; data: AdminCampaignDetail }>("/admin/campaigns/" + id, { method: "DELETE", token: getToken(), body: JSON.stringify({ name }) });
}
