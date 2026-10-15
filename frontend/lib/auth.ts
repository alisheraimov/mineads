export type UserRole = "advertiser" | "publisher" | "admin";

export type AccountStatus = "active" | "suspended";

export type CommercialAccount = {
  id: number;
  name: string;
  status: AccountStatus;
  currency: string;
};

export type User = {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  status: AccountStatus;
  avatar_url: string | null;
  email_verified_at: string | null;
  permissions: string[];
  advertiser_account?: CommercialAccount | null;
  publisher_account?: CommercialAccount | null;
};

export type AuthResponse = {
  token: string;
  user: User;
};

type RegisterPayload = {
  email: string;
  password: string;
  password_confirmation: string;
  name?: string;
};

type LoginPayload = {
  email: string;
  password: string;
};

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api";
const TOKEN_KEY = "mineads_token";

export function getToken() {
  if (typeof window === "undefined") {
    return null;
  }

  return window.localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string) {
  window.localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken() {
  window.localStorage.removeItem(TOKEN_KEY);
}

export function googleRedirectUrl() {
  return `${API_URL}/auth/google/redirect`;
}

export async function register(payload: RegisterPayload) {
  return apiRequest<AuthResponse>("/auth/register", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export async function login(payload: LoginPayload) {
  return apiRequest<AuthResponse>("/auth/login", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export async function getCurrentUser() {
  return apiRequest<{ data: User }>("/auth/me", {
    token: getToken(),
  });
}

export async function logout() {
  const token = getToken();

  if (!token) {
    return;
  }

  await apiRequest<{ message: string }>("/auth/logout", {
    method: "POST",
    token,
  });
  clearToken();
}

export async function resendVerificationEmail() {
  return apiRequest<{ message: string }>("/auth/email/verification-notification", {
    method: "POST",
    token: getToken(),
  });
}

export async function completeOnboarding(role: "advertiser" | "publisher") {
  return apiRequest<{ user: User }>("/auth/onboarding", {
    method: "POST",
    token: getToken(),
    body: JSON.stringify({ role }),
  });
}

export async function forgotPassword(email: string) {
  return apiRequest<{ message: string }>("/auth/forgot-password", {
    method: "POST",
    body: JSON.stringify({ email }),
  });
}

export async function resetPassword(payload: {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
}) {
  return apiRequest<{ message: string }>("/auth/reset-password", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

type ApiOptions = RequestInit & {
  token?: string | null;
};

export async function apiRequest<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");

  if (options.body) {
    headers.set("Content-Type", "application/json");
  }

  if (options.token) {
    headers.set("Authorization", `Bearer ${options.token}`);
  }

  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    headers,
  });

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(resolveApiMessage(payload, response.statusText));
  }

  return payload as T;
}

function resolveApiMessage(payload: unknown, fallback: string) {
  if (
    payload &&
    typeof payload === "object" &&
    "message" in payload &&
    typeof payload.message === "string"
  ) {
    return payload.message;
  }

  if (
    payload &&
    typeof payload === "object" &&
    "errors" in payload &&
    payload.errors &&
    typeof payload.errors === "object"
  ) {
    const errors = Object.values(payload.errors);
    const first = Array.isArray(errors[0]) ? errors[0][0] : errors[0];

    if (typeof first === "string") {
      return first;
    }
  }

  return fallback || "Something went wrong.";
}
