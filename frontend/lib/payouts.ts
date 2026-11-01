import { apiRequest, getToken } from "@/lib/auth";

export const payoutStatuses = ["pending", "failed", "paid", "cancelled"] as const;
export type PayoutStatus = typeof payoutStatuses[number];
export type PayoutsData = {
  account: { name: string; status: string };
  metrics: { available_payout_micros: number; pending_earnings_micros: number; lifetime_paid_micros: number };
  policy: {
    minimum_payout_micros: number; threshold_met: boolean;
    hold_days_min: number; hold_days_max: number;
    estimated_window_start: string; estimated_window_end: string;
  };
  payout_method: { provider: "stripe"; status: "not_connected"; account: null; integration_available: false };
  history: {
    id: number; period_start: string; period_end: string; amount_micros: number;
    status: PayoutStatus; provider: string; created_at: string; paid_at: string | null;
  }[];
  pagination: { page: number; last_page: number; total: number; per_page: number };
  filter_years: number[];
  period: { start: string; end: string; timezone: "UTC" };
  generated_at: string;
};

export function getPayouts(params: URLSearchParams) {
  return apiRequest<{ data: PayoutsData }>(`/publisher/payouts?${params}`, { token: getToken() });
}
