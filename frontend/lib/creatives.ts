import { apiRequest, getToken } from "@/lib/auth";

export type CreativeMetrics = {
  impressions: number;
  spend_micros: number;
  effective_cpm_micros: number;
};
export type AdvertiserCreative = {
  id: number;
  name: string;
  campaign_id: number;
  campaign_name: string;
  campaign_status: string;
  status: string;
  is_paused: boolean;
  rejection_reason: string | null;
  language: string | null;
  message_text: string;
  click_url: string | null;
  created_at: string;
  updated_at: string;
};
export type CreativeDetail = AdvertiserCreative & {
  metrics: CreativeMetrics;
  spend_chart: (CreativeMetrics & { date: string; label: string })[];
  period: { from: string; to: string };
  countries: {
    label: string | null;
    impressions: number;
    spend_micros: number;
  }[];
  server_categories: {
    label: string | null;
    impressions: number;
    spend_micros: number;
  }[];
};
export type CreativeList = {
  creatives: (AdvertiserCreative &
    Pick<CreativeMetrics, "impressions" | "spend_micros">)[];
  counts: Record<string, number>;
  pagination: { page: number; last_page: number; total: number };
};
export type CreativeOptions = {
  campaigns: { id: number; name: string; status: string }[];
  languages: string[];
};
export type CreativePayload = {
  name: string;
  campaign_id?: number;
  language: string;
  message_text: string;
  click_url: string | null;
};
export function creativeRequest<T>(
  path = "",
  options: { method?: string; body?: string; signal?: AbortSignal } = {},
) {
  return apiRequest<T>(`/advertiser/creatives${path}`, {
    ...options,
    token: getToken(),
  });
}
