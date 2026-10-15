"use client";

import {
  createContext,
  ReactNode,
  useCallback,
  useContext,
  useEffect,
  useState,
} from "react";

import { THEME_COOKIE, ThemePreference } from "@/lib/theme";

const ThemeContext = createContext<{
  preference: ThemePreference;
  setPreference: (preference: ThemePreference) => void;
} | null>(null);

function applyTheme(preference: ThemePreference) {
  const dark =
    preference === "dark" ||
    (preference === "system" &&
      window.matchMedia("(prefers-color-scheme: dark)").matches);
  document.documentElement.classList.toggle("dark", dark);
  document.documentElement.dataset.themePreference = preference;
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [preference, updatePreference] = useState<ThemePreference>("system");

  useEffect(() => {
    let saved: ThemePreference = "system";
    try {
      const value = document.cookie
        .split("; ")
        .find((row) => row.startsWith(`${THEME_COOKIE}=`))
        ?.split("=")[1];
      if (value === "light" || value === "dark") saved = value;
    } catch {}
    updatePreference(saved);
    applyTheme(saved);

    const media = window.matchMedia("(prefers-color-scheme: dark)");
    const onSystemChange = () => {
      if (document.documentElement.dataset.themePreference === "system")
        applyTheme("system");
    };
    media.addEventListener("change", onSystemChange);
    return () => media.removeEventListener("change", onSystemChange);
  }, []);

  const setPreference = useCallback((next: ThemePreference) => {
    updatePreference(next);
    applyTheme(next);
    const maxAge = next === "system" ? 0 : 60 * 60 * 24 * 365;
    const secure = location.protocol === "https:" ? "; Secure" : "";
    try {
      document.cookie = `${THEME_COOKIE}=${next === "system" ? "" : next}; Path=/; Max-Age=${maxAge}; SameSite=Lax${secure}`;
    } catch {
      // The current page can still switch themes if cookies are unavailable.
    }
  }, []);

  return (
    <ThemeContext.Provider value={{ preference, setPreference }}>
      {children}
    </ThemeContext.Provider>
  );
}

export function useTheme() {
  const context = useContext(ThemeContext);
  if (!context) throw new Error("useTheme must be used within ThemeProvider");
  return context;
}
