"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useRouter } from "@/lib/navigation";
import { FormEvent, useState } from "react";
import { Loader2 } from "lucide-react";

import { AuthDivider, AuthError, AuthField, AuthForm, AuthIntro, AuthSubmit, GoogleMark } from "@/components/auth/auth-shell";
import { PasswordInput } from "@/components/auth/password-input";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PrimaryButton } from "@/components/ui/primary-button";
import { adminHome, googleRedirectUrl, login, setToken } from "@/lib/auth";

export function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [error, setError] = useState(searchParams.get("error") === "suspended" ? "This account has been suspended." : "");
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setError("");
    setIsSubmitting(true);

    try {
      const { token, user } = await login({
        email: String(form.get("email") ?? ""),
        password: String(form.get("password") ?? ""),
      });
      setToken(token);

      if (user.status === "suspended") {
        router.push("/account-suspended");
      } else if (!user.email_verified_at) {
        router.push(`/verify-email?email=${encodeURIComponent(user.email)}`);
      } else if (user.role === "admin") {
        router.push(adminHome(user));
      } else if (!user.advertiser_account && !user.publisher_account) {
        router.push("/choose-path");
      } else {
        router.push(user.role === "publisher" ? "/publisher" : "/");
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to log in.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex w-full max-w-[360px] flex-col gap-6">
      <AuthIntro title="Welcome back" description="Sign in to your VoxelAds account." />
      <Button asChild variant="outline" className="h-10 w-full">
        <a href={googleRedirectUrl()}>
          <GoogleMark />
          Continue with Google
        </a>
      </Button>
      <AuthDivider />
      <AuthForm onSubmit={handleSubmit}>
        <AuthField>
          <Label htmlFor="email">Email</Label>
          <Input
            id="email"
            name="email"
            type="email"
            autoComplete="username"
            placeholder="you@example.com"
            required
          />
        </AuthField>
        <AuthField>
          <div className="flex items-center justify-between">
            <Label htmlFor="password">Password</Label>
            <Link className="text-xs leading-4" href="/forgot-password">
              Forgot password?
            </Link>
          </div>
          <PasswordInput
            id="password"
            name="password"
            autoComplete="current-password"
            placeholder="Enter your password"
            required
          />
        </AuthField>
        <AuthError message={error} />
        <AuthSubmit>
          <PrimaryButton className="h-10 w-full" disabled={isSubmitting} type="submit">
            {isSubmitting ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
            Sign in
          </PrimaryButton>
        </AuthSubmit>
      </AuthForm>

      <p className="text-center text-sm leading-5 text-muted-foreground">
        Don&apos;t have an account?{" "}
        <Link className="font-medium text-foreground" href="/register">
          Sign up
        </Link>
      </p>
    </div>
  );
}
