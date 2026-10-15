"use client";

import { useState } from "react";
import { format, isValid, parseISO } from "date-fns";
import { CalendarIcon } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Calendar } from "@/components/ui/calendar";
import { Label } from "@/components/ui/label";
import { TimePicker } from "@/components/ui/time-picker";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { cn } from "@/lib/utils";

type DatePickerProps = {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  min?: string;
  max?: string;
  disabled?: boolean;
};

function calendarDate(value?: string) {
  if (!value) return undefined;
  const date = parseISO(value);
  return isValid(date) ? date : undefined;
}

export function DatePicker({
  id,
  label,
  value,
  onChange,
  min,
  max,
  disabled,
}: DatePickerProps) {
  const [open, setOpen] = useState(false);
  const selected = calendarDate(value);
  const earliest = calendarDate(min);
  const latest = calendarDate(max);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          disabled={disabled}
          aria-label={label}
          aria-describedby={`${id}-value`}
          className={cn(
            "w-full min-w-0 justify-start font-normal",
            !selected && "text-muted-foreground",
          )}
        >
          <CalendarIcon className="size-4 shrink-0" />
          <span id={`${id}-value`} className="truncate">
            {selected ? format(selected, "d MMM yyyy") : "Pick a date"}
          </span>
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start">
        <Calendar
          mode="single"
          required
          selected={selected}
          defaultMonth={selected ?? earliest}
          autoFocus
          disabled={[
            ...(earliest ? [{ before: earliest }] : []),
            ...(latest ? [{ after: latest }] : []),
          ]}
          onSelect={(date) => {
            if (date) {
              onChange(format(date, "yyyy-MM-dd"));
              setOpen(false);
            }
          }}
        />
      </PopoverContent>
    </Popover>
  );
}

export function DateRangePicker({
  id,
  label,
  value,
  onChange,
}: {
  id: string;
  label: string;
  value: { from: string; to: string };
  onChange: (value: { from: string; to: string }) => void;
}) {
  const from = calendarDate(value.from);
  const to = calendarDate(value.to);
  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          aria-label={label}
          aria-describedby={`${id}-value`}
          className="w-full min-w-0 justify-start font-normal"
        >
          <CalendarIcon className="size-4 shrink-0" />
          <span id={`${id}-value`} className="truncate">
            {from
              ? `${format(from, "d MMM yyyy")}${to ? ` - ${format(to, "d MMM yyyy")}` : " - Select end date"}`
              : "Pick a date range"}
          </span>
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="max-h-[min(80vh,640px)] w-auto overflow-y-auto p-0"
        align="start"
      >
        <Calendar
          mode="range"
          selected={from ? { from, to } : undefined}
          defaultMonth={from}
          numberOfMonths={2}
          max={365}
          autoFocus
          onSelect={(range) =>
            onChange({
              from: range?.from ? format(range.from, "yyyy-MM-dd") : "",
              to: range?.to ? format(range.to, "yyyy-MM-dd") : "",
            })
          }
        />
      </PopoverContent>
    </Popover>
  );
}

export function DateTimePicker({
  id,
  label,
  value,
  onChange,
  min,
  max,
  disabled,
}: DatePickerProps) {
  const [date = "", time = ""] = value.split("T");
  return (
    <div className="flex flex-wrap gap-3">
      <div className="min-w-0 flex-1 basis-36 space-y-2">
        <Label htmlFor={id}>Date</Label>
        <DatePicker
          id={id}
          label={`${label} date`}
          value={date}
          min={min?.slice(0, 10)}
          max={max?.slice(0, 10)}
          disabled={disabled}
          onChange={(selected) => onChange(`${selected}T${time || "00:00"}`)}
        />
      </div>
      <div className="w-28 shrink-0 space-y-2">
        <Label htmlFor={`${id}-time`}>Time</Label>
        <TimePicker
          id={`${id}-time`}
          aria-label={`${label} time`}
          required
          disabled={disabled}
          className="h-8 w-full"
          value={time}
          min={date === min?.slice(0, 10) ? min.slice(11) : undefined}
          max={date === max?.slice(0, 10) ? max.slice(11) : undefined}
          onInput={(event) => onChange(`${date}T${event.currentTarget.value}`)}
          onChange={(event) => onChange(`${date}T${event.target.value}`)}
        />
      </div>
    </div>
  );
}
