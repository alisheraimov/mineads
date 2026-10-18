"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { FormEvent, useState } from "react";
import { Loader2 } from "lucide-react";

import { AuthDivider, AuthError, AuthField, AuthForm, AuthIntro, AuthSubmit, GoogleMark } from "@/components/auth/auth-shell";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PrimaryButton } from "@/components/ui/primary-button";
import { startRouteProgress } from "@/components/ui/route-progress";
import { getCurrentUser, googleRedirectUrl, login, setToken } from "@/lib/auth";

export function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState(searchParams.get("error") === "suspended" ? "This account has been suspended." : "");
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setIsSubmitting(true);

    try {
      const response = await login({ email, password });
      setToken(response.token);
      const currentUser = await getCurrentUser();
      startRouteProgress();

      if (currentUser.data.status === "suspended") {
        router.push("/account-suspended");
      } else if (!currentUser.data.email_verified_at) {
        router.push(`/verify-email?email=${encodeURIComponent(currentUser.data.email)}`);
      } else if (!currentUser.data.advertiser_account && !currentUser.data.publisher_account) {
        router.push("/choose-path");
      } else {
        router.push(currentUser.data.role === "publisher" ? "/publisher" : "/");
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to log in.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex w-full max-w-[360px] flex-col gap-6">
      <AuthIntro title="Welcome back" description="Sign in to your MineAds account." />
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
            type="email"
            autoComplete="email"
            placeholder="you@example.com"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
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
          <Input
            id="password"
            type="password"
            autoComplete="current-password"
            placeholder="Enter your password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
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
