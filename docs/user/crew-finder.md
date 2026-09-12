---
title: Crew Finder
summary: Finding crew who can work a call — filters, the conflict rules, what blocks versus what warns, and how to book.
tags: cf, find crew, availability, conflicts, conflict rules, clash, radius, distance, licence, rating, timeline, focus, select all, warnings, fatigue, spot check, book crew, add and confirm, hide from results
order: 20
updated: 2026-09-12
---

# Crew Finder

Crew Finder answers one question: **who can work this call?**

It is **admin only**. Operations and Leadership do not see the tab.

## Picking the call

The left sidebar lists **Unfilled Calls** — every call in the next 90 days where booked crew is fewer than crew required. A call that is already full never appears here.

- **Click** a call to select it.
- **⌘-click or Shift-click** to add more calls to the selection.
- The search box takes a **booking name or a booking number** (with or without the `#`). It does not search call names.

Two things happen automatically when you select a call:

- **Calls it feeds are selected with it.** If this call feeds others, offering it offers them, so they come along. They show as "carried". Calls that feed *into* it are not dragged in.
- **Calls it merely suggests become "considered"** rather than selected. Crew are *ranked* against them but not searched for them — a clash on a considered call will not count against anyone.

The chips on a call card tell you this at a glance: `↓ feeds 2`, `↑ fed by 1`, `↓ suggests 3`. The booked count reads `3/5 booked`, or spells the arithmetic out when feeds reserve seats: `5 required · 2 reserved · 1 likely · 2 to fill`.

The search button tells you what it is about to do — **Select a call first**, **Search #4821**, or **Search 3 calls**. Changing the selection greys out the old results so you cannot mistake them for current ones.

## Filters

Everything lives behind **⚙ Filters**, which carries a count of how many are active. Each one you set also appears as a removable chip under the toolbar, with a **clear all** link.

| Filter | What it does |
|---|---|
| **Filter by group** | The live SmartStaff groups. Multiple groups means **all of them**, not any. |
| **Filter by licence** | Only appears once the licence catalogue loads, and only offers codes somebody actually holds. Multiple licences means all of them. Driver classes understand seniority — asking for Medium Rigid also returns Heavy Rigid, Heavy Combination and Multi Combination. |
| **Only show valid on the call date** | Checks the licence against **the call's date**, not today. Someone expired on some of your selected calls but not all of them stays in the list, with the badge saying which. |
| **Minimum rating** | 1 to 10. Opens at 1, so new starters appear by default. |
| **Hide from results** | Two boxes, both **ticked by default** — "Already booked on this call" and "Declined this call". |
| **Exclude public-transport-only crew** | Drops crew in the PT-only group. |
| **Filter by distance** | Within 5–100 km of either the **venue** or a **postcode** you type. |

Two more narrowing tools sit outside the dropdown, on the toolbar. **Filter by name…** narrows the results already on screen. **Ask the GOAT** takes plain English — "lots of RLA experience", "sort by hours at Marvel" — and filters or sorts what is displayed. Both clear themselves on every new search.

### Worth knowing about filters

- Changing any filter **except** the two Hide boxes re-runs the search automatically. The Hide boxes only re-filter what is already on screen, which is why they are instant.
- **The hide rule is not "answered the same way on every call".** Someone is hidden only when they have answered *every* selected call and every one of those answers is a box you ticked. Booked on the load-in and declined the load-out, with both boxes ticked, is hidden. Booked on the load-in but still free for the load-out **stays in the list** — they still have something to be offered.
- **With several calls selected, the distance filter measures from the first call's venue.** If your selection spans venues, the radius is from one of them only.
- If the venue is missing or unknown, the search stops and tells you to switch the origin to Postcode rather than quietly measuring from nowhere.

### Spot-check a person

Above the call list. Type a name, press **Check**, and THE GOAT assesses that one person against the selected call **ignoring your filters** — group, rating, licence, PT-only and distance exclusion are all skipped. Use it when someone rings up and asks, and you do not want to widen your filters to answer.

## Who is available, and why

**Only confirmed shifts are ever treated as conflicts.** A pending offer, a decline, a backup or a no-show somewhere else never blocks anyone and never warns — those draw an informational bar on the timeline and nothing more.

### Two things block

These are exactly what SmartStaff itself refuses at write time, so the Finder can never offer you someone the booking would reject. A blocked person lands in **CONFLICTS** with no checkbox.

- **Rule 1 — Overlap.** A confirmed shift overlaps the call.
- **Unavailability.** Leave or an unavailable period touching the call.

### Three things warn

These are policy positions you may legitimately override — a spot operator rolling into their own load-out, or a walk between two gates of the same stadium, are your call, not the system's. A warned person stays **available and bookable**, with an amber **⚠** and the reason on hover.

- **Rule 2 — Fatigue.** A shift of 8 hours or more with less than 6 hours clear of this call.
- **Rule 3 — Venue change.** A different venue with less than 2 hours between.
- **Rule 4 — 24-hour load.** More than 16 hours worked in any rolling 24 hours, counting this call.

> **⚠ Select All deliberately skips warned crew.** They must be ticked by hand, one at a time. The amber ⚠ is the only cue, so if your selected count comes back lower than the available count, that is why.

Results are bucketed as **AVAILABLE FOR ALL**, **PARTIAL — SOME CALLS**, **CONFLICTS**, **SKIPPED**, and **LOCATION UNKNOWN — NO POSTCODE**. Skipped means a filter removed them, and the reason is given: `Missing: Rigging`, `Rating 4 < 6`, `No Forklift on file`, `32 km away`, `PT-only (excluded)`.

## Reading a row

After the name you may see, in order:

- **Licence pills** — one per licence you asked for, showing the worst status across your selected calls: `Valid`, `Held`, `Expires soon`, `Expired`, `No expiry on file`. A red **Expired** pill does not block booking unless you ticked "Only show valid on the call date".
- **Induction badges** — `✓` current, `◈` expiring, `⊘` expired, `⚠` none. Only for venues that are induction-controlled and published. **None of these affect availability.** If the legend shows "Induction required" for the venue, a blank badge means no record at all; if there is no such legend line, the venue simply is not gated.
- **🔕** — no Crew Hub push subscription on file. Shown only when we positively know; it is not shown when the check has not run, so an absent 🔕 is not proof of anything.
- **Amber ⚠** — a Rule 2/3/4 warning. Hover for the full text.

## Views

**Timeline** is the default. Each crew member gets a horizontal strip showing their shifts from 24 hours before the earliest call to 24 hours after the latest, with the call you are filling marked as a green band. Colours: green booked, teal backup, grey waiting, red conflict, amber potential conflict, blue for information, purple unavailable. Scroll the timeline with a trackpad swipe or Shift-scroll.

**Table** swaps the timeline for a Detail column. Name, Status, Distance, Rating are all click-to-sort.

**⤢ Focus** hides the nav and tab bars to fit more crew on screen. Esc brings them back. The TEST strip is never hidden.

**Click a name** to open a full-width panel under that row: their photo, reliability (late and no-show counts), and a ±48 hour chart of everything they are doing, including unavailability. **Hover a name** instead for a quick card with the same reliability figures, the next few shifts, and their SmartStaff notes.

## Booking them

Tick people, then use the buttons at the bottom or in the results header:

- **+ Add** — offers the call. They are unconfirmed until they accept.
- **✓ Add & Confirm** — books them outright.
- **✉ Send SMS** — adds them and sends the SMS in one go.
- **Copy Names** / **Copy + Phones** — for pasting into a message.

The write goes through THE GOAT's own SmartStaff session. No popup, no callsheet page.

Two things to expect:

- **Adding to a call expands to the calls it feeds.** One action can create rows on more calls than you selected. That is the point of feeds, but it does mean the count can be larger than you expect.
- **Anyone already on a call is left alone** and reported as skipped rather than rewritten — rewriting would wipe a confirmed row back to nothing and orphan their calendar entry.

A clean run just shows a summary on the button: `Done - 5 added, 2 already on call`. If anything was skipped, failed, or could not be verified, a results panel opens and names each one. **"Couldn't verify" means the write probably went through but was not read back** — check those calls before relying on them.

Adding more than one person to a Crew Boss call asks you to confirm first. Boss calls should carry one resource, because everyone booked on the job sees them as their contact.
