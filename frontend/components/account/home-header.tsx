"use client";

import { useEffect, useState } from "react";

import { AccountHeader } from "@/components/account/account-header";
import {
  ApiError,
  clearToken,
  getCurrentUser,
  getToken,
  logout,
  User,
} from "@/lib/auth";

export function HomeHeader() {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    let mounted = true;
    if (!getToken()) {
      setIsLoading(false);
      return;
    }

    getCurrentUser()
      .then(({ data }) => {
        if (mounted) setUser(data);
      })
      .catch((error) => {
        if (mounted && error instanceof ApiError && error.status === 401)
          clearToken();
      })
      .finally(() => {
        if (mounted) setIsLoading(false);
      });

    return () => {
      mounted = false;
    };
  }, []);

  async function handleLogout() {
    await logout().catch(() => clearToken());
    setUser(null);
  }

  return (
    <AccountHeader user={user} onLogout={handleLogout} isLoading={isLoading} />
  );
}
