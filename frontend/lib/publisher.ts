import { apiRequest, CommercialAccount, getToken } from "@/lib/auth";

export type PublisherDashboard = {
  account: CommercialAccount;
  server_count: number;
  period: {
    today_start: string;
    month_start: string;
    month_end: string;
    chart_start: string;
    timezone: "UTC";
  };
  metrics: {
    earnings_today_micros: number;
    earnings_month_micros: number;
    impressions_today: number;
    impressions_month: number;
    effective_cpm_micros: number;
    available_payout_micros: number;
  };
  performance_chart: {
    date: string;
    label: string;
    earnings_micros: number;
    impressions: number;
  }[];
  generated_at: string;
};

export function getPublisherDashboard() {
  return apiRequest<{ data: PublisherDashboard }>("/publisher/dashboard", {
    token: getToken(),
  });
}
