import { apiRequest, getToken, ApiError } from "@/lib/auth";
import type { PageMeta } from "@/lib/admin-campaigns";

export type FinancePermissions = { users: boolean; campaigns: boolean; servers: boolean; manage: boolean };
export type FinanceIdentity = { id: number; name: string; deleted: boolean; status?: string };
export type LedgerEntry = {
 id: number; type: string; amount_micros: number; currency: string; reference_type: string | null; reference_id: number | null; created_at: string;
 account: FinanceIdentity; related: FinanceIdentity | null; settled_at: string | null; held: boolean;
};
export type LedgerResult = { data: LedgerEntry[]; summary: Record<string, number>; types: string[]; meta: PageMeta; permissions: FinancePermissions; generated_at: string };
export const payoutStatuses = ["pending", "failed", "paid", "cancelled"] as const;
export type PayoutStatus = typeof payoutStatuses[number];
export type FinancePayout = {
 id: number; amount_micros: number; currency: string; status: PayoutStatus; period_start: string; period_end: string;
 created_at: string; paid_at: string | null; version: number; provider: string; payment_method: string | null;
 external_reference: string | null; reason: string | null; internal_note: string | null; publisher: FinanceIdentity | null;
};
export type FinancePayoutDetail = FinancePayout & {
 can_manage: boolean; balances: { available_micros: number; awaiting_micros: number; reserved_micros: number }; minimum_micros: number;
 history: { id: number; action: string; actor_name: string; created_at: string; details: { from?: string; to?: string; amount_micros?: number; reason?: string | null; external_reference?: string | null; paid_at?: string | null; internal_note?: string | null } }[];
 history_meta: PageMeta;
};
export type PayoutListResult = { data: FinancePayout[]; totals: Record<PayoutStatus, { count: number; amount_micros: number }>; meta: PageMeta; permissions: FinancePermissions; generated_at: string };
export type FinanceMetric = "spend_micros" | "earnings_micros" | "revenue_micros";
export type FinancePoint = Record<FinanceMetric | `previous_${FinanceMetric}`, number> & { date: string; to: string; previous_from: string; previous_to: string };
export type FinanceOverview = {
 metrics: Record<FinanceMetric | "paid_micros" | "impressions", number>;
 balances: { advertiser_funds_micros: number; awaiting_micros: number; held_micros: number; available_micros: number; reserved_micros: number; inconsistent_count: number; inconsistencies: { user_id: number; available_micros: number }[] };
 workload: { status: PayoutStatus; count: number; amount_micros: number }[];
 points: FinancePoint[]; period: { from: string; to: string }; generated_at: string;
};
const request = <T>(path: string, signal?: AbortSignal) => apiRequest<T>("/admin/finance" + path, { token: getToken(), signal });
export const getFinanceOverview = (query: string, signal?: AbortSignal) => request<{ data: FinanceOverview }>("?" + query, signal);
export const getFinanceLedger = (kind: string, query: string, signal?: AbortSignal) => request<LedgerResult>("/ledgers/" + kind + "?" + query, signal);
export const getFinancePayouts = (query: string, signal?: AbortSignal) => request<PayoutListResult>("/payouts?" + query, signal);
export const getFinancePayout = (id: string, page: string, signal?: AbortSignal) => request<{ data: FinancePayoutDetail; permissions: FinancePermissions }>("/payouts/" + id + "?page=" + page, signal);
export const getFinanceOptions = (kind: string, search: string, account: string, signal?: AbortSignal) => request<{ data: (FinanceIdentity & { email: string })[]; selected_name: string | null; related: { id: number; name: string }[] }>("/options?" + new URLSearchParams({ kind, search, ...(account === "all" ? {} : { account }) }), signal);
export const recordPayoutOutcome = (id: number, body: object) => apiRequest<{ data: FinancePayoutDetail; message: string }>("/admin/finance/payouts/" + id + "/actions", { token: getToken(), method: "POST", body: JSON.stringify(body) });
export async function exportFinance(path: string, query: string) {
 const result = await fetch((process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api") + "/admin/finance/" + path + "/export?" + query, { headers: { Accept: "text/csv", Authorization: "Bearer " + getToken() } });
 if (!result.ok) { const error = await result.json().catch(() => ({})); throw new ApiError(error.message ?? "Unable to export financial records.", result.status); }
 const url = URL.createObjectURL(await result.blob());
 const link = document.createElement("a"); link.href = url;
 link.download = result.headers.get("content-disposition")?.match(/filename="?([^";]+)"?/)?.[1] ?? "mineads-" + path.replaceAll("/", "-") + "-" + new Date().toISOString().slice(0,10) + ".csv";
 document.body.appendChild(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export function exactUsd(micros: number, signed = false) {
 return (signed && micros > 0 ? "+" : "") + new Intl.NumberFormat("en-US", { style: "currency", currency: "USD", minimumFractionDigits: 2, maximumFractionDigits: 6 }).format(micros / 1_000_000);
}
export function financeLabel(value: string) { return ({ pending_earning: "Awaiting release", payable: "Released", bank_transfer: "Bank transfer", paypal: "PayPal" } as Record<string, string>)[value] ?? value.charAt(0).toUpperCase() + value.slice(1).replaceAll("_", " "); }
export function financeTime(value: string) { return new Date(value).toLocaleString("en-US", { year: "numeric", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false, timeZone: "UTC" }) + " UTC"; }
