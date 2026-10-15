"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { FormEvent, useState } from "react";
import { Loader2 } from "lucide-react";

import { AuthError, AuthField, AuthForm, AuthIntro, AuthSubmit } from "@/components/auth/auth-shell";
import { PrimaryButton } from "@/components/ui/primary-button";
import { startRouteProgress } from "@/components/ui/route-progress";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { forgotPassword } from "@/lib/auth";

export function ForgotPasswordForm() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [error, setError] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setIsSubmitting(true);

    try {
      await forgotPassword(email);
      startRouteProgress();
      router.push(`/reset-email-sent?email=${encodeURIComponent(email)}`);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to send reset link.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex w-full max-w-[360px] flex-col gap-6">
      <AuthIntro
        title="Forgot your password?"
        description="Enter your email and we'll send you a link to reset your password."
      />
      <AuthForm onSubmit={handleSubmit}>
        <AuthField>
          <Label htmlFor="email">Email</Label>
          <Input
            id="email"
            type="email"
            autoComplete="email"
            placeholder="you@example.com"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            required
          />
        </AuthField>
        <AuthError message={error} />
        <AuthSubmit>
          <PrimaryButton className="h-10 w-full" disabled={isSubmitting} type="submit">
            {isSubmitting ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
            Send reset link
          </PrimaryButton>
        </AuthSubmit>
      </AuthForm>
      <Link className="text-center text-sm font-medium leading-5" href="/login">
        Back to sign in
      </Link>
    </div>
  );
}
