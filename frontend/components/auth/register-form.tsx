"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { FormEvent, useState } from "react";
import { Loader2 } from "lucide-react";

import { AuthDivider, AuthError, AuthField, AuthForm, AuthIntro, AuthSubmit, GoogleMark } from "@/components/auth/auth-shell";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PrimaryButton } from "@/components/ui/primary-button";
import { startRouteProgress } from "@/components/ui/route-progress";
import { googleRedirectUrl, register, setToken } from "@/lib/auth";

export function RegisterForm() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setIsSubmitting(true);

    try {
      const response = await register({
        email,
        password,
        password_confirmation: password,
      });
      setToken(response.token);
      startRouteProgress();
      router.push(`/verify-email?email=${encodeURIComponent(response.user.email)}`);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to create account.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex w-full max-w-[360px] flex-col gap-6">
      <AuthIntro title="Create your account" description="Get started with MineAds." />
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
          <Label htmlFor="password">Password</Label>
          <Input
            id="password"
            type="password"
            autoComplete="new-password"
            minLength={8}
            placeholder="Create a password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            required
          />
        </AuthField>
        <AuthError message={error} />
        <AuthSubmit>
          <PrimaryButton className="h-10 w-full" disabled={isSubmitting} type="submit">
            {isSubmitting ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
            Create account
          </PrimaryButton>
        </AuthSubmit>
      </AuthForm>

      <p className="text-center text-sm leading-5 text-muted-foreground">
        Already have an account?{" "}
        <Link className="font-medium text-foreground" href="/login">
          Sign in
        </Link>
      </p>
    </div>
  );
}
