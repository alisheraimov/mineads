"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { FormEvent, ReactNode, useEffect, useState } from "react";
import { Loader2 } from "lucide-react";

import { AccountHeader } from "@/components/account/account-header";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { startRouteProgress } from "@/components/ui/route-progress";
import {
  ApiError,
  User,
  clearToken,
  forgotPassword,
  getCurrentUser,
  logout,
  resendVerificationEmail,
  updatePassword,
  updateProfile,
} from "@/lib/auth";

type Feedback = {
  error?: string;
  success?: string;
  fields?: Record<string, string[]>;
};

export function AccountPanel() {
  const router = useRouter();
  const [user, setUser] = useState<User | null>(null);
  const [name, setName] = useState("");
  const [loadError, setLoadError] = useState("");
  const [isLoading, setIsLoading] = useState(true);
  const [retry, setRetry] = useState(0);
  const [profileFeedback, setProfileFeedback] = useState<Feedback>({});
  const [securityFeedback, setSecurityFeedback] = useState<Feedback>({});
  const [emailFeedback, setEmailFeedback] = useState<Feedback>({});
  const [savingProfile, setSavingProfile] = useState(false);
  const [savingPassword, setSavingPassword] = useState(false);
  const [sendingEmail, setSendingEmail] = useState(false);
  const [currentPassword, setCurrentPassword] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");

  useEffect(() => {
    let mounted = true;
    setIsLoading(true);
    setLoadError("");
    getCurrentUser()
      .then(({ data }) => {
        if (mounted) {
          setUser(data);
          setName(data.name);
        }
      })
      .catch((error) => {
        if (!mounted) return;
        if (error instanceof ApiError && error.status === 401) {
          clearToken();
          router.replace("/login");
        } else if (error instanceof ApiError && error.status === 403) {
          router.replace("/account-suspended");
        } else {
          setLoadError(
            error instanceof Error
              ? error.message
              : "Unable to load account settings.",
          );
        }
      })
      .finally(() => {
        if (mounted) setIsLoading(false);
      });
    return () => {
      mounted = false;
    };
  }, [retry, router]);

  function failure(error: unknown): Feedback {
    if (error instanceof ApiError && error.status === 401) {
      clearToken();
      router.replace("/login");
    } else if (error instanceof ApiError && error.status === 403) {
      router.replace("/account-suspended");
    }
    return {
      error:
        error instanceof Error
          ? error.message
          : "Something went wrong. Please try again.",
      fields: error instanceof ApiError ? error.errors : {},
    };
  }

  async function handleLogout() {
    await logout().catch(() => clearToken());
    startRouteProgress();
    router.push("/login");
  }

  async function saveProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (savingProfile) return;
    setSavingProfile(true);
    setProfileFeedback({});
    try {
      const { data } = await updateProfile(name.trim());
      setUser(data);
      setName(data.name);
      setProfileFeedback({ success: "Profile saved." });
    } catch (error) {
      setProfileFeedback(failure(error));
    } finally {
      setSavingProfile(false);
    }
  }

  async function savePassword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (savingPassword) return;
    setSecurityFeedback({});
    if (password !== confirmation) {
      setSecurityFeedback({
        fields: { password_confirmation: ["Passwords do not match."] },
      });
      return;
    }
    setSavingPassword(true);
    try {
      const response = await updatePassword({
        current_password: currentPassword,
        password,
        password_confirmation: confirmation,
      });
      setCurrentPassword("");
      setPassword("");
      setConfirmation("");
      setSecurityFeedback({ success: response.message });
    } catch (error) {
      setSecurityFeedback(failure(error));
    } finally {
      setSavingPassword(false);
    }
  }

  async function sendEmail(kind: "verification" | "password") {
    if (!user || sendingEmail) return;
    setSendingEmail(true);
    const setFeedback =
      kind === "verification" ? setEmailFeedback : setSecurityFeedback;
    setFeedback({});
    try {
      const response =
        kind === "verification"
          ? await resendVerificationEmail()
          : await forgotPassword(user.email);
      setFeedback({ success: response.message });
    } catch (error) {
      setFeedback(failure(error));
    } finally {
      setSendingEmail(false);
    }
  }

  if (isLoading || (!user && !loadError)) {
    return (
      <main className="grid min-h-screen place-items-center">
        <Loader2
          className="size-5 animate-spin"
          aria-label="Loading account settings"
        />
      </main>
    );
  }

  if (!user) {
    return (
      <main className="grid min-h-screen place-items-center p-6">
        <div className="max-w-md space-y-4 text-center">
          <p role="alert" className="text-sm text-muted-foreground">
            {loadError}
          </p>
          <Button onClick={() => setRetry((value) => value + 1)}>
            Try again
          </Button>
          <Button asChild variant="outline" className="ml-2">
            <Link href="/login">Log in</Link>
          </Button>
        </div>
      </main>
    );
  }

  return (
    <div className="min-h-screen bg-canvas text-foreground">
      <AccountHeader user={user} onLogout={handleLogout} />
      <main className="mx-auto flex w-full max-w-[1192px] flex-col gap-8 px-6 py-6 lg:p-10">
        <div className="space-y-2">
          <h1 className="text-2xl font-semibold leading-8">Account settings</h1>
          <p className="text-sm leading-5 text-muted-foreground">
            Manage your profile and account security.
          </p>
        </div>

        <SettingsSection
          title="Profile"
          description="Your personal details and the email you use to sign in."
        >
          <form onSubmit={saveProfile} className="space-y-6">
            <div className="space-y-2">
              <Label htmlFor="display-name">Display name</Label>
              <Input
                id="display-name"
                name="name"
                autoComplete="name"
                value={name}
                onChange={(event) => {
                  setName(event.target.value);
                  setProfileFeedback({});
                }}
                maxLength={255}
                required
                disabled={savingProfile}
                aria-invalid={!!profileFeedback.fields?.name}
                aria-describedby={
                  profileFeedback.fields?.name ? "name-error" : undefined
                }
              />
              <FieldError
                id="name-error"
                messages={profileFeedback.fields?.name}
              />
            </div>
            <div className="space-y-2">
              <p className="text-sm font-medium leading-5">Email address</p>
              <div className="flex flex-wrap items-center gap-3 text-sm leading-5">
                <span className="break-all">{user.email}</span>
                <span
                  className={
                    user.email_verified_at
                      ? "rounded-md border border-emerald-500/20 bg-emerald-500/10 px-1.5 py-1 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                      : "rounded-md border border-amber-500/20 bg-amber-500/10 px-1.5 py-1 text-xs font-medium text-amber-700 dark:text-amber-400"
                  }
                >
                  {user.email_verified_at ? "Verified" : "Unverified"}
                </span>
              </div>
              <p className="text-sm leading-5 text-muted-foreground">
                Your email is used for sign-in and account messages.
              </p>
              {!user.email_verified_at && (
                <Button
                  type="button"
                  variant="outline"
                  className="h-9"
                  disabled={sendingEmail}
                  onClick={() => sendEmail("verification")}
                >
                  {sendingEmail && (
                    <Loader2 className="animate-spin" aria-hidden="true" />
                  )}
                  Resend verification email
                </Button>
              )}
              <FeedbackMessage feedback={emailFeedback} />
            </div>
            <FeedbackMessage feedback={profileFeedback} />
            <SaveRow description="Your name appears throughout MineAds.">
              <Button
                type="submit"
                className="h-9 w-full px-4 sm:w-auto"
                disabled={
                  savingProfile || !name.trim() || name.trim() === user.name
                }
              >
                {savingProfile && (
                  <Loader2 className="animate-spin" aria-hidden="true" />
                )}
                Save profile
              </Button>
            </SaveRow>
          </form>
        </SettingsSection>

        <SettingsSection
          title="Security"
          description="Keep your account protected with a strong password."
        >
          {user.google_connected ? (
            <div className="space-y-6">
              <div className="space-y-2">
                <p className="text-sm font-medium">Google sign-in connected</p>
                <p className="text-sm leading-5 text-muted-foreground">
                  To set or reset your MineAds password, request a secure link
                  at {user.email}. You can continue signing in with Google.
                </p>
              </div>
              <FeedbackMessage feedback={securityFeedback} />
              <SaveRow description="Resetting your password signs out all sessions.">
                <Button
                  className="h-9 w-full px-4 sm:w-auto"
                  disabled={sendingEmail}
                  onClick={() => sendEmail("password")}
                >
                  {sendingEmail && (
                    <Loader2 className="animate-spin" aria-hidden="true" />
                  )}
                  Send password reset email
                </Button>
              </SaveRow>
            </div>
          ) : (
            <form onSubmit={savePassword} className="space-y-6">
              <PasswordField
                id="current-password"
                label="Current password"
                placeholder="Enter current password"
                value={currentPassword}
                onChange={setCurrentPassword}
                autoComplete="current-password"
                errors={securityFeedback.fields?.current_password}
                disabled={savingPassword}
              />
              <div className="grid gap-4 sm:grid-cols-2">
                <PasswordField
                  id="new-password"
                  label="New password"
                  placeholder="Enter new password"
                  value={password}
                  onChange={setPassword}
                  autoComplete="new-password"
                  errors={securityFeedback.fields?.password}
                  disabled={savingPassword}
                />
                <PasswordField
                  id="confirm-password"
                  label="Confirm new password"
                  placeholder="Re-enter new password"
                  value={confirmation}
                  onChange={setConfirmation}
                  autoComplete="new-password"
                  errors={securityFeedback.fields?.password_confirmation}
                  disabled={savingPassword}
                />
              </div>
              <p className="text-sm leading-5 text-muted-foreground">
                Use at least 8 characters. Make it different from your current
                password.
              </p>
              <FeedbackMessage feedback={securityFeedback} />
              <SaveRow description="Other sessions will be signed out.">
                <Button
                  type="submit"
                  className="h-9 w-full px-4 sm:w-auto"
                  disabled={savingPassword}
                >
                  {savingPassword && (
                    <Loader2 className="animate-spin" aria-hidden="true" />
                  )}
                  Update password
                </Button>
              </SaveRow>
            </form>
          )}
        </SettingsSection>
      </main>
    </div>
  );
}

function SettingsSection({
  title,
  description,
  children,
}: {
  title: string;
  description: string;
  children: ReactNode;
}) {
  return (
    <section
      aria-labelledby={`settings-${title.toLowerCase()}`}
      className="grid items-start gap-4 lg:grid-cols-[216px_minmax(0,1fr)] lg:gap-8"
    >
      <div className="space-y-2">
        <h2
          id={`settings-${title.toLowerCase()}`}
          className="text-base font-semibold leading-6"
        >
          {title}
        </h2>
        <p className="text-sm leading-5 text-muted-foreground">{description}</p>
      </div>
      <div className="min-w-0 rounded-lg border border-border bg-card p-6">
        {children}
      </div>
    </section>
  );
}

function SaveRow({
  description,
  children,
}: {
  description: string;
  children: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <p className="text-sm leading-5 text-muted-foreground">{description}</p>
      {children}
    </div>
  );
}

function FeedbackMessage({ feedback }: { feedback: Feedback }) {
  if (feedback.error)
    return (
      <p role="alert" className="text-sm text-destructive">
        {feedback.error}
      </p>
    );
  if (feedback.success)
    return (
      <p role="status" className="text-sm text-emerald-700 dark:text-emerald-400">
        {feedback.success}
      </p>
    );
  return null;
}

function FieldError({ id, messages }: { id: string; messages?: string[] }) {
  return messages?.length ? (
    <p id={id} role="alert" className="text-sm text-destructive">
      {messages[0]}
    </p>
  ) : null;
}

function PasswordField({
  id,
  label,
  placeholder,
  value,
  onChange,
  autoComplete,
  errors,
  disabled,
}: {
  id: string;
  label: string;
  placeholder: string;
  value: string;
  onChange: (value: string) => void;
  autoComplete: "current-password" | "new-password";
  errors?: string[];
  disabled: boolean;
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        type="password"
        autoComplete={autoComplete}
        placeholder={placeholder}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        minLength={autoComplete === "new-password" ? 8 : undefined}
        required
        disabled={disabled}
        aria-invalid={!!errors?.length}
        aria-describedby={errors?.length ? `${id}-error` : undefined}
      />
      <FieldError id={`${id}-error`} messages={errors} />
    </div>
  );
}
