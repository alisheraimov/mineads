"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import { cn } from "cn";

import { AuthError, AuthIntro } from "@/components/auth/auth-shell";
import { PrimaryButton } from "@/components/ui/primary-button";
import { startRouteProgress } from "@/components/ui/route-progress";
import { completeOnboarding, getCurrentUser } from "@/lib/auth";

type Path = "advertiser" | "publisher";

const paths: Record<Path, { title: string; role: string; description: string; action: string }> = {
  advertiser: {
    title: "Advertise",
    role: "Advertiser account",
    description: "Reach players with your campaigns. Manage your creatives, budgets and reports.",
    action: "Continue as advertiser",
  },
  publisher: {
    title: "Monetize a server",
    role: "Publisher account",
    description: "Earn from ads on your Minecraft server. Manage your servers, earnings and payouts.",
    action: "Continue as publisher",
  },
};

export function ChoosePathPanel() {
  const router = useRouter();
  const [selectedPath, setSelectedPath] = useState<Path>("advertiser");
  const [error, setError] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    getCurrentUser()
      .then(({ data }) => {
        if (!data.email_verified_at) {
          startRouteProgress();
          router.replace(`/verify-email?email=${encodeURIComponent(data.email)}`);
        } else if (data.advertiser_account) {
          startRouteProgress();
          router.replace("/account-ready?role=advertiser");
        } else if (data.publisher_account) {
          startRouteProgress();
          router.replace("/account-ready?role=publisher");
        }
      })
      .catch(() => {
        startRouteProgress();
        router.replace("/login");
      });
  }, [router]);

  async function handleSubmit() {
    setError("");
    setIsSubmitting(true);

    try {
      await completeOnboarding(selectedPath);
      startRouteProgress();
      router.push(`/account-ready?role=${selectedPath}`);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to choose an account path.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex w-full max-w-[400px] flex-col gap-6">
      <AuthIntro title="Choose your path" description="How would you like to use MineAds?" />
      <div className="flex flex-col gap-3">
        {(Object.keys(paths) as Path[]).map((path) => {
          const active = selectedPath === path;
          const item = paths[path];

          return (
            <button
              type="button"
              key={path}
              onClick={() => setSelectedPath(path)}
              className={cn(
                "flex w-full gap-3 rounded-md border p-4 text-left transition-colors",
                active ? "border-foreground" : "border-border hover:border-foreground/50",
              )}
            >
              <span
                className={cn(
                  "mt-0.5 flex size-4 items-center justify-center rounded-full border",
                  active ? "border-primary bg-primary" : "border-border",
                )}
              >
                {active ? <span className="size-2 rounded-full bg-primary-foreground" /> : null}
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-sm font-medium leading-5">{item.title}</span>
                <span className="block text-xs leading-4 text-muted-foreground">{item.role}</span>
                <span className="mt-1 block text-sm leading-5 text-muted-foreground">{item.description}</span>
              </span>
            </button>
          );
        })}
      </div>
      <p className="text-center text-sm leading-5 text-muted-foreground">
        Choose one path for your account. You can&apos;t be both an advertiser and a publisher.
      </p>
      <AuthError message={error} />
      <PrimaryButton className="h-10 w-full" disabled={isSubmitting} onClick={handleSubmit}>
        {isSubmitting ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
        {paths[selectedPath].action}
      </PrimaryButton>
    </div>
  );
}
