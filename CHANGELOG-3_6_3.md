# THE GOAT — v3.6.3

_Release date: 2026-06-17_

A branding-and-polish release. THE GOAT gets its proper identity across every
surface it shows up on — the **app header**, the **login screen**, and the
**macOS dock/app icon** — all driven from one new piece of artwork. The header
wordmark is now crisp live text instead of a baked image (so it stays sharp at
any size), the **Ask THE GOAT** button moves down onto the tab row to free up the
nav, the login version stamp stops lying, and clicking the goat earns you a
little reward.

## Added — new branding across header, login, and dock

The new goat artwork lands in three forms: a square emblem in the app header, the
full vertical lockup on the login screen, and a proper `.icns` for the macOS app
and DMG volume icons. The header now pairs the square emblem with live HTML text —
**THE GOAT** in gold and **Gig Power Ops, Admin, Tasking** in white underneath —
so the wordmark is vector-sharp at nav size rather than an unreadable shrunken
image. The old "Gig Power Operations Platform" subtitle is retired everywhere
(header, login, `<title>`).

## Added — click-the-goat easter egg

Clicking the header emblem plays a goat bray and sends a stampede of goats
galloping across the screen, then cleans itself up. No new assets — it reuses the
existing `static/goat-bray.mp3` and emoji.

## Changed — Ask THE GOAT moved to the tab row

The **Ask THE GOAT** button now sits at the right-hand end of the tab bar
(Schedule / Crew Finder / Crew Utilization / …) instead of crowding the top nav.
Cohort visibility is unchanged — crew still don't see it.

## Fixed — login version stamp was hardcoded

The login telemetry corner read a stale, hardcoded "THE GOAT v2.1". It now renders
the real `APP_VERSION` passed from the login route, so it tracks the build and
can't drift again.

## How it works — the decisions worth remembering

**The header wordmark is text, not image, on purpose.** The first pass dropped the
whole horizontal lockup graphic into the nav, but at ~46px the baked subtitle was
mush. Going back to a square emblem + live `nav-goat-title` / `nav-goat-sub` text
keeps it razor-sharp at any DPI and lets the version stamp sit alongside it.

**The header emblem is cropped from the square art, centred on the head.** The
square icon is bilaterally symmetric (gold centre x == image centre), so an
equal-margin square crop keeps the head dead-centre; the crop is centred on the
head's own midpoint (horns are tall, so the geometric centre sat slightly high)
and tightened just enough to push the rounded icon bezel off-frame. At nav size
the bezel isn't visible anyway.

**The bray reuses the preloaded audio element.** `goatBleat()` plays the existing
`<audio id="goat-bray">` node (the same one the chat "meaning of life" egg uses)
via `currentTime = 0; play()` rather than constructing a new `Audio()` — no extra
fetch, and consistent with the existing pattern. The stampede is a fixed
full-screen overlay of `🐐` spans on a CSS `@keyframes goat-run`, removed after
4.5s.

**The login version is injected server-side.** Rather than a client fetch against
the auth-gated `/api/version`, the login route passes `version=APP_VERSION` into
the template — single source of truth, works pre-auth, nothing to drift.

## Code changes

- **`app.py`** — login route passes `version=APP_VERSION` to `login.html`;
  `APP_VERSION` → 3.6.3.
- **`templates/index.html`** — header brand is now `the-goat-emblem.png` + live
  `nav-goat-title` (gold) / `nav-goat-sub` (white, was muted grey); `<title>`
  updated; **Ask THE GOAT** button relocated from `.nav-right` into `.tab-bar`
  (right-aligned); emblem `onclick="goatStampede()"`; new `goatBleat()` /
  `goatStampede()` + `@keyframes goat-run`.
- **`templates/login.html`** — emblem + title + subtitle replaced by the
  `the-goat-login.png` lockup (rings dropped, glow kept); telemetry version now
  `v{{ version }}`.
- **`static/`** — new `the-goat-emblem.png` (header), `the-goat-login.png` (login);
  `the-goat-header.png` added but currently unused.
- **`goat.icns`** _(repo root)_ — regenerated from the new square artwork; feeds
  both the PyInstaller `--icon` and the DMG `--volicon`.

No backend, PHP, schema, or config changes. Templates + static assets + icon only.
