import { apiRequest, getToken } from "@/lib/auth";

export type Campaign = {
  id: number;
  name: string;
  status: string;
  daily_budget_micros: number | null;
  total_budget_micros: number | null;
  starts_at: string | null;
  ends_at: string | null;
};
export type CampaignRow = Campaign & {
  spend_micros: number;
  impressions: number;
  effective_cpm_micros: number;
  active_creatives_count: number;
};
export type CampaignList = {
  campaigns: CampaignRow[];
  counts: Record<string, number>;
  pagination: { page: number; last_page: number; total: number };
  account_name: string;
  generated_at: string;
};
export type Creative = {
  is_paused: boolean;
  id: number;
  name: string;
  status: string;
  language: string | null;
  message_text: string;
  click_url: string | null;
  impressions: number;
  spend_micros: number;
};
export type CampaignDetail = Campaign & {
  account_name: string;
  countries: string[];
  languages: string[];
  server_categories: string[];
  delivery_eligible: boolean;
  delivery_issues: string[];
  metrics: {
    spend_micros: number;
    impressions: number;
    effective_cpm_micros: number;
    spend_today_micros: number;
    spend_total_micros: number;
    spend_month_micros: number;
  };
  spend_chart: {
    date: string;
    label: string;
    spend_micros: number;
    impressions: number;
    effective_cpm_micros: number;
  }[];
  creatives: Creative[];
  period: { from: string; to: string };
  generated_at: string;
};
export type CampaignOptions = {
  countries: string[];
  languages: string[];
  server_categories: string[];
  available_balance_micros: number;
};
export type CampaignPayload = {
  name: string;
  starts_at: string;
  ends_at: string;
  daily_budget_micros: number;
  total_budget_micros: number;
  countries: string[];
  languages: string[];
  server_categories: string[];
};

export function campaignOptions() {
  return apiRequest<{ data: CampaignOptions }>(
    "/advertiser/campaigns/options",
    { token: getToken() },
  );
}
export function listCampaigns(params: URLSearchParams, signal?: AbortSignal) {
  return apiRequest<{ data: CampaignList }>(`/advertiser/campaigns?${params}`, {
    token: getToken(),
    signal,
  });
}
export function getCampaign(
  id: string,
  params = new URLSearchParams(),
  signal?: AbortSignal,
) {
  return apiRequest<{ data: CampaignDetail }>(
    `/advertiser/campaigns/${id}?${params}`,
    { token: getToken(), signal },
  );
}
export function saveCampaign(payload: CampaignPayload, id?: string) {
  return apiRequest<{ data: Campaign }>(
    `/advertiser/campaigns${id ? `/${id}` : ""}`,
    {
      method: id ? "PUT" : "POST",
      token: getToken(),
      body: JSON.stringify(payload),
    },
  );
}
export function setCampaignStatus(id: number, status: string) {
  return apiRequest<{ data: Campaign }>(`/advertiser/campaigns/${id}/status`, {
    method: "PATCH",
    token: getToken(),
    body: JSON.stringify({ status }),
  });
}
