import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  reactStrictMode: true,
  watchOptions: {
    pollIntervalMs: 500,
  },
};

export default nextConfig;
