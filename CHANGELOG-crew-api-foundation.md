# THE GOAT — Crew API foundation (service-scoped SmartStaff access)

_Two layers of the Crew API foundation: the **SmartStaff PHP self-endpoints**
(service-scoped access, **no THE GOAT binary change** — `app.py` / `index.html`
untouched, deploys as a PHP push, not a DMG) **and** the **Supabase `auth-login`
Edge Function** (verify-then-mint, deployed to the existing website project). Both
tested end-to-end against `test.smartstaffsolutions.com` (EIN 5925 → userID 9734):
verify-then-mint, all service-scoped reads, the details write, availability
read/add/delete, and a minted Supabase session all work. The shared `cohort.php`
refactor is behaviour-preserving for THE GOAT._

**Update (19 Jun 2026):** added the Supabase `auth-login` Edge Function and its
`_shared` helpers, aligned to the website project's existing `crew` table; widened
the app audience to include `operations`; recorded the `venue_inductions` finding.

## Highlights

Lays the **server-side foundation for the crew app/PWA**: a way for an always-on
backend (the Crew API — Supabase Edge Functions) to read and write a single crew
member's **own** SmartStaff data, without holding any crew password or per-user
SmartStaff session. Implements the **verify-then-mint / service-account** model
from the crew-auth spec (v0.2.1):

- **`verify-credentials.php`** (new) — confirms an EIN + password against
  SmartStaff's own scheme, returns identity + cohort, **mints no session**. The
  password is used once, then discarded by the caller.
- **Dual-trust endpoints** — the crew self-endpoints (`my-shifts.php`,
  `my-inductions.php`, `my-details.php`, `update-my-details.php`) **and**
  SmartStaff's own calendar endpoints (`get-unavailabilities.php`,
  `add-event.php`, `delete-event.php`) each serve **two callers** from one code
  path: THE GOAT desktop crew view / SmartStaff UI (a session) and the Crew API
  (service secret + a backend-asserted userID).
- All gated by a single **service secret** (`X-Goat-Service-Key`), the trust
  anchor for the whole path.
- **`auth-login`** (new Supabase Edge Function) — the verify-then-mint front door:
  verifies via `verify-credentials.php`, provisions/refreshes the `crew` identity
  row, and mints a Supabase session, never holding the password.

That covers every capability the PWA needs — auth, see shifts, see inductions,
view/edit details, view/add/remove availability — proven on test. THE GOAT's
session path and the SmartStaff calendar UI are unchanged: every endpoint behaves
identically when a real session is present.

## Audience (updated 19 Jun 2026)

The crew app admits **`crew`, `leadership`, and `operations`**, all mapped to the
**same crew-self capability set** — your *own* shifts, availability, details. The
app is self-scoped, so cohort grants only app entry, never cross-user access;
admitting `operations` gives operations staff their own data through the PWA and
no more. **`admin`** (`usergroupID == 1`) stays out — the highest-privilege
accounts get no app session. This **widens the crew-auth spec's original
crew/leadership** (spec §3/§10); the spec should be updated to match.

## Guiding principle — verify once, never hold the password

Mirroring THE GOAT (holding each crew member's SmartStaff session + creds for
silent re-auth) would put every crew member's password into a public,
multi-tenant, always-on service — exactly what verify-then-mint exists to avoid.
The **service-account** model keeps that liability off the hosted layer entirely:
the only long-lived secret server-side is the service key, and the only
persistent client credential is a revocable Supabase JWT. `verify-credentials.php`
proves identity once and mints nothing on the SmartStaff side; `auth-login` mints
its own Supabase session and discards the password.

## Dual-trust — one endpoint, two callers

`goat_acting_user_id()` resolves the acting userID through two trust paths, in
order:

1. a logged-in SmartStaff session → `$_SESSION` userID (THE GOAT desktop crew
   view and the SmartStaff calendar UI, unchanged);
2. else a valid `X-Goat-Service-Key` → the `userID` the backend asserts (the Crew
   API, derived from a verified Supabase JWT).

Every read/write **self-scopes to that resolved userID**. This keeps the hard
rule from `BACKLOG-cohort-access` intact — *never trust a client-supplied
`userID` param* — because in path 2 the param is **backend-asserted behind the
secret**, and the app itself can only ever present its own JWT. Because both
callers funnel through one helper, no endpoint forks: one set of files, one
self-scoping path, no second copy to drift. The pattern is applied identically to
the crew self-endpoints and to SmartStaff's own `/ajax/calendar/` endpoints.

## SmartStaff (PHP)

### `cohort.php` — single-source refactor + access helpers
- **`goat_cohort_for_user($userID)`** extracted as the resolution authority;
  **`goat_user_cohort()` now delegates** to it with the session userID, so the
  rule lives in exactly one place (same anti-drift principle as the operations
  cohort work). Uses two PK lookups (usergroupID, then cohort) to preserve both
  the admin-via-`usergroupID` rule and the column-absent tolerance.
- New helpers: **`goat_service_key_ok()`** (constant-time compare vs
  `GOAT_SERVICE_KEY`), **`goat_hash_equals()`** (`hash_equals` on 5.6+, manual
  constant-time fallback for 5.4/5.5), **`goat_acting_user_id()`** (the dual-trust
  resolver).
- Tolerant include of the gitignored `goat-service-key.php` — absent file → the
  service path is simply disabled (`goat_service_key_ok()` returns false), no
  fatal, so the code is safe to deploy before the key lands. Resolves via
  `dirname(__FILE__)`, so it's found regardless of which directory includes
  `cohort.php`.

### `verify-credentials.php` (new)
- Service-secret gated. SELECT by `ein`; checks `sha1(password . salt) ===
  users.password` **and** `active == 1`, constant-time on the hash; returns
  `{user_id, ein, firstname, lastname, cohort}`. No SmartStaff session minted,
  nothing stored.

### `my-shifts.php`, `my-inductions.php` (edited)
- `include('cohort.php')`; the inline `$user->checkSession()` block replaced by
  `$userID = goat_acting_user_id();`. Self-scoping query unchanged — fully
  behaviour-preserving for the session path.

### `my-details.php` (new, read) / `update-my-details.php` (new, write)
- **Read** — SELECTs the nine editable contact fields (`mobile`, `phone`,
  `address`, `suburb`, `state`, `postcode`, `email`, `emergency_contact`,
  `emergency_phone`), self-scoped; never returns `salt`/`password`. Pre-fills the
  PWA's "My Details" form and doubles as the column-name verifier.
- **Write** — whitelist of those nine columns; only fields actually present in
  the request are written (partial saves touch only what was sent); privileged
  columns (`usergroupID`, `cohort`, `ein`, `active`, `salt`, `password`)
  deliberately excluded. Gated on the `mysql_query` result, **never
  `affected_rows`** (0 on a no-op save). Password change deferred (see Remaining).
- **Leading-zero fix** — contact fields are quoted as string literals explicitly
  (`mysql_real_escape_string()` + quotes) rather than via `$db->sc()`, which
  coerces all-digit values to numbers and **strips leading zeros** from phones and
  0-prefixed postcodes (NT `08xx`, ACT `02xx`). Confirmed live: `phone=0400000000`
  stored as `400000000` under `sc()`, correct under explicit quoting.

### `/ajax/calendar/` — `get-unavailabilities.php`, `add-event.php`, `delete-event.php` (edited)
The crew app's "modify availability" path. These are SmartStaff's **own** calendar
endpoints (not new crew files), so reusing them keeps a single write path — no
reverse-engineering the `calendars` INSERT, no divergent copy.
- Each: `include('../crew/cohort.php')` + the inline `$_SESSION` resolution
  replaced by `$userID = goat_acting_user_id();`. All three were confirmed
  self-scoped (resolve from `$_SESSION`, no target-userID param), so the swap is
  behaviour-preserving for the SmartStaff calendar UI.
- **`add-event.php` hardcodes `'type' => '1'`** in its INSERT (it ignores the
  `&type=` in the URL), so the service path **cannot forge a `type=2` shift row**
  through it — it can only ever create unavailability. `get-unavailabilities.php`
  already filters `type = 1`.
- **`delete-event.php`** is type-agnostic by design (it's the general calendar
  delete), so added an **optional `type` guard**: `if (isset($_GET['type'])) …
  AND type = N`. The crew app sends `type=1` to scope a delete to unavailability
  and never a shift; the existing calendar UI sends no `type` and is unchanged.
- Crew API contract (mirrors the existing Python helpers, not the JSON one):
  `get-unavailabilities` returns a JSON array `[{id, title, start, end}]` (map
  `title` → `reason`); `add-event` / `delete-event` are text/plain, **success
  unless the body starts with `ERROR`** (empty body = success).
- Minor: an *unauthenticated* direct hit now returns `goat_acting_user_id()`'s
  JSON 401 rather than the native `ERROR:` / `[]` — immaterial in practice, since
  logged-in users always resolve via the session path.

### PHP house rules (held)
PHP 5.x; `mysql_*` only; no `??`, no short `[]`; JSON body + `application/json` on
every path (the crew self-endpoints); `(int)`-cast int fields; self-scope by the
resolved userID. (The crew/calendar endpoints match the existing self-endpoints'
use of `http_response_code()` rather than the `send_status()` helper — flagged for
a future normalisation pass.)

## Crew API — Supabase Edge Functions (`auth-login`)

The always-on layer from spec §6, now built and **deployed/tested on the existing
"Gig Power Website" Supabase project** (`ihyvwhquycsxhmhulzmu`) — the PWA and the
website's crew section are one identity system, so there's no separate project.

### Identity table — reuse the existing `crew` table (no `crew_profiles`)
The website project already had a `crew` table designed for exactly this:
`ein` (text PK) · `smartstaff_user_id` (int8) · `first_name` · `last_name` ·
`display_name` · `cohort` · `last_verified_at` · `created_at`. So the planned
`crew_profiles` was **dropped** and `auth-login` aligns to `crew`. It is the
**single source of truth** the data functions read `smartstaff_user_id` / `cohort`
from, keyed by `ein`; no `auth.users` FK is needed — the `auth.users` link is the
synthetic-email convention below, so `smartstaff_user_id` is not cached in
`app_metadata` (only `ein` is), keeping one authority and no drift.

### `auth-login` (new Edge Function)
Verify-then-mint, server-side with the service role:
1. `POST {ein, password}` → `verify-credentials.php` (service key) via the
   `_shared/smartstaff.ts` helper. Password used once, never stored.
2. **Fail-closed cohort gate** — admits `crew`, `leadership`, `operations`;
   `admin` rejected with 403 `Not permitted` (see Audience).
3. Upserts the `crew` row by `ein` (refreshes `smartstaff_user_id`, `cohort`,
   `first_name` / `last_name`, `display_name = "Last, First"`, `last_verified_at`).
4. Idempotently ensures a Supabase Auth user — synthetic email `{ein}@<domain>`
   (default `crew.gigpower.invalid`, override `CREW_EMAIL_DOMAIN`; **no mail is
   ever sent**). `app_metadata.ein` is the durable claim the data functions and
   RLS key off. `createUser` is idempotent — the duplicate-email error on repeat
   logins is caught and ignored, so no reverse `auth.users` lookup is needed.
5. Mints a session without a password: `generateLink` (`magiclink` — generates the
   token but **sends no email**) → `verifyOtp` (`type: 'email'`; note `magiclink`
   is deprecated for `verifyOtp`). Returns `{access_token, refresh_token, user}`.
- **`verify_jwt = false`** for this one function (in `config.toml`) — it's the
  login endpoint, so it must be callable without a session; gated instead by the
  SmartStaff credential check inside.

### `_shared/`
- `smartstaff.ts` — `verifyCredentials(ein, password)` (POSTs to
  `verify-credentials.php` with `X-Goat-Service-Key`; returns `null` on PHP
  401/403, throws on other non-2xx); `ssGet(path, userId, params)` for the data
  functions (appends `userID`, sends the service key). Reads `SMARTSTAFF_BASE_URL`
  and `GOAT_SERVICE_KEY` from env.
- `cors.ts` — CORS headers (origin `*` for now; **tighten to the PWA origin before
  prod**).

### Secrets / deploy (website project)
- `GOAT_SERVICE_KEY` + `SMARTSTAFF_BASE_URL` set as Edge Function secrets. **The
  key must equal the SmartStaff server's `goat-service-key.php` byte-for-byte** —
  a mismatch surfaces as `auth-login` returning `Invalid credentials`, because the
  function maps PHP's 403 (bad key) to the same message as a 401 (bad password).
- `SUPABASE_URL` / `SUPABASE_SERVICE_ROLE_KEY` are injected automatically — don't
  set them (and Supabase blocks `SUPABASE_`-prefixed secret names anyway).
- **No migration** (existing `crew` table). Deploy:
  `supabase functions deploy auth-login`.
- Tested on TEST end-to-end: EIN 5925 → session minted, `crew` row stamped
  (`smartstaff_user_id` 9734, fresh `last_verified_at`), `5925@crew.gigpower.invalid`
  provisioned under Authentication → Users.

## App (`app.py`) / UI (`index.html`)
- **No change.** The service path needs no Flask route; the dual-trust means THE
  GOAT could adopt `my-details` later via the session path; and the unavailability
  Python helpers keep calling `/ajax/calendar/` unchanged. **No DMG rebuild
  required.** No `@app.route` decorators touched.

## Service secret
- Per environment, `openssl rand -hex 32`. Stored as `define('GOAT_SERVICE_KEY',
  …)` in gitignored `goat-service-key.php` in `/ajax/crew/` on the SmartStaff
  server, and as a Supabase Edge Function secret (`GOAT_SERVICE_KEY`, read via
  `Deno.env.get`) in the Crew API. **Different keys test vs prod; identical
  within an environment.** Sent header-only (`X-Goat-Service-Key`), never in URLs
  or logs; compared constant-time. Treat like the admin credentials — possession
  permits read/write as any crew member.

## Migration
- **No schema change.** SmartStaff side: add `goat-service-key.php` to
  `/ajax/crew/` per environment (gitignored, deployed by hand), and add the path
  to `.gitignore`. Supabase side: none — reuses the existing `crew` table.

## Deploy order
1. PHP, **test first**:
   - to `/ajax/crew/`: `cohort.php` (shared — feeds THE GOAT and the website),
     `verify-credentials.php`, the edited `my-shifts.php` / `my-inductions.php`,
     `my-details.php`, `update-my-details.php`;
   - to `/ajax/calendar/`: the edited `get-unavailabilities.php` / `add-event.php`
     / `delete-event.php` (they `include('../crew/cohort.php')`, so `cohort.php`
     must already be in `/ajax/crew/`).
2. `goat-service-key.php` with that environment's key, in `/ajax/crew/`.
3. **Regression-check THE GOAT after `cohort.php`** — whoami resolves
   admin/leadership/crew correctly and MY STATUS loads. The refactor is
   behaviour-preserving, but `cohort.php` gates everything, so verify it.
4. Supabase: set `GOAT_SERVICE_KEY` / `SMARTSTAFF_BASE_URL` secrets (key matching
   that environment's server), `supabase functions deploy auth-login`.
5. No DMG rebuild — PHP-only push on THE GOAT side.

## Related findings from this work
- **EIN ≠ userID, in the flesh** — EIN `5925` → internal id `9734`. The Crew API
  must forward `user_id` (from `verify-credentials`) to every read/write, **never
  the EIN**. (cf. the v3.4.10 EIN-vs-userID regression.)
- **The website project's `crew` table already existed** and matched the planned
  identity shape, keyed by EIN — so `auth-login` reuses it rather than creating a
  parallel `crew_profiles`.
- **`venue_inductions` is reference data, not a per-crew sync** — it's venue +
  registration `note` + portal `links` (jsonb) + `sort_order` + `published`, with
  no EIN or per-person status. So `me-inductions` reads induction *status* **live**
  from SmartStaff (`my-inductions.php`); the PWA layers `venue_inductions` on top
  for the "how to register" links on venues not yet completed.
- **`auth-login` `Invalid credentials` can mask a key mismatch** — the function
  collapses PHP's 401 (bad password) and 403 (bad service key) into one message;
  isolate by calling `verify-credentials.php` directly with the same key.
- **`$db->sc()` numeric coercion** — drops leading zeros from all-digit values
  (phones, NT/ACT postcodes). The legacy SmartStaff `/my-details` form handler
  very likely shares this latent bug; our endpoints quote explicitly and are
  correct regardless.
- **`add-event.php` hardcodes `type=1`** — a useful safety property: the
  unavailability add path structurally can't be turned into a shift-forging path,
  even with the service key.
- **MY STATUS "breakage" was version drift, not a defect** — the per-user read
  layer (`/api/me/*` → `my-shifts.php` / `my-inductions.php`) was sound; the
  running Flask instance had lagged the source tree, so the panels hit Flask's
  HTML 404 and threw `Unexpected token '<'`. Restart from source cleared it.
  `BACKLOG-cohort-access` is effectively closed.

## Remaining
- **Data-proxy Edge Functions** — `me-shifts`, `me-details`, `me-inductions`,
  `me-unavailability`: each validates the JWT, reads `smartstaff_user_id` from the
  `crew` row by `ein`, and calls the matching SmartStaff endpoint via `ssGet` (or
  the text/plain calendar endpoints). Then the **PWA client**.
- **RLS on the website tables** (`crew`, `documents`, `notices`, …) keyed on
  `app_metadata.ein`, before the PWA reads them directly with the user's session.
- **Update the crew-auth spec** (§3/§10) for the widened `operations` audience.
- **Password change** in `update-my-details.php` — needs `class.user.php`'s salt
  generation and the `salt` column width, so the new hash matches SmartStaff
  exactly and a crew member can't be locked out on next login.
- **Promote to prod** — the SmartStaff PHP (backward-compatible, test-then-prod)
  and a prod Supabase deploy with a prod `GOAT_SERVICE_KEY` / prod
  `SMARTSTAFF_BASE_URL` and CORS tightened to the PWA origin.
- **`verify-credentials` throttling** — credential-stuffing protection, better
  placed at the Crew API `/auth/login` rate-limit than in PHP, since the endpoint
  already sits behind the service secret.
