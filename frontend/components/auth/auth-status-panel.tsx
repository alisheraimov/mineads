"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { CircleCheck, LockKeyhole, MailCheck } from "lucide-react";

import { AuthIntro } from "@/components/auth/auth-shell";
import { Button } from "@/components/ui/button";
import { PrimaryButton } from "@/components/ui/primary-button";
import { startRouteProgress } from "@/components/ui/route-progress";
import { clearToken } from "@/lib/auth";

export function ResetEmailSentPanel() {
  const searchParams = useSearchParams();
  const email = searchParams.get("email") ?? "you@example.com";

  return (
    <div className="flex w-full max-w-[360px] flex-col gap-6">
      <AuthIntro
        icon={<MailCheck className="size-6" aria-hidden="true" />}
        title="Check your inbox"
        description={`If an account exists for ${email}, you'll receive a password reset link.`}
      />
      <p className="text-center text-sm leading-5 text-muted-foreground">
        Follow the link in the email to set a new password. Check your spam folder if you don&apos;t see it.
      </p>
      <PrimaryButton asChild className="h-10 w-full">
        <Link href="/login">Back to sign in</Link>
      </PrimaryButton>
      <p className="text-center text-sm leading-5 text-muted-foreground">
        Need another link?{" "}
        <Link className="font-medium text-foreground" href="/forgot-password">
          Try again
        </Link>
      </p>
      <Link className="text-center text-sm font-medium leading-5" href="/forgot-password">
        Use a different email
      </Link>
    </div>
  );
}

export function PasswordResetSuccessPanel() {
  return (
    <div className="flex w-full max-w-[360px] flex-col gap-6">
      <AuthIntro
        icon={<CircleCheck className="size-6" aria-hidden="true" />}
        title="Password updated"
        description="Your password has been reset. Sign in with your new password to return to MineAds."
      />
      <PrimaryButton asChild className="h-10 w-full">
        <Link href="/login">Return to sign in</Link>
      </PrimaryButton>
    </div>
  );
}

export function AccountReadyPanel() {
  const searchParams = useSearchParams();
  const role = searchParams.get("role") === "publisher" ? "publisher" : "advertiser";
  const isPublisher = role === "publisher";

  return (
    <div className="flex w-full max-w-[360px] flex-col gap-6">
      <AuthIntro
        icon={<CircleCheck className="size-6" aria-hidden="true" />}
        title={isPublisher ? "You're ready to monetize" : "You're ready to advertise"}
        description={isPublisher ? "Your publisher account is ready." : "Your advertiser account is ready."}
      >
        <p className="order-first text-xs font-medium leading-4 text-muted-foreground">
          Selected path · {isPublisher ? "Monetize a server" : "Advertise"}
        </p>
      </AuthIntro>
      <p className="text-center text-sm leading-5 text-muted-foreground">
        {isPublisher
          ? "Add your Minecraft server to get started. You'll find plugin setup, earnings and payouts in your account."
          : "Start with your first campaign. You'll manage your creatives, budgets and campaign reports in your account."}
      </p>
      <PrimaryButton asChild className="h-10 w-full">
        <Link href={isPublisher ? "/publisher" : "/account"}>
          {isPublisher ? "Go to publisher dashboard" : "Create your first campaign"}
        </Link>
      </PrimaryButton>
    </div>
  );
}

export function AccountSuspendedPanel() {
  const router = useRouter();

  function handleSignOut() {
    clearToken();
    startRouteProgress();
    router.push("/login");
  }

  return (
    <div className="flex w-full max-w-[400px] flex-col gap-6">
      <AuthIntro
        icon={<LockKeyhole className="size-6" aria-hidden="true" />}
        title="Account suspended"
        description="You can't access MineAds while your account is suspended."
      />
      <div className="rounded-md border border-border px-2.5 py-2">
        <p className="text-sm font-medium leading-5">Your data is preserved</p>
        <p className="text-sm leading-5 text-muted-foreground">
          Your campaigns, servers, ledger entries and account history are kept. Nothing has been deleted.
        </p>
      </div>
      <p className="text-center text-sm leading-5 text-muted-foreground">Contact support for help with your account status.</p>
      <div className="flex flex-col gap-3">
        <PrimaryButton className="h-10 w-full">Contact support</PrimaryButton>
        <Button variant="outline" className="h-10 w-full" onClick={handleSignOut}>
          Sign out
        </Button>
      </div>
    </div>
  );
}
