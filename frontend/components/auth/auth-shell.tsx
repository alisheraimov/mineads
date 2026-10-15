import Image from "next/image";
import Link from "next/link";
import { ThemeSwitch } from "@/components/ui/theme-switch";

export function AuthShell({ children }: { children: React.ReactNode }) {
  return (
    <main className="flex min-h-screen flex-col bg-background">
      <header className="flex h-[88px] items-center justify-between px-6 sm:px-8">
        <Link
          href="/"
          className="inline-flex items-center gap-3"
          aria-label="MineAds home"
        >
          <Image
            src="/mineads_logo.svg"
            alt=""
            width={29}
            height={30}
            priority
            className="dark:invert"
          />
          <span className="text-xl font-semibold leading-7 max-[379px]:hidden">MineAds</span>
        </Link>
        <div className="flex items-center gap-2">
          <ThemeSwitch />
          <Link href="/" className="rounded-md px-2.5 py-2 text-sm font-medium">
            Back to website
          </Link>
        </div>
      </header>
      <section className="flex min-h-[calc(100vh-184px)] flex-1 justify-center pt-[116px] px-6">
        {children}
      </section>
      <footer className="flex h-24 items-center justify-between px-6 text-xs text-muted-foreground sm:px-8">
        <p>© 2026 MineAds</p>
        <div className="flex gap-6">
          <Link href="/terms">Terms of service</Link>
          <Link href="/privacy">Privacy policy</Link>
        </div>
      </footer>
    </main>
  );
}

export function AuthIntro({
  icon,
  title,
  description,
  children,
}: {
  icon?: React.ReactNode;
  title: string;
  description: string;
  children?: React.ReactNode;
}) {
  return (
    <div className="flex w-full flex-col items-center gap-2 text-center">
      {icon ? (
        <div className="mb-1 flex size-12 items-center justify-center rounded-full bg-muted">
          {icon}
        </div>
      ) : null}
      <h1 className="w-full text-2xl font-semibold leading-8">{title}</h1>
      <p className="w-full text-sm leading-5 text-muted-foreground">
        {description}
      </p>
      {children}
    </div>
  );
}

export function AuthDivider() {
  return (
    <div className="flex w-full items-center gap-4">
      <div className="h-px flex-1 bg-border" />
      <span className="text-xs leading-4 text-muted-foreground">
        or continue with email
      </span>
      <div className="h-px flex-1 bg-border" />
    </div>
  );
}

export function AuthForm({
  children,
  onSubmit,
}: {
  children: React.ReactNode;
  onSubmit: React.FormEventHandler<HTMLFormElement>;
}) {
  return (
    <form className="flex flex-col gap-5" onSubmit={onSubmit}>
      {children}
    </form>
  );
}

export function AuthField({ children }: { children: React.ReactNode }) {
  return <div className="flex flex-col gap-2">{children}</div>;
}

export function AuthSubmit({ children }: { children: React.ReactNode }) {
  return <div className="pt-1">{children}</div>;
}

export function AuthError({ message }: { message: string }) {
  if (!message) {
    return null;
  }

  return (
    <p className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
      {message}
    </p>
  );
}

export function GoogleMark() {
  return (
    <Image
      src="/img/icons/google.png"
      alt=""
      aria-hidden="true"
      width={16}
      height={16}
    />
  );
}
