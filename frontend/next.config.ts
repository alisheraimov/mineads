import type { NextConfig } from "next";
import { PHASE_PRODUCTION_BUILD } from "next/constants";

const nextConfig = (phase: string): NextConfig => ({
  distDir: phase === PHASE_PRODUCTION_BUILD ? ".next-build" : ".next",
  reactStrictMode: true,
  watchOptions: {
    pollIntervalMs: 500,
  },
});

export default nextConfig;
