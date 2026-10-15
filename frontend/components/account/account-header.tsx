"use client";

import Image from "next/image";
import Link from "next/link";
import { Bell, LayoutDashboard, Loader2, LogOut, Settings } from "lucide-react";

import { Button } from "@/components/ui/button";
import { ThemeSwitch } from "@/components/ui/theme-switch";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import type { User } from "@/lib/auth";

export function AccountHeader({
  user,
  onLogout,
  showNotifications = false,
  isLoading = false,
}: {
  user: User | null;
  onLogout: () => void;
  showNotifications?: boolean;
  isLoading?: boolean;
}) {
  const initials = user?.name
    .split(" ")
    .filter(Boolean)
    .map((part) => part[0])
    .join("")
    .slice(0, 2)
    .toUpperCase();

  return (
    <header className="flex h-[72px] items-center justify-between border-b border-border bg-background px-6 lg:px-8">
      <Link
        href="/"
        aria-label="MineAds home"
        className="inline-flex items-center gap-3"
      >
        <Image
          src="/account-logo.svg"
          alt=""
          width={29}
          height={30}
          priority
          className="dark:invert"
        />
        <span className="text-xl font-semibold max-[379px]:hidden">
          MineAds
        </span>
      </Link>
      <div className="flex items-center gap-3 sm:gap-6">
        <ThemeSwitch />
        {user && showNotifications && (
          <Button variant="ghost" size="icon-sm" aria-label="Notifications">
            <Bell aria-hidden="true" />
          </Button>
        )}
        {isLoading ? (
          <Loader2
            className="size-5 animate-spin"
            aria-label="Loading account"
          />
        ) : user ? (
          <Popover>
            <PopoverTrigger asChild>
              <Button
                variant="ghost"
                className="h-auto gap-2 p-1"
                aria-label="Open account menu"
              >
                {user.avatar_url ? (
                  <Image
                    src={user.avatar_url}
                    alt=""
                    width={32}
                    height={32}
                    unoptimized
                    className="size-8 rounded-full object-cover"
                  />
                ) : (
                  <span className="grid size-8 place-items-center rounded-full bg-foreground text-xs font-semibold text-background">
                    {initials || "MA"}
                  </span>
                )}
                <span className="hidden max-w-40 truncate sm:inline">
                  {user.name}
                </span>
                <Image
                  src="/account-chevron-down.svg"
                  alt=""
                  width={16}
                  height={16}
                  className="hidden sm:block"
                />
              </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-64 gap-1 p-1">
              <p
                className="truncate border-b px-2 py-2 text-xs text-muted-foreground"
                title={user.email}
              >
                {user.email}
              </p>
              {user.role === "advertiser" && (
                <Button asChild variant="ghost" className="h-9 justify-start">
                  <Link href="/advertiser">
                    <LayoutDashboard aria-hidden="true" />
                    Advertiser account
                  </Link>
                </Button>
              )}
              <Button asChild variant="ghost" className="h-9 justify-start">
                <Link href="/account">
                  <Settings aria-hidden="true" />
                  Account settings
                </Link>
              </Button>
              <Button
                variant="ghost"
                className="h-9 justify-start"
                onClick={onLogout}
              >
                <LogOut aria-hidden="true" />
                Log out
              </Button>
            </PopoverContent>
          </Popover>
        ) : (
          <nav aria-label="Account" className="flex items-center gap-2">
            <Button asChild variant="ghost" className="h-9">
              <Link href="/login">Log in</Link>
            </Button>
            <Button asChild className="h-9">
              <Link href="/register">Get started</Link>
            </Button>
          </nav>
        )}
      </div>
    </header>
  );
}
