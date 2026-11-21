import { cn } from "@/lib/utils";

export function VoxelBlock({ className }: { className?: string }) {
  return <svg viewBox="0 0 48 48" fill="none" aria-hidden="true" className={cn("size-10", className)}>
    <path d="m24 4 20 10-20 10L4 14 24 4Z" fill="#8fc89a" />
    <path d="m4 14 20 10v21L4 35V14Z" fill="#738078" />
    <path d="m24 24 20-10v21L24 45V24Z" fill="#4b5b52" />
    <path d="m4 14 20 10v7l-6-3v-3l-6-3v4l-8-4v-8Z" fill="#4f9565" />
    <path d="m24 24 20-10v7l-6 3v4l-6 3v-4l-8 4v-7Z" fill="#36784f" />
    <path d="m12 10 6 3 6-3-6-3-6 3Zm14 6 6 3 6-3-6-3-6 3Z" fill="#b8ddae" />
    <path d="m9 29 5 2v4l-5-2v-4Zm22 4 5-3v4l-5 3v-4Z" fill="#a5aca5" fillOpacity=".45" />
  </svg>;
}

export function VoxelScene() {
  return <svg viewBox="0 0 400 175" fill="none" aria-hidden="true" className="mx-auto h-40 w-full max-w-sm">
    <ellipse cx="202" cy="156" rx="126" ry="12" fill="currentColor" opacity=".04" />
    <g stroke="#7c9685" strokeOpacity=".25" strokeDasharray="3 5">
      <path d="m73 80 92 32 144-35M201 39v68" />
    </g>
    <g transform="translate(65 64)">
      <path d="m0 24 62-31 63 31-63 32L0 24Z" fill="#a1cbb0" />
      <path d="m0 24 63 32v28L0 52V24Z" fill="#75857a" />
      <path d="m63 56 62-32v28L63 84V56Z" fill="#52675b" />
      <path d="m0 24 63 32v9L0 33v-9Z" fill="#5c9c70" />
      <path d="m63 56 62-32v9L63 65v-9Z" fill="#3c7e55" />
      <path d="m34 7 23-12 25 12-24 12L34 7Z" fill="#d5e0d8" />
      <path d="m34 7 24 12v25L34 32V7Z" fill="#9cac9f" />
      <path d="m58 19 24-12v25L58 44V19Z" fill="#62796b" />
      <path d="m39 17 12 6m-12 2 12 6" stroke="#e8eee9" strokeWidth="2" />
    </g>
    <g transform="translate(229 41)">
      <path d="m0 35 57-28 57 28-57 29L0 35Z" fill="#b9d5bd" />
      <path d="m0 35 57 29v29L0 64V35Z" fill="#829086" />
      <path d="m57 64 57-29v29L57 93V64Z" fill="#5a7061" />
      <path d="m0 35 57 29v9L0 44v-9Z" fill="#5c9c70" />
      <path d="m57 64 57-29v9L57 73v-9Z" fill="#3c7e55" />
      <path d="m32 6 22-11 23 11-23 12L32 6Z" fill="#d7e0da" />
      <path d="m32 6 22 12v38L32 44V6Z" fill="#9cac9f" />
      <path d="m54 18 23-12v38L54 56V18Z" fill="#62796b" />
      <path d="m37 16 11 6m-11 2 11 6m-11 2 11 6" stroke="#e8eee9" strokeWidth="2" />
      <path d="m61 29 7-4v5l-7 4v-5Z" fill="#a5e6a7" />
    </g>
    <g fill="#69a87b">
      <path d="M197 30h6v6h-6zM49 57h4v4h-4zM351 102h5v5h-5z" />
      <path d="M182 79h5v5h-5z" opacity=".5" />
    </g>
  </svg>;
}
