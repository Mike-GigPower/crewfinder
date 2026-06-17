# THE GOAT — v3.6.2

_Release date: 2026-06-17_

A person who needs to be both a **bookable resource** and a **THE GOAT admin** no
longer has to juggle two logins. `usergroupID` is a single column — admin needs
`1`, a bookable crew record needs `3` — so the two roles can't live on one row.
This release bridges them: the operator keeps one everyday identity (their
`usergroupID 3` crew record, cohort `operations`, fully bookable) and **steps up
to admin on demand** by authenticating their separate admin account, then drops
back with **Exit Admin**. Who's allowed to step up is a small EIN allow-list
managed inside THE GOAT.

## Added — admin step-up ("sudo")

A listed operations user sees a **🔑 Admin** button in the nav. Clicking it opens
a credential modal; on success THE GOAT authenticates a *second* SmartStaff
session as the supplied admin account, confirms it's a real `usergroupID == 1`
account, and swaps the session's held identity over to admin — the full operator
UI re-renders in place. **⤓ Exit Admin** restores the original operations session.
A **Remember on this device** option pre-fills the credentials next time.

## Added — in-GOAT admin-access management

Admins get a **⚙ Admin Access** panel (add by EIN / list with names / remove)
backed by a new `goat_elevators` allow-list. Adding someone also promotes them to
the `operations` cohort in the same action, so the allow-list and the base cohort
are set together and can't drift.

## Fixed — browser autofilling the saved EIN into search boxes

Crew log in with their EIN as username, so the browser would drop that saved
username into the first bare text field on the page — landing the EIN in the
Schedule / Crew Finder / Induction / Utilization search boxes. Each is now
`readonly` until focused, which the browser won't autofill, restoring normal
typing on click.

## How it works — the decisions worth remembering

**The allow-list is visibility only; the grant is always re-auth.** `whoami.php`
returns a `can_elevate` flag (is the caller's EIN in `goat_elevators`) purely to
decide whether to show the button. Elevation itself only completes when
`fetch_whoami()` on the freshly-authenticated account resolves to cohort `admin`
(`usergroupID == 1`). So the cohort field still can never confer admin on its own,
and being on the list grants nothing without a real admin account + password.

**Elevators are operations by policy.** `manage-elevators.php`'s `add` action also
runs `UPDATE users SET cohort = 'operations'`, so an elevator's base view is the
read-only operator UI and they step up for writes. `remove` drops the list entry
only — reverting someone to plain crew stays a deliberate, separate decision.

**Elevation is a transient session swap, never persisted.** The crew
session/identity/creds are stashed in `_pre_elevation[sid]`; the admin session
takes their place (with session-only creds so an expired elevated session re-auths
as admin). Exit restores the stash; if the crew session lapsed while elevated,
`get_ss_session()` re-auths it from the restored creds. Nothing is written to
`config.json`, so the impersonation account `_make_admin_ss` uses is untouched.

**Re-render via reload, not in-place.** `applyCohort` adds body classes and hides
tabs cumulatively without reverting, so elevate/exit finish with `location.reload()`
— the clean way to re-render the whole UI (and load the right data) for the new
cohort rather than untangling stale state.

**Remember-on-device is a conscious trade.** When ticked, the admin password is
held in that browser's `localStorage` in plain text — fine when the machine is the
trust boundary (internal tool), but it does soften the step-up's deliberate
re-auth on that device. Unticked remembers the username only.

**The manager proxies through the app.** The browser can't reach SmartStaff
directly, so `/api/elevators` (admin-gated) forwards list/add/remove to
`manage-elevators.php` over the server-side admin session.

## Code changes

- **SmartStaff DB** — new `goat_elevators` table (`ein` PK, `added_by`, `added_at`;
  MyISAM/latin1).
- **`smartstaff/whoami.php`** — returns `can_elevate` (caller's EIN in
  `goat_elevators`); tolerant of the table being absent (degrades to `false`).
- **`smartstaff/manage-elevators.php`** _(new)_ — admin-gated `list` / `add` /
  `remove`. `add` validates the EIN belongs to a `usergroupID 3` record, inserts,
  and sets that user's cohort to `operations`. `list` resolves a best-effort crew
  name per EIN.
- **`app.py`** — `_pre_elevation` stash; `POST /api/elevate` (second SS session →
  confirm cohort admin → swap identity → cache refresh); `POST /api/exit-admin`
  (restore stash); `GET/POST /api/elevators` (admin proxy to manage-elevators.php);
  `/api/whoami` now returns `can_elevate` + `elevated`; `logout` clears
  `_pre_elevation`; `APP_VERSION` → 3.6.2.
- **`templates/index.html`** — 🔑 Admin / ⤓ Exit Admin / ⚙ Admin Access nav buttons
  (toggled in `applyCohort` off `can_elevate` / `elevated` / admin); the elevate
  credential modal with **Remember on this device**; the Admin Access management
  modal wired to `/api/elevators`; `readonly`-until-focus on the five search inputs
  (`sched-search`, `calls-search-input`, `cf-name-filter`, `ind-name-search`,
  `fc-search`).

New SmartStaff table `goat_elevators`; one new PHP endpoint
(`manage-elevators.php`) and one changed (`whoami.php`). No config changes.
