import { apiRequest, getToken } from "@/lib/auth";

export type AdvertiserDashboard = {
  account: {
    id: number;
    name: string;
    status: "active" | "suspended";
    currency: string;
  };
  period: {
    today_start: string;
    month_start: string;
    month_end: string;
  };
  metrics: {
    available_balance_micros: number;
    active_campaigns: number;
    active_creatives: number;
    pending_creatives: number;
    spend_today_micros: number;
    spend_month_micros: number;
    impressions_today: number;
    impressions_month: number;
    effective_cpm_micros: number;
  };
  spend_chart: Array<{
    date: string;
    label: string;
    spend_micros: number;
  }>;
  running_campaigns: Array<{
    id: number;
    name: string;
    status: string;
    daily_budget_micros: number | null;
    spend_micros: number;
    impressions: number;
  }>;
  generated_at: string;
};

export async function getAdvertiserDashboard() {
  return apiRequest<{ data: AdvertiserDashboard }>("/advertiser/dashboard", {
    token: getToken(),
  });
}
