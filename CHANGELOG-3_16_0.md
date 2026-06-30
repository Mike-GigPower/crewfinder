# THE GOAT — v3.16.0

Crew Hub push-offer trigger. App-logic only on the GOAT side — **no PHP
endpoint or database/schema changes**, so no SmartStaff test→prod deploy. It
does depend on the Crew Hub portal's `/api/push/offer` webhook (separate repo,
`Mike-GigPower/website`) and on the shared push secret being present at build
time (see **Secret handling**).

## Push a notification when crew are offered a call
- When ops offer a call to crew through THE GOAT, each offered crew member now
  gets a push notification on their phone via the Crew Hub portal — **alongside**
  the existing SmartStaff SMS during the transition, not instead of it.
- The notification engine (device subscriptions, VAPID keys, service worker,
  notification UI) lives **entirely in the Crew Hub portal**. GOAT holds only the
  **trigger**: it detects "an offer was created" and fires one fire-and-forget
  HTTP POST per crew member to `crew.gigpower.com/api/push/offer`.
- Why this lives in GOAT and nothing else does: GOAT never writes the
  `call_crew_map` row itself — it calls SmartStaff's native `add-call.php` and
  SmartStaff writes the row. The only place the "offer created" event exists is
  in GOAT's Python, right after that call returns.

## How it works
- New `gp_notify_offer(crew_id, call)` helper. It POSTs the crew member's
  SmartStaff `userID` (the portal resolves it to an EIN against its own `crew`
  table), the call name, and the call id, authenticated with an `X-Push-Secret`
  header.
- **Fire-and-forget and safe:** the helper swallows all exceptions and uses a
  4-second timeout. The offer (and any SMS) has already happened by the time it
  runs — a slow or unreachable portal must never block or fail the offer loop.
  If a crew member has never logged into Crew Hub they aren't in the portal's
  table and the push simply no-ops; harmless.
- **Deduped:** an in-memory `(crew_id, call_id)` map suppresses a repeat push for
  the same crew+call within a 15-minute window (`GP_PUSH_DEDUP_TTL = 900`). This
  absorbs the Add-then-Send-SMS double-fire on the same selection. The map is
  per-process and resets on restart (worst case after a restart: one possible
  duplicate push), and is bounded at 5,000 entries.
- **Two trigger points** — the single chokepoint for every offer path (manual
  **Add**, **Send SMS**, and the ASK THE GOAT confirmation card):
  - `api_goat_add_crew` — fires on a plain **Add** (`action == "addcrew"`) only.
    "Add & Confirm" (`confcrew`) jumps straight to confirmed (status 5) and is
    not an offer, so it does **not** push.
  - `api_goat_send_sms` — fires once per crew member after the SMS send succeeds.

## Secret handling
- The push secret is read from the environment or GOAT's config —
  `GP_PUSH_SECRET = os.environ.get("GP_PUSH_SECRET", "") or load_config().get("gp_push_secret", "")`
  — exactly like the Anthropic key, and **never hardcoded in source** (the
  `crewfinder` repo is public).
- New empty `"gp_push_secret"` placeholder added to `config.template.json`. The
  real value lives in `build_secrets.json` on the build machine (gitignored) and
  is composed into the bundle's `config.json` by `build_dmg.sh` — the same path
  the Anthropic key already takes.
- The value must equal the portal's `PUSH_WEBHOOK_SECRET` (Vercel env); any
  mismatch returns `401` and no push goes out. Because the helper is
  fire-and-forget, a **missing** secret fails silently — every push 401s with no
  visible error. Confirm `gp_push_secret` is in `build_secrets.json` before
  building the DMG.

## Phase 2 (pending — frontend only, no `app.py` change)
- The notification currently reads just **"New shift offer / <call name>"**.
  `gp_notify_offer` already forwards `booking_name`, `venue` and `start`, but the
  frontend only sends `call_name` today, so those arrive empty.
- Widening the offer calls in `index.html` (and the ASK THE GOAT card path) to
  also forward venue, booking name and a start time will fill the notification
  out to **"Booking — Call · Fri 3 Jul 6:00am · Venue"**. Pure frontend change.

## Code changes
- `app.py` — new `GP_PUSH_URL` / `GP_PUSH_SECRET` / `GP_PUSH_DEDUP_TTL` constants
  and the `_gp_offer_notified` dedup map; new `gp_notify_offer()` helper; trigger
  call added inside `api_goat_add_crew` (gated on `addcrew`) and inside
  `api_goat_send_sms` (after SMS success). (+51 / −3)
- `config.template.json` — added empty `"gp_push_secret"` placeholder. (+1)

## Not bumped yet (release-time)
- `APP_VERSION` in `app.py` is still `3.15.0`, and `version.json` is unchanged.
  Both are flipped at release time per the usual order (source pushed → DMG built
  and release asset confirmed → `version.json` flipped last), not in this commit.
