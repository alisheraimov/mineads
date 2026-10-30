import { apiRequest, getToken } from "@/lib/auth";
import type { CampaignAdvertiser, PageMeta, CampaignPoint } from "@/lib/admin-campaigns";

export type CreativeOption = { id: number; name: string; email?: string };
export type AdminCreative = {
  id: number; name: string | null; message_text: string; click_url: string | null; language: string | null;
  status: string; is_paused: boolean; rejection_reason: string | null; rejection_code: string | null; rejection_explanation: string | null;
  campaign: { id: number; name: string; status: string } | null; advertiser: CampaignAdvertiser | null;
  created_at: string; updated_at: string; submitted_at: string; deleted_at: string | null; has_deleted_parent: boolean;
};
export type CreativeList = {
  data: AdminCreative[]; meta: PageMeta; pending_count: number; languages: string[]; can_review: boolean;
  can_view_campaign: boolean; can_view_advertiser: boolean; selected_advertiser: CreativeOption | null; selected_campaign: CreativeOption | null;
};
export type CreativeDetail = AdminCreative & {
  version: string; can_moderate: boolean; can_view_money: boolean; can_view_advertiser: boolean; can_view_campaign: boolean;
  metrics: { validated_impressions: number; invalid_impressions: number; invalid_rate: number | null; last_served_at: string | null; spend_micros?: number; effective_cpm_micros?: number | null };
  points: CampaignPoint[]; period: { from: string; to: string }; generated_at: string;
  history: { id: number; actor_name: string; action: string; created_at: string; details: { previous_status?: string; status?: string; reason?: string | null; message_text?: string; click_url?: string | null; language?: string | null } }[];
};
export const rejectionReasons = [
  { value: "misleading_content", label: "Misleading content" }, { value: "inappropriate_content", label: "Inappropriate content" },
  { value: "suspicious_url", label: "Broken or suspicious URL" }, { value: "unsupported_language", label: "Unsupported language" },
  { value: "formatting_issue", label: "Formatting issue" }, { value: "policy_violation", label: "Policy violation" }, { value: "other", label: "Other" },
];
export function listAdminCreatives(query: string, signal?: AbortSignal) {
  return apiRequest<CreativeList>("/admin/creatives?" + query, { token: getToken(), signal });
}
export function creativeOptions(kind: string, search: string, advertiser: string, signal?: AbortSignal) {
  const query = new URLSearchParams({ kind, search });
  if (advertiser && advertiser !== "all") query.set("advertiser_id", advertiser);
  return apiRequest<{ data: CreativeOption[] }>("/admin/creatives/options?" + query, { token: getToken(), signal });
}
export function getAdminCreative(id: string, query: string, signal?: AbortSignal) {
  return apiRequest<{ data: CreativeDetail }>("/admin/creatives/" + id + "?" + query, { token: getToken(), signal });
}
export function changeCreative(id: number, query: string, payload: Record<string, unknown>, deleting = false) {
  return apiRequest<{ data: CreativeDetail; message: string }>(`/admin/creatives/${id}${deleting ? "" : "/moderate"}?${query}`, {
    token: getToken(), method: deleting ? "DELETE" : "POST", body: JSON.stringify(payload),
  });
}
export function creativeDestination(url: string | null) {
  if (!url) return "No destination URL";
  try { return new URL(url).hostname; } catch { return "Invalid destination URL"; }
}

