"use client";

import NextTopLoader from "nextjs-toploader";

export function RouteProgress() {
  return (
    <NextTopLoader
      color="var(--primary)"
      height={4}
      showSpinner={false}
      showForHashAnchor={false}
      shadow="0 0 10px var(--primary)"
      zIndex={2147483647}
    />
  );
}
