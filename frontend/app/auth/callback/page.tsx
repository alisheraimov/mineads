"use client";

import { Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";

import { startRouteProgress } from "@/components/ui/route-progress";
import { getCurrentUser, setToken } from "@/lib/auth";

export default function AuthCallbackPage() {
  return (
    <Suspense
      fallback={
        <main className="grid min-h-screen place-items-center bg-background px-6">
          <p className="text-sm text-muted-foreground">Completing sign in...</p>
        </main>
      }
    >
      <AuthCallbackContent />
    </Suspense>
  );
}

function AuthCallbackContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [message, setMessage] = useState("Completing sign in...");

  useEffect(() => {
    const token = searchParams.get("token");

    if (!token) {
      setMessage("The sign in response did not include an access token.");
      return;
    }

    setToken(token);

    getCurrentUser()
      .then(({ data }) => {
        startRouteProgress();

        if (data.status === "suspended") {
          router.replace("/account-suspended");
        } else if (!data.email_verified_at) {
          router.replace(`/verify-email?email=${encodeURIComponent(data.email)}`);
        } else if (!data.advertiser_account && !data.publisher_account) {
          router.replace("/choose-path");
        } else {
          router.replace(data.role === "publisher" ? "/publisher" : "/");
        }
      })
      .catch(() => {
        setMessage("We signed you in, but could not load your account.");
      });
  }, [router, searchParams]);

  return (
    <main className="grid min-h-screen place-items-center bg-background px-6">
      <p className="text-sm text-muted-foreground">{message}</p>
    </main>
  );
}
