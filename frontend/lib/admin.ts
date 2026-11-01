import { apiRequest, getToken } from "@/lib/auth";

export type AdminSnapshot = {
  active_campaigns: number;
  active_servers: number;
  online_servers: number;
  pending_creatives: number;
  oldest_pending_creative_at: string | null;
  unhealthy_servers: number;
  offline_servers: number;
  high_invalid_rate_servers: number;
  pending_payouts: number;
  pending_payout_micros: number;
  health_rules: {
    heartbeat_timeout_seconds: number;
    invalid_rate_percent: number;
    minimum_processed_impressions: number;
  };
  generated_at: string;
};

export type AdminPeriod = "today" | "yesterday" | "last7" | "last30" | "custom";
export type AdminPerformance = {
  period: { key: AdminPeriod; from: string; to: string; timezone: string; interval: "hour" | "day" };
  metrics: {
    reported_impressions: number;
    validated_impressions: number;
    invalid_impressions: number;
    pending_impressions: number;
    invalid_rate: number;
    spend_micros: number;
    earnings_micros: number;
    fee_micros: number;
  };
  chart: {
    date: string;
    label: string;
    validated: number;
    invalid: number;
    spend_micros: number;
    earnings_micros: number;
    fee_micros: number;
  }[];
  generated_at: string;
};

export function getAdminSnapshot(signal?: AbortSignal) {
  return apiRequest<{ data: AdminSnapshot }>("/admin/dashboard", { token: getToken(), signal });
}

export function getAdminPerformance(query: string, signal?: AbortSignal) {
  return apiRequest<{ data: AdminPerformance }>("/admin/dashboard/performance?" + query, { token: getToken(), signal });
}
