"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { ApiError, clearToken } from "@/lib/auth";
import { changeCreative, rejectionReasons, type CreativeDetail } from "@/lib/admin-creatives";
import { UserSelect } from "./user-shared";

export type CreativeAction = "reject" | "return_to_review" | "delete";
export function CreativeActionDialog({ data, action, query, onClose, onSaved, onConflict }: {
  data: CreativeDetail; action: CreativeAction; query: string; onClose: () => void;
  onSaved: (data: CreativeDetail, message: string) => void; onConflict: () => void;
}) {
  const router = useRouter();
  const [reason, setReason] = useState("");
  const [explanation, setExplanation] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [conflict, setConflict] = useState(false);
  const deleting = action === "delete";
  const title = deleting ? "Delete creative" : action === "reject" ? "Reject creative" : "Return creative to review?";
  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError("");
    try {
      const result = await changeCreative(data.id, query, { version: data.version, action, reason: reason || null, explanation: explanation.trim() || null, confirmation, confirm_withdrawal: data.status === "approved" }, deleting);
      onSaved(result.data, result.message);
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) { clearToken(); router.replace("/login"); return; }
      if (error instanceof ApiError && error.status === 409) { setConflict(true); onConflict(); }
      setError(error instanceof Error ? error.message : "Unable to save this decision.");
    } finally { setBusy(false); }
  }
  return <Dialog open onOpenChange={open => { if (!open && !busy) onClose(); }}><DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-lg"><DialogHeader><DialogTitle>{title}</DialogTitle><DialogDescription>{deleting ? `Delete CR-${data.id} while retaining its content and history.` : action === "reject" ? `Reject CR-${data.id}. This prevents future serving, including if the creative is currently approved.` : `CR-${data.id} will return to Pending review. Future serving stops until it is approved again.`}</DialogDescription></DialogHeader>
    <form className="space-y-4" onSubmit={submit}>{action === "reject" && <><div className="space-y-2"><Label htmlFor="creative-rejection-reason">Reason *</Label><UserSelect id="creative-rejection-reason" placeholder="Select a reason" disabled={busy || conflict} label="Rejection reason" value={reason} onChange={setReason} options={rejectionReasons} className="w-full bg-background" /></div><div className="space-y-2"><Label htmlFor="creative-rejection-explanation">Explanation{reason === "other" ? " *" : " (optional)"}</Label><Textarea id="creative-rejection-explanation" value={explanation} onChange={event => setExplanation(event.target.value)} required={reason === "other"} maxLength={2000} disabled={busy || conflict} placeholder="Explain what needs to change…" className="min-h-24" /></div><p className="text-xs leading-5 text-muted-foreground">The advertiser will see the saved reason and explanation in their portal.</p></>}
      {deleting && <><div className="space-y-2 pt-2"><Label htmlFor="creative-delete-confirm">Type CR-{data.id} to confirm</Label><Input id="creative-delete-confirm" className="h-8 py-1 text-sm" value={confirmation} onChange={event => setConfirmation(event.target.value)} autoComplete="off" maxLength={40} disabled={busy || conflict} required /></div><p className="text-xs leading-5 text-muted-foreground">Future serving will stop. The creative, review decisions, ledger entries and reporting records remain available as read-only history. The creative cannot be restored in the MVP.</p></>}
      {action === "return_to_review" && <p className="text-sm text-muted-foreground">Existing impressions, charges and review history are retained.</p>}
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <DialogFooter><Button type="button" variant="outline" disabled={busy} onClick={onClose}>Cancel</Button><Button type="submit" variant={action === "return_to_review" ? "default" : "destructive"} disabled={busy || conflict || (deleting ? confirmation !== "CR-" + data.id : action === "reject" && (!reason || (reason === "other" && !explanation.trim())))}>{busy ? "Saving…" : deleting ? "Delete creative" : action === "reject" ? "Reject creative" : "Return to review"}</Button></DialogFooter>
    </form></DialogContent></Dialog>;
}
