# Admin console UI preferences

## Filters toolbar

Use the Finance → Payouts toolbar as the reference for future admin list filters (`components/admin/admin-finance-payouts.tsx`).

- Keep search, the primary account/entity filter, the shadcn date-range picker, and Export CSV in one compact row. Allow wrapping when space is limited.
- Search updates as the user types with a short debounce; do not add a Search button.
- Place the date-range picker directly beside the account/entity filter. Do not put a lone date filter behind a Filters button.
- Use the existing searchable shadcn combobox for account/entity selection and the shared shadcn date-range picker instead of separate From/Through inputs.
- Align Export CSV at the right end of the toolbar.
- Show applied filters below as individually removable chips. Show Clear filters only when filters are applied.
- Preserve other filters when changing one, reset pagination when filters change, and apply the same filters to CSV exports.
- Payout date ranges use payout creation date in UTC. Do not offer a Created/Paid date-field selector on the payouts list.

Keep status tabs and their compact selected-status total below the toolbar, followed by the table. Avoid restoring separate status summary cards or explanatory paragraphs above the payouts table; put supporting explanations in tooltips or details.
