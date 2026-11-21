"use client";

import Image from "next/image";
import { useEffect, useRef, useState } from "react";
import { Loader2, Upload } from "lucide-react";
import { Button } from "@/components/ui/button";
import { removeAvatar, uploadAvatar, type User } from "@/lib/auth";

export function ProfilePicture({ user, disabled, onSaved, onBusyChange, getError }: {
  user: User;
  disabled: boolean;
  onSaved: (user: User) => void;
  onBusyChange: (busy: boolean) => void;
  getError: (error: unknown) => string;
}) {
  const input = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  useEffect(() => {
    if (!file) { setPreview(""); return; }
    const url = URL.createObjectURL(file);
    setPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [file]);

  async function save(remove = false) {
    if (busy || disabled || (!remove && !file)) return;
    setBusy(true);
    onBusyChange(true);
    setError("");
    setSuccess("");
    try {
      const { data } = remove ? await removeAvatar() : await uploadAvatar(file!);
      onSaved(data);
      setFile(null);
      setSuccess(remove ? "Uploaded picture removed." : "Profile picture saved.");
    } catch (error) { setError(getError(error)); }
    finally { setBusy(false); onBusyChange(false); }
  }

  const src = preview || user.avatar_url;
  const initials = user.name.split(" ").filter(Boolean).map(part => part[0]).join("").slice(0, 2).toUpperCase();
  return <div className="space-y-3 border-b pb-6">
    <p className="text-sm font-medium">Profile picture</p>
    <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
      <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-full border bg-muted text-xl font-semibold" aria-label="Profile picture preview">
        {src ? <Image src={src} width={80} height={80} unoptimized alt="Profile picture" className="size-full object-cover" /> : <span aria-hidden="true">{initials}</span>}
      </div>
      <div className="min-w-0 space-y-2">
        <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" aria-label="Choose profile picture" disabled={busy || disabled} onChange={event => {
          const selected = event.target.files?.[0];
          event.target.value = "";
          if (!selected) return;
          setError(""); setSuccess("");
          if (!["image/jpeg", "image/png", "image/webp"].includes(selected.type)) { setError("Choose a JPG, PNG, or WebP image."); return; }
          if (selected.size > 2 * 1024 * 1024) { setError("Choose an image smaller than 2 MB."); return; }
          setFile(selected);
        }} />
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" disabled={busy || disabled} onClick={() => input.current?.click()}><Upload aria-hidden="true" />{src ? "Change picture" : "Upload picture"}</Button>
          {file ? <>
            <Button type="button" disabled={busy || disabled} onClick={() => void save()}>{busy && <Loader2 className="animate-spin" aria-hidden="true" />}Save picture</Button>
            <Button type="button" variant="ghost" disabled={busy || disabled} onClick={() => { setFile(null); setError(""); }}>Cancel</Button>
          </> : user.has_uploaded_avatar && <Button type="button" variant="ghost" disabled={busy || disabled} onClick={() => void save(true)}>{busy && <Loader2 className="animate-spin" aria-hidden="true" />}Remove upload</Button>}
        </div>
        <p className="text-xs leading-5 text-muted-foreground">JPG, PNG, or WebP · Up to 2 MB · Maximum 4096 × 4096 pixels</p>
        {file && <p className="break-all text-xs text-muted-foreground">{file.name} · Preview only until you save</p>}
        {!file && user.has_uploaded_avatar && user.google_connected && <p className="text-xs text-muted-foreground">Removing this upload restores your Google photo.</p>}
      </div>
    </div>
    {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
    {success && <p role="status" className="text-sm text-emerald-700 dark:text-emerald-400">{success}</p>}
  </div>;
}
