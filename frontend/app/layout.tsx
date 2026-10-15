import type { Metadata } from "next";
import "./globals.css";
import { Inter } from "next/font/google";
import { Suspense } from "react";
import { cn } from "@/lib/utils";
import { RouteProgress } from "@/components/ui/route-progress";

const inter = Inter({subsets:['latin'],variable:'--font-sans'});

export const metadata: Metadata = {
  title: "MineAds",
  description: "Minecraft server ads and monetization.",
  icons: {
    icon: { url: "/favicon.svg", type: "image/svg+xml", sizes: "any" },
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" className={cn("font-sans", inter.variable)}>
      <body>
        <Suspense fallback={null}>
          <RouteProgress />
        </Suspense>
        {children}
      </body>
    </html>
  );
}
