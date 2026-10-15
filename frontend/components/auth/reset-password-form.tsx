"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { FormEvent, useState } from "react";
import { Loader2, LockKeyhole } from "lucide-react";

import { AuthError, AuthField, AuthForm, AuthIntro, AuthSubmit } from "@/components/auth/auth-shell";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PrimaryButton } from "@/components/ui/primary-button";
import { startRouteProgress } from "@/components/ui/route-progress";
import { resetPassword } from "@/lib/auth";

export function ResetPasswordForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [error, setError] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setIsSubmitting(true);

    try {
      await resetPassword({
        token: searchParams.get("token") ?? "",
        email: searchParams.get("email") ?? "",
        password,
        password_confirmation: passwordConfirmation,
      });
      startRouteProgress();
      router.push("/password-reset-success");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to reset password.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex w-full max-w-[360px] flex-col gap-6">
      <AuthIntro
        icon={<LockKeyhole className="size-6" aria-hidden="true" />}
        title="Set a new password"
        description="Choose a new password for your MineAds account."
      />
      <AuthForm onSubmit={handleSubmit}>
        <AuthField>
          <Label htmlFor="password">New password</Label>
          <Input
            id="password"
            type="password"
            autoComplete="new-password"
            minLength={8}
            placeholder="Enter your new password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            required
          />
        </AuthField>
        <AuthField>
          <Label htmlFor="password_confirmation">Confirm new password</Label>
          <Input
            id="password_confirmation"
            type="password"
            autoComplete="new-password"
            minLength={8}
            placeholder="Re-enter your new password"
            value={passwordConfirmation}
            onChange={(event) => setPasswordConfirmation(event.target.value)}
            required
          />
        </AuthField>
        <AuthError message={error} />
        <AuthSubmit>
          <PrimaryButton className="h-10 w-full" disabled={isSubmitting} type="submit">
            {isSubmitting ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
            Save new password
          </PrimaryButton>
        </AuthSubmit>
      </AuthForm>
      <Link className="text-center text-sm font-medium leading-5" href="/login">
        Back to sign in
      </Link>
    </div>
  );
}
