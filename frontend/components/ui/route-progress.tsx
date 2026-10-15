"use client";

import { usePathname, useSearchParams } from "next/navigation";
import { useEffect, useMemo, useRef, useState } from "react";

const MIN_VISIBLE_MS = 650;
const ROUTE_PROGRESS_EVENT = "mineads:route-progress-start";

export function startRouteProgress() {
  if (typeof window === "undefined") {
    return;
  }

  window.dispatchEvent(new Event(ROUTE_PROGRESS_EVENT));
}

export function RouteProgress() {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const [isVisible, setIsVisible] = useState(false);
  const [progress, setProgress] = useState(0);
  const routeKey = useMemo(() => `${pathname}?${searchParams.toString()}`, [pathname, searchParams]);
  const isVisibleRef = useRef(false);
  const barRef = useRef<HTMLDivElement>(null);
  const startedAt = useRef(0);
  const startedRoute = useRef("");
  const finishTimer = useRef<number | null>(null);
  const trickleTimer = useRef<number | null>(null);

  useEffect(() => {
    isVisibleRef.current = isVisible;
  }, [isVisible]);

  useEffect(() => {
    function clearTimers() {
      if (finishTimer.current) {
        window.clearTimeout(finishTimer.current);
      }

      if (trickleTimer.current) {
        window.clearInterval(trickleTimer.current);
      }
    }

    function start() {
      clearTimers();
      startedAt.current = Date.now();
      startedRoute.current = `${window.location.pathname}${window.location.search}`;
      isVisibleRef.current = true;
      if (barRef.current) {
        barRef.current.style.opacity = "1";
        barRef.current.style.width = "24%";
      }
      setIsVisible(true);
      setProgress(24);

      trickleTimer.current = window.setInterval(() => {
        setProgress((current) => {
          if (current >= 92) {
            return current;
          }

          return current + (current < 50 ? 9 : 4);
        });
      }, 180);
    }

    function shouldStartForLink(link: HTMLAnchorElement, event: MouseEvent | PointerEvent) {
      if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return false;
      }

      if (link.target && link.target !== "_self") {
        return false;
      }

      const url = new URL(link.href);
      const currentUrl = new URL(window.location.href);

      return url.origin === currentUrl.origin && url.href !== currentUrl.href;
    }

    function handlePointerDown(event: PointerEvent) {
      if (event.button !== 0) {
        return;
      }

      const link = (event.target as Element | null)?.closest("a[href]");

      if (link instanceof HTMLAnchorElement && shouldStartForLink(link, event)) {
        start();
      }
    }

    function handleClick(event: MouseEvent) {
      const link = (event.target as Element | null)?.closest("a[href]");

      if (link instanceof HTMLAnchorElement && shouldStartForLink(link, event) && !isVisibleRef.current) {
        start();
      }
    }

    function handlePopState() {
      start();
    }

    document.addEventListener("pointerdown", handlePointerDown, { capture: true });
    document.addEventListener("click", handleClick);
    window.addEventListener(ROUTE_PROGRESS_EVENT, start);
    window.addEventListener("popstate", handlePopState);

    return () => {
      clearTimers();
      document.removeEventListener("pointerdown", handlePointerDown, { capture: true });
      document.removeEventListener("click", handleClick);
      window.removeEventListener(ROUTE_PROGRESS_EVENT, start);
      window.removeEventListener("popstate", handlePopState);
    };
  }, []);

  useEffect(() => {
    if (!isVisible) {
      return;
    }

    if (routeKey === startedRoute.current) {
      return;
    }

    if (trickleTimer.current) {
      window.clearInterval(trickleTimer.current);
    }

    const elapsed = Date.now() - startedAt.current;
    const delay = Math.max(MIN_VISIBLE_MS - elapsed, 0);

    finishTimer.current = window.setTimeout(() => {
      if (barRef.current) {
        barRef.current.style.opacity = "1";
        barRef.current.style.width = "100%";
      }
      setProgress(100);
      finishTimer.current = window.setTimeout(() => {
        isVisibleRef.current = false;
        if (barRef.current) {
          barRef.current.style.opacity = "0";
          barRef.current.style.width = "0%";
        }
        setIsVisible(false);
        setProgress(0);
      }, 160);
    }, delay);
  }, [routeKey, isVisible]);

  return (
    <div
      aria-hidden="true"
      className="pointer-events-none fixed inset-x-0 top-0 z-[2147483647] h-1 overflow-hidden"
    >
      <div
        ref={barRef}
        className="h-full bg-primary shadow-[0_0_10px_var(--primary)] transition-[width,opacity] duration-200 ease-out"
        style={{
          opacity: isVisible ? 1 : 0,
          width: `${progress}%`,
        }}
      />
    </div>
  );
}
