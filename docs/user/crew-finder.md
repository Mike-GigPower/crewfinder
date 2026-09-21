---
title: Crew Finder
summary: Finding crew who can work a call — filters, the conflict rules, what blocks versus what warns, open offers, reading the timeline, and getting the most screen out of it.
tags: cf, find crew, availability, conflicts, conflict rules, clash, radius, distance, licence, rating, timeline, schedule view, focus, select all, warnings, fatigue, spot check, book crew, add and confirm, hide from results, columns, hide columns, resize, name column, sidebar, collapse, calls, open offers, offer clash, envelope, inducted first, sort order, sticky, declined
order: 20
updated: 2026-09-21
---

# Crew Finder

Crew Finder answers one question: **who can work this call?**

Everything else it does — the filters, the timeline, the warnings — exists to help you answer that faster and with fewer surprises. This guide covers the whole tab.


![Crew Finder with a call selected and results in Timeline view](images/crew-finder-01-overview.png)

It is for **admin users only**. Operations and Leadership do not see it.

## Picking the call

The left sidebar lists **Unfilled Calls** — every call in the next 90 days where booked crew is fewer than crew required. A call that is already full never appears.

- **Click** a call to select it.
- **⌘-click or Shift-click** to add more calls to the selection.
- The search box takes a **booking name, a call name, or a booking number** (with or without the `#`). If the booking name misses but a call name matches, you get the booking header with only the matching call cards under it — and a collapsed booking opens itself when the search finds a call inside it.


![The Unfilled Calls sidebar, a booking expanded to show its call cards](images/crew-finder-02-unfilled-calls.png)

Two things happen automatically when you select a call:

- **Calls it feeds are selected with it.** If this call feeds others, offering it offers them, so they come along. They show as "carried". Calls that feed *into* it are not dragged in.
- **Calls it merely suggests become "considered"** rather than selected. Crew are *ranked* against them but not searched for them — a clash on a considered call will not count against anyone.

The chips on a call card tell you this at a glance: `↓ feeds 2`, `↑ fed by 1`, `↓ suggests 3`. The booked count reads `3/5 booked`, or spells the arithmetic out when feeds reserve seats: `5 required · 2 reserved · 1 likely · 2 to fill`.

The search button tells you what it is about to do — **Select a call first**, **Search #4821**, or **Search 3 calls**. Changing the selection greys out the old results so you cannot mistake them for current ones.

## Filters

![The Filters dropdown open](images/crew-finder-03-filters.png)

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

![Spot-check a person, above the call list](images/crew-finder-04-spot-check.png)

Above the call list. Type a name, press **Check**, and THE GOAT assesses that one person against the selected call **ignoring your filters** — group, rating, licence, PT-only and distance exclusion are all skipped. Use it when someone rings up and asks, and you do not want to widen your filters to answer.

## Who is available, and why

**Only confirmed shifts are ever treated as conflicts.** A pending offer, a decline, a backup or a no-show somewhere else never blocks anyone — those draw an informational bar on the timeline and may present warning flags.

### Two things block

These are exactly what SmartStaff itself refuses at write time, so the Finder can never offer you someone the booking would reject. A blocked person lands in **CONFLICTS** with no checkbox.

- **Rule 1 — Overlap.** A confirmed shift overlaps the call.
- **Unavailability.** Leave or an unavailable period touching the call.

Declines were also previously treated as conflicts by SmartStaff, but these have been relaxed to warnings.

### Three things warn

These are policy positions you may legitimately override — a spot operator rolling into their own load-out, or a walk between two gates of the same stadium, are your call, not the system's. A warned person stays **available and bookable**, with an amber **⚠** and the reason on hover.

- **Rule 2 — Fatigue.** A shift of 8 hours or more with less than 6 hours clear of this call.
- **Rule 3 — Venue change.** A different venue with less than 2 hours between.
- **Rule 4 — 24-hour load.** More than 16 hours worked in any rolling 24 hours, counting this call.

> **⚠ Select All deliberately skips warned crew.** They must be ticked by hand, one at a time. The amber ⚠ is the only cue, so if your selected count comes back lower than the available count, that is why.

### Open offers they already hold

A crew member can accumulate offers across separate searches and end up over-committed without any single action looking wrong — which is exactly how someone ended up assigned 20 hours inside a 24-hour period before anyone noticed.

So the Finder now shows you what is already outstanding. An **✉** after the name means this person holds one or more **unanswered offers** that would clash with the call you are filling, judged by the same four rules above. Hover it for the detail.

**It is advisory and nothing more.** It never blocks, never changes the order, and badged crew stay in Select All. An offer is not a booking, and the person may well decline it — but you get to make that call knowing.

![A crew row carrying an open-offer marker](images/crew-finder-05-open-offer.png)

On the timeline, an open offer draws as a **dashed grey** bar and an offer that clashes as a **dashed amber** one. Hollow on purpose: a filled bar means that time is taken, and an unanswered offer is not.

### The order results come back in

Within **AVAILABLE FOR ALL**, crew with a **valid induction for the venue you are filling** come first, sorted by rating; everyone else follows, also sorted by rating. On a multi-call search across several gated venues, the ordering scores each person across all of them and ranks by the total, so partial coverage still counts for something.

There is no divider — the green induction badge is what makes the tier visible.

### The buckets

![The result buckets with their counts](images/crew-finder-06-buckets.png)

Results are grouped as **AVAILABLE FOR ALL**, **PARTIAL — SOME CALLS**, **CONFLICTS**, **SKIPPED**, and **LOCATION UNKNOWN — NO POSTCODE**. Skipped means a filter removed them, and the reason is given: `Missing: Rigging`, `Rating 4 < 6`, `No Forklift on file`, `32 km away`, `PT-only (excluded)`.

## Reading a row

After the name you may see, in order:

- **Licence pills** — one per licence you asked for, showing the worst status across your selected calls: `Valid`, `Held`, `Expires soon`, `Expired`, `No expiry on file`. A red **Expired** pill does not block booking unless you ticked "Only show valid on the call date".
- **Induction badges** — `✓` current, `◈` expiring, `⊘` expired, `⚠` none. Only for venues that are induction-controlled and published. **None of these affect availability.**
- **✉** — open offers outstanding that would clash. Advisory.
- **🔕** — no Crew Hub push subscription on file. Shown only when we positively know; it is not shown when the check has not run, so an absent 🔕 is not proof of anything.
- **Amber ⚠** — a Rule 2/3/4 warning. Hover for the full text.

**In Timeline view the induction badges are deliberately short** — the tick on its own for a current induction, and the icon plus one word (`⊘ Expired`, `◈ Expiring`, `⚠ No induction`) for a problem. The venue name moves into the tooltip, because on a single-venue search it is the same text on every row and it was crowding out the crew names. **Table view shows the full label**, and hovering any badge gives you the whole thing either way.


![Induction badges as they appear in Timeline view](images/crew-finder-07-badges-timeline.png)

*Timeline view — short badges*

![Induction badges as they appear in Table view](images/crew-finder-08-badges-table.png)

*Table view — the full label*

**Hover a name** for a quick card: photo, reliability (late and no-show counts), the next few shifts, and their SmartStaff notes. The card opens just clear of the Name column, so it never covers the badges — and hovering a badge shows that badge's own tooltip instead of opening the card.


![The crew hover card, clear of the Name column](images/crew-finder-09-hover-card.png)

**Click a name** to open a full-width panel under that row: photo, reliability, and a ±48 hour chart of everything they are doing, including unavailability.


![The crew detail panel opened under a row](images/crew-finder-10-detail-panel.png)

## Making the most of the screen

The Timeline is where most of the work happens, and in 5.55.0 it got the whole window to work with. Four controls, all of which remember what you chose:

**The schedule fills whatever space is left.** It used to sit at a fixed width no matter how big your screen was. Now it takes everything the other columns are not using and re-measures when you resize the window — so on a typical laptop you are looking at roughly 40 hours of schedule instead of 24.

**⋮ Columns** hides the columns you are not reading — Phone, Rating, Groups, Detail. Every column you hide goes straight to the schedule. A badge on the button counts what is hidden, so a missing column is never a mystery.


![The Columns menu open, with a count badge on the button](images/crew-finder-11-columns-menu.png)

**The Name column resizes.** Drag the handle on its right edge, or double-click the handle to fit the longest name on screen. Arrow keys nudge it once the handle has focus. The names truncate before the badges do — a shortened surname costs you nothing, a hidden induction warning could cost you a lot.

**❮ Calls** collapses the Unfilled Calls sidebar once you have picked your call, handing another 320px to the schedule. Click it again to bring the list back.


![The same call with the sidebar open and all columns showing](images/crew-finder-12-sidebar-open.png)

*Before*

![The same call with the sidebar collapsed and columns hidden](images/crew-finder-13-sidebar-collapsed.png)

*After*

**The crew names stay put.** However far right you scroll the schedule, the tick box and the name stay pinned to the left edge, so you never lose track of whose row you are reading.

**⤢ Focus** hides the nav and tab bars to fit more rows on screen. Esc brings them back. The TEST strip is never hidden.

Your hidden columns, Name column width and sidebar state are remembered **per person** — four admins on one machine keep their own.

## Reading the timeline

Each crew member gets a horizontal strip showing their shifts from 24 hours before the earliest call to 24 hours after the latest, with the call you are filling marked as a green band.

| Bar | Means |
|---|---|
| Green | Booked |
| Teal | Backup |
| Grey | Waiting |
| Red | Conflict |
| Amber | Potential conflict |
| Blue | For information only |
| Purple | Unavailable |
| Dashed grey outline | Open offer, unanswered |
| Dashed amber outline | Open offer that clashes |

![The shift-bar legend and a set of crew rows](images/crew-finder-14-timeline-bars.png)

Scroll the timeline with a trackpad swipe or Shift-scroll. Hover any bar for the booking, call, times, venue and status.


![Hovering a shift bar shows the booking, call, times, venue and status](images/crew-finder-15-bar-tooltip.png)

**Table** view swaps the timeline for a Detail column. Name, Status, Distance and Rating are all click-to-sort.

## Booking them

Tick people, then use the buttons in the results header:

- **+ Add** — offers the call. They are unconfirmed until they accept.
- **✓ Add & Confirm** — books them outright.
- **✉ Send SMS** — adds them and sends the SMS in one go. *Not currently active: SMS has been disabled, and Crew Hub push notifications are the replacement.*
- **Copy Names** / **Copy + Phones** — for pasting into a message.

The write goes through THE GOAT's own SmartStaff session. No popup, no callsheet page.

Two things to expect:

- **Adding to a call expands to the calls it feeds.** One action can create rows on more calls than you selected. That is the point of feeds, but it does mean the count can be larger than you expect.
- **Anyone already on a call is left alone** and reported as skipped rather than rewritten — rewriting would wipe a confirmed row back to nothing and orphan their calendar entry.

A clean run just shows a summary on the button: `Done - 5 added, 2 already on call`. If anything was skipped, failed, or could not be verified, a results panel opens and names each one. **"Couldn't verify" means the write probably went through but was not read back** — check those calls before relying on them.

Adding more than one person to a Crew Boss call asks you to confirm first. Boss calls should carry one resource, because everyone booked on the job sees them as their contact.

## Quick reference

| I want to… | Do this |
|---|---|
| Find a call by its name, not the booking | Type the call name in the Unfilled Calls search |
| Check one person, ignoring my filters | Spot-check, top left |
| See more schedule | ⋮ Columns to hide what you are not reading, then ❮ Calls |
| Read long crew names | Drag the Name column handle, or double-click it to fit |
| Know why someone is in CONFLICTS | Hover their red bar, or click the name for the ±48h panel |
| Know why someone has an amber ⚠ | Hover the ⚠ — it is a policy warning, and they are still bookable |
| See if someone is already over-committed | Look for ✉ after the name and hover it |
| Book warned crew | Tick them by hand — Select All skips them on purpose |
| Get the screen back to normal | Esc for Focus, ⋮ Columns to re-show, ❯ Calls for the sidebar |
