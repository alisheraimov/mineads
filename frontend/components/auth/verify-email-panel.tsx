"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useState } from "react";
import { Loader2, MailCheck } from "lucide-react";

import { AuthError, AuthIntro } from "@/components/auth/auth-shell";
import { PrimaryButton } from "@/components/ui/primary-button";
import { resendVerificationEmail } from "@/lib/auth";

export function VerifyEmailPanel() {
  const searchParams = useSearchParams();
  const email = searchParams.get("email") ?? "you@example.com";
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function handleResend() {
    setError("");
    setMessage("");
    setIsSubmitting(true);

    try {
      const response = await resendVerificationEmail();
      setMessage(response.message);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to resend verification email.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex w-full max-w-[360px] flex-col gap-6">
      <AuthIntro
        icon={<MailCheck className="size-6" aria-hidden="true" />}
        title="Verify your email"
        description="We sent a verification link to"
      >
        <p className="text-sm font-medium leading-5">{email}</p>
      </AuthIntro>
      <p className="text-center text-sm leading-5 text-muted-foreground">
        Open the link in your inbox to verify your email and continue to choose your path.
      </p>
      <AuthError message={error} />
      {message ? <p className="text-center text-sm text-muted-foreground">{message}</p> : null}
      <PrimaryButton className="h-10 w-full" disabled={isSubmitting} onClick={handleResend}>
        {isSubmitting ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
        Resend verification email
      </PrimaryButton>
      <div className="space-y-2 text-center text-sm leading-5 text-muted-foreground">
        <p>No email yet? Check your spam folder.</p>
        <p>
          Wrong email?{" "}
          <Link className="font-medium text-foreground" href="/register">
            Change email
          </Link>
        </p>
      </div>
      <Link className="text-center text-sm font-medium leading-5" href="/login">
        Back to sign in
      </Link>
    </div>
  );
}
