# THE GOAT — v3.5.1 Release Runbook

A single worklist to ship 3.5.1. Work top to bottom.

---

## 0. What's in this release

Crew Finder enhancements:
- Shift bars coloured by relationship to the requested call (green=Booked,
  grey=Waiting, red=Conflict/Declined, amber=Potential Conflict, blue=Info) +
  a colour **legend**.
- **BOOKED / WAITING / DECLINED** availability labels, sourced from the
  booked-crew list (name-matched), not from shift rows.
- Hover a **shift bar** → call details (booking, call, time, venue, state).
- Hover a **crew name** → card with profile **photo** + **notes**.
- **Ask the GOAT** bar: natural-language filter/sort over the on-screen crew.
- Config-driven **domain glossary** (VX/SX/LX → Video/Sound/Lighting).

## Files changed (review `git diff` before committing)

- `app.py` — `APP_VERSION` → 3.5.1; `_tag_shift_for_timeline`; `/api/crew-card`,
  `/api/crew-photo`, `/api/crew-finder/ask`; glossary (`GOAT_GLOSSARY_DEFAULT`,
  `goat_glossary()`, request-time prompt wrappers).
- `templates/index.html` — colours, BOOKED/WAITING/DECLINED, legend, hover
  tooltip + name card, Ask the GOAT bar.
- `smartstaff/list-crew-bulk.php` — adds `u.notes` (the ONLY PHP change).
- `config.template.json` — adds `"goat_glossary": {}` placeholder.
- `CHANGELOG-3_5_1.md` — new.
- `version.json` — bump at release (step 6).

---

## 1. Pre-flight

- [ ] On the build machine, working dir is `~/dev/gigpower/` (NOT `~/Desktop/...`
      — the iCloud path breaks codesign).
- [ ] `grep APP_VERSION app.py` shows **3.5.1**.
- [ ] Refresh project-knowledge files if you keep them in sync with the release.
- [ ] `python3 -m py_compile app.py` is clean.
- [ ] Confirm the `users` table really has a column named **`notes`** (the
      `list-crew-bulk.php` SELECT uses `u.notes`; a different name → query error).

## 2. Commit source FIRST

(The v3.3.0 lesson: never tag/release before source is pushed.)

- [ ] `git diff` review of the five changed files above.
- [ ] Commit and **push to `main`**.
- [ ] Confirm GitHub shows the new commit on `main` before going further.

## 3. Deploy the SmartStaff PHP

- [ ] Upload **`list-crew-bulk.php`** to `/ajax/crew/` on production.
      - Additive + zero-regression (admin/leadership read path unchanged).
      - Until it's live, name-hover **notes** show "No notes on file"; everything
        else works without it.
- [ ] No other PHP changed this release (`get-shifts-bulk.php` etc. already emit
      what the colours/tooltips need from 3.5.0).

## 4. Build, sign, notarise the DMG

- [ ] Ensure `build_secrets.json` exists locally (Anthropic key only).
- [ ] `GOAT_SIGN_ID` and `GOAT_NOTARY_PROFILE` set in `~/.zshrc`
      (Developer ID Application: Gig Power Pty ltd (96W2KAK46G); keychain
      profile `thegoat-notary`).
- [ ] Run `./build_dmg.sh` — composes the bundle `config.json` from
      `config.template.json` + `build_secrets.json`, signs, notarises, staples.
- [ ] Confirm notarytool succeeded and the ticket was **stapled**.
- [ ] Sanity: `find "dist/The GOAT.app" -name config.json` and `-name au_postcodes.json`
      both present in the bundle.

## 5. Publish the GitHub release

- [ ] Create release tag **`v3.5.1`** on `main`.
- [ ] Attach the built **`TheGOAT.dmg`**.

## 6. Update `version.json` on `main`

Push this (edit `release_notes` wording if you like):

```json
{
  "version": "3.5.1",
  "release_date": "2026-06-04",
  "dmg_url": "https://github.com/Mike-GigPower/crewfinder/releases/download/v3.5.1/TheGOAT.dmg",
  "release_notes": "Crew Finder: shift bars coloured by relationship to the requested call (green=booked, grey=waiting, red=conflict/declined, amber=potential conflict, blue=info) with a legend; BOOKED/WAITING/DECLINED availability; hover a shift bar for call details, hover a name for photo + notes; Ask the GOAT natural-language filter."
}
```

- [ ] `dmg_url` matches the actual asset URL from step 5.
- [ ] Confirm `https://raw.githubusercontent.com/Mike-GigPower/crewfinder/main/version.json`
      serves 3.5.1 (the running app reads this for auto-update).

## 7. Post-deploy verification (open the app, run a Crew Finder search)

- [ ] Legend visible above the results.
- [ ] A crew member in the **WAITING** chips shows `WAITING` + grey bar;
      **DECLINED** chips show `DECLINED` + red bar; a confirmed-on-call shows
      `BOOKED` + green bar.
- [ ] An AVAILABLE row with a non-conflicting confirmed shift shows that shift
      **blue** (not red) — the original inconsistency.
- [ ] Hover a shift bar → call details tooltip appears (not clipped).
- [ ] Hover a name → photo (or "No photo on file") + notes (or "No notes on
      file" until the PHP is deployed).
- [ ] Ask the GOAT: try "experience with ProStage", "lots of RLA experience",
      "VX experience", "sort by hours worked at Marvel" (last one filters by
      Marvel and notes the hours limitation).

---

## Glossary — where to add terms

The glossary maps call-description abbreviations to skill words so e.g.
"VX experience" matches the **Video** crew group.

- **Built-in defaults** (always present): `GOAT_GLOSSARY_DEFAULT` in `app.py`
  — currently `VX=Video`, `SX=Sound, Audio`, `LX=Lighting, Lights`.
- **Add/override at runtime**: a `goat_glossary` object in **`config.json`**.
  Read fresh on every request — effective on the next message, no rebuild/restart.

Two scenarios:

### A) Add a term to a running install right now (no rebuild)
Edit that machine's bundle config:
`…/The GOAT.app/Contents/MacOS/config.json`

```json
{
  "username": "",
  "password": "",
  "anthropic_api_key": "…",
  "goat_glossary": {
    "FX": "Special Effects, Pyro",
    "RX": "Rigging",
    "BX": "Backline"
  }
}
```

Save, then ask the GOAT again — it picks it up immediately. Note: this only
affects **that** install's config.

### B) Ship a term to everyone (baked into new installs)
Add it to **`config.template.json`** (the `goat_glossary` map), commit, and
rebuild the DMG (steps 2–6). New installs get it by default. For terms you
consider permanent core vocabulary, you can instead add them to
`GOAT_GLOSSARY_DEFAULT` in `app.py` — same effect, also requires a rebuild.

`config` entries override code defaults of the same key (e.g. put
`"SX": "Sound desk"` in config to change the default `"SX": "Sound, Audio"`).

---

## Known limitations / notes (not blockers)

- **"Sort by hours worked at <venue>"** can't truly sort by hours — work history
  isn't loaded in Crew Finder. It matches that venue's experience and says so.
  A real version needs a per-venue hours data pull (future enhancement).
- **Photo path** is `images/crewpics/crewimg_<id>.jpg` (confirmed). Non-`.jpg`
  headshots fall back to a profile-page scrape, so they still resolve.
- **BOOKED/DECLINED rows stay selectable** (they sit in the available bucket).
  Re-adding someone already booked/declined is possible; say the word if you
  want those checkboxes suppressed.
- Optional: if `public_html/images` is web-accessible without a session, the
  photo `<img>` could point straight at the file and drop the proxy. Quick to
  check later; works either way now.
