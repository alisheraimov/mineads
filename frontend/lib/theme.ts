export type ThemePreference = "light" | "dark" | "system";

export const THEME_COOKIE = "mineads_theme";

// Apply the theme before the page paints, including on a full reload.
export const THEME_INIT_SCRIPT = `(() => {
  let preference = 'system';
  try {
    const value = document.cookie.split('; ').find(row => row.startsWith('${THEME_COOKIE}='))?.split('=')[1];
    if (value === 'light' || value === 'dark') preference = value;
  } catch {}
  const dark = preference === 'dark' || (preference === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.classList.toggle('dark', dark);
  document.documentElement.dataset.themePreference = preference;
})();`;
