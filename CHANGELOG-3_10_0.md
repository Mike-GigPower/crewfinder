# CHANGELOG — v3.10.0 · Records Management (Venues · Customers · Contacts)

Everything new since the 3.9.0 DMG. This release **adds nine PHP endpoints**
(three each for Venues, Customers, Contacts), so PHP deploys to test → prod
**before** the build; `version.json` is flipped **last**.

The Administration screen gains three new record-management surfaces that mirror
the existing **Manage Crew** pattern — a browse list (Active/Inactive toggle,
status pills, name search) plus an edit modal — for Venues, Customers and
Contacts. Two correctness fixes ride along: the ASK-THE-GOAT induction counts,
and a browser-autofill leak into form fields.

## Manage Venues
Open any venue and edit it: name, address, suburb, state, postcode,
has-induction flag and active flag.

- Browse list backed by the new `list-venues.php` (returns **all** venues incl.
  inactive; the app filters by `?active=`). The list **replaces the old HTML
  scrape** of the SmartStaff venues page.
- Edit modal reads `get-venue.php`, saves through `update-venue.php`.
- `has_induction` and `active` written as INT (the venues quirk). Postcode is
  quoted so leading zeros survive.

## Manage Customers
Open any customer and edit it: customer name, phone, email, address, suburb,
state, postcode, active flag.

- Browse list backed by `list-customers.php`; edit reads `get-customer.php`,
  saves through `update-customer.php`. `active` written as INT.
- **Associated contacts table.** The customer edit modal shows a read-only table
  of every contact linked to that customer (via `customer_map`, any default),
  with the default contact badged **DEFAULT**. Each row shows the contact's
  **mobile and email** — mobile as a `tel:` link, email as a `mailto:` link —
  and is clickable to jump straight to that contact's editor (the row click and
  the phone/email links are separated with `stopPropagation`).

## Manage Contacts
Contacts are `users` in **usergroup 4** ('Contact'). This screen scopes strictly
to usergroup 4 — a genuine fix over SmartStaff's native `/contacts` page, which
also lists crew.

- Browse list backed by `list-contacts.php` (usergroup-4 only; shows each
  contact's default customer; searchable by **contact name or customer name**).
- Edit modal reads `get-contact.php`, saves through `update-contact.php`. Editable
  fields: username (validated unique across all users), first/last name, mobile,
  phone, email, status, notes, and a password reset (same `sha1($pw.$salt)`
  scheme as crew). `users.active` is VARCHAR `'1'`/`'0'` here, not the INT used by
  venues/customers.
- **Editable Customer picker.** The contact's customer link is editable from the
  contact side. Saving sets that customer as the contact's **default** in
  `customer_map` non-destructively: existing default flags are cleared, the chosen
  customer is upserted as default, and **no rows are deleted** (other associations
  survive as non-default). `customer_id = 0` clears the default. `default` is a
  reserved word — backticked in every query.

## ASK-THE-GOAT induction counts — fix
The `get_inductions` tool read the **raw cached** induction status (only ever
Complete/Incomplete), so the Expired and Expiring-Soon counts were structurally
**always 0**, and it capped at the first 100 crew. It now routes every row
through `_compute_induction_status()` — the same canonical helper the
`/api/inductions` page uses — and the 100-crew cap is removed. Expired and
Expiring-Soon now report correctly.

## Browser-autofill leak — fix
Chrome/WebKit was injecting the saved username into form fields (e.g. the search
boxes, and the **postcode** field in edit modals), risking a stray "admin" being
saved. `autocomplete="off"` alone is ignored for fields the browser guesses are a
username, so both the four list **search boxes** and the shared **`efInput`**
edit-field helper now use a `readonly`-until-focus guard: the field shows its
value, and focusing it to type clears `readonly` so editing is normal. This kills
the leak across every Manage screen at once.

## Administration tidy-up
The standalone **Add Venue / Add Customer / Add Contact** cards are folded into
their Manage screens (the `+ Add` button lives inside each Manage modal), so the
Records grid is now four consistent **Manage** cards — Crew, Customers, Venues,
Contacts — all call-based (no scrapes), all with the Active/Inactive toggle.

## Files
| File | Where | Change |
|---|---|---|
| `list-venues.php` | `/ajax/crew/` | **NEW** — venue browse list (all, incl. inactive) |
| `get-venue.php` | `/ajax/crew/` | **NEW** — one venue's fields |
| `update-venue.php` | `/ajax/crew/` | **NEW** — venue update (INT flags, quoted postcode) |
| `list-customers.php` | `/ajax/crew/` | **NEW** — customer browse list |
| `get-customer.php` | `/ajax/crew/` | **NEW** — customer fields + associated-contacts (incl. mobile/email) |
| `update-customer.php` | `/ajax/crew/` | **NEW** — customer update (INT active) |
| `list-contacts.php` | `/ajax/crew/` | **NEW** — contact browse list (usergroup 4 only) |
| `get-contact.php` | `/ajax/crew/` | **NEW** — one contact's fields + default customer |
| `update-contact.php` | `/ajax/crew/` | **NEW** — contact update + password + `customer_map` default upsert |
| `app.py` | repo root | records-management helpers + routes (venue/customer/contact list, GET, POST); venue-list swapped from scrape → `list-venues`; ASK-THE-GOAT induction-count fix; `APP_VERSION = 3.10.0` |
| `templates/index.html` | repo | Manage Venues / Customers / Contacts UI; customer picker; associated-contacts table with `tel:`/`mailto:` links; autofill `readonly`-on-focus on search boxes + `efInput`; Add-cards folded into Manage modals |
| `smartstaff/*.php` | repo | source copies of the nine endpoints above |
| `CHANGELOG-3_10_0.md`, `RELEASE-3_10_0.md` | repo | docs (this commit) |

All nine endpoints: admin-gated (`goat_user_cohort() !== 'admin'` → 403), include
`global.php` + `cohort.php`, output JSON, gate write success on `mysql_error()`
(not `affected_rows`), PHP5-safe (`mysql_*`, `array()`, no `??`, no short arrays).
