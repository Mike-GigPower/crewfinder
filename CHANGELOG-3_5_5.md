# THE GOAT — v3.5.5

Continues the scrape-retirement work from 3.5.4. The Estimate Import matcher's
three SmartStaff list scrapes (customers, venues, contacts) are replaced by a
single bulk endpoint, and contact matching is now scoped to the matched
customer.

## Changed — Estimate Import lookups read JSON, no longer scrape

The import preview built its match dropdowns by scraping three paginated
SmartStaff list views in sequence — `/customers`, `/venues`, `/contacts` —
walking every page's edit links for `{id, name}`. That's three multi-page round
trips per preview and three more HTML parsers exposed to layout drift.

The preview now pulls all three lists from one new endpoint,
`import-lookups-bulk.php`, as structured JSON in a single request.

## New — customer-scoped contact matching

There is no `contacts` table in SmartStaff — contacts are `users` in the
'Contact' usergroup (usergroupID 4), linked to customers via `customer_map`.
The endpoint returns that map, so once the customer is matched the preview
narrows contact candidates to that customer's own contacts and prefers their
`default` contact, instead of fuzzy-matching the estimate's contact name against
every contact in the system. This cuts the "select manually" cases. With no map
or no customer match it degrades to the previous match-against-all behaviour.

## Bonus data now available (carried, not yet consumed)

The endpoint also returns columns that already exist on these tables but the
scrapes never captured — customer phone/email/address, and venue
postcode/suburb/state/`has_induction`. The venue geo fields set up the next
piece of work (retiring the hand-maintained `VENUE_POSTCODES` table); they ride
through the matcher untouched for now.

## How it works

- New `fetch_import_lookups()` calls `import-lookups-bulk.php` and returns the
  four lists with customer/venue/contact ids coerced to `str` (shape-identical
  to the scrapes), or an error so the caller can fall back.
- **Graceful degradation.** On any endpoint failure (or with the bulk path
  disabled) the preview falls back to `ss_get_customers` / `ss_get_venues` /
  `ss_get_contacts`. Those scrapers are retained as the fallback.
- **Feature flag.** `use_bulk_import_lookups` in `config.json` (default `true`);
  the master `use_bulk_endpoints` switch also gates it.

## Schema notes (verified against smartst_test)

- `venues.active` and `customers.active` are `int` (`= 1`); `users.active` is
  the varchar quirk (`= '1'`). Contacts come from `users WHERE usergroupID = 4`.
- 953 active venues (not the ~22 we'd assumed — that was only the induction
  venues, which carry `has_induction = 1`). Postcode is populated on ~67%,
  suburb on ~98%.

## Files

- `import-lookups-bulk.php` (new SmartStaff endpoint at `/ajax/crew/`) —
  admin-only; returns `{customers, venues, contacts, customer_map}`.
- `app.py` — `USE_BULK_IMPORT_LOOKUPS` flag + config override;
  `fetch_import_lookups`, `_customer_contacts`, `_scoped_contact_match` (new);
  `api_import_preview` rewired; `APP_VERSION` → 3.5.5.

## Deployment

`import-lookups-bulk.php` must be on **production** SmartStaff before the app
release. The fallback means a missed deploy degrades to scraping rather than
breaking, but deploy the endpoint first.

Sequence: deploy endpoint to prod → commit + push `app.py` + changelog → build
DMG → smoke-test → publish GitHub release (asset `TheGOAT.dmg`) → flip
`version.json` to 3.5.5 last.
