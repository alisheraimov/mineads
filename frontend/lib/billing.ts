import { apiRequest, getToken } from "@/lib/auth";

export type BillingMonth = {
  month: string;
  is_current: boolean;
  starting_balance_micros: number;
  funds_added_micros: number;
  spend_micros: number;
  adjustments_micros: number;
  ending_balance_micros: number;
};
export type AdvertiserBilling = {
  account: { name: string; status: string; currency: string };
  metrics: {
    available_balance_micros: number;
    funds_added_month_micros: number;
    spend_month_micros: number;
    impressions_month: number;
  };
  low_balance: boolean;
  funding_available: boolean;
  months: BillingMonth[];
  generated_at: string;
  pagination: { page: number; last_page: number; total: number };
};
export function getBilling(page: number, signal?: AbortSignal) {
  return apiRequest<{ data: AdvertiserBilling }>(
    `/advertiser/billing?page=${page}`,
    { token: getToken(), signal },
  );
}
