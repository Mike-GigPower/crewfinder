---
title: Inductions
summary: Reading the induction grid, what the colours mean, how expiry is worked out, and maintaining the catalogue.
tags: induction, inductions, venue, expiry, expiring, compliance, MCG, Crown, catalogue, csv, export
order: 40
updated: 2026-09-12
---

# Inductions

A grid of who is inducted where. One row per crew member, one column per venue.

Visible to **Admin, Operations and Leadership**. Editing the catalogue is **admin only**.

## Reading the grid

Each cell is a coloured dot:

| Dot | Meaning |
|---|---|
| 🟢 **Valid** | Current. Hover for the expiry date. |
| 🟠 **Expiring Soon** | Inside the warning window — chase it. |
| 🔴 **Expired** | Hover shows when it expired and when it was completed. |
| ⚪ **Incomplete** | A record exists with no completion date. |
| ⚫ **N/A** | No record for that venue at all. |

The footer counts resources, expired and incomplete. **It does not count expiring-soon**, so a clean-looking footer can still have amber in the grid.

### Narrowing it down

- **Name** — searches crew names only.
- **Group** — chips built from the groups the loaded crew actually hold.
- **▼ Venues** — pick which columns you want, with **All** and **None**. The badge reads `All`, `None`, or `6/21`.

The name and group filters are **shared with Utilization and Records**. Narrow to a set of people once, then look at them through whichever lens you need. Note that Records also matches on EIN and phone, while Inductions matches names only — so a search that worked on Records may look broken here.

Columns only exist for venues where somebody, somewhere, has a record. A venue nobody has ever been inducted at has no column.

## How expiry is decided

```
expiry  = completed date + validity period
Expired       today is past expiry
Expiring Soon expiry is within the warning window
Valid         otherwise
```

Both numbers come from the **induction catalogue** in SmartStaff, per induction. A catalogue entry with no warning window of its own uses the catalogue default, which is **14 days**.

If the catalogue cannot be reached, THE GOAT falls back to 24 months for Crown Melbourne and 12 months for everything else, with a 14-day warning. A record with no completion date is **Incomplete** rather than being guessed at.

### Venues that share one induction

Several SmartStaff venues can be covered by a single induction — all eight MCG gates, both Crown rows, both MCEC rows. Where that rollup applies, **being inducted at any covered venue counts as inducted for the whole induction**, and the best status wins. This is what stopped someone current at MCG - Gate 7 but lapsed at MCG - Brunton Ave reading as Expired.

> This rollup is applied when Crew Finder decides whether to warn you about a booking, and when you ask THE GOAT about compliance. **The grid on this page lists venues as SmartStaff stores them**, so the individual gates appear as their own columns. Judge a booking by the Crew Finder badge, not by scanning gates here.

## Exporting

**⇓ Export CSV** downloads the grid as `inductions-YYYY-MM-DD.csv`, with a column per **currently selected venue**.

Two things to know before you send it to anyone:

- **The export ignores the name and group filters.** Filtering to a group and exporting gives you the whole roster. Only your venue column selection is honoured.
- **Expiring-soon crew export as "Incomplete"**, not as valid with a date. Do not use the CSV to work out who is about to lapse — use the amber dots on screen.

## Maintaining the catalogue

**Administration → Manage Inductions.** Admin only.

Each induction carries a **Title**, a **Code** (set when created and never changeable), the **Covered venues** it applies to, its **Validity** in months or years, and a **Warn from** window in days.

- **Crew note** is shown to crew. **Ops note** is internal and is labelled as such.
- **Links** attach useful URLs by type — register, sitemap, info, parking, contact, entry — each with its own label.
- **Show on Induction Checker** and **Publish to Crew Hub** are separate switches.

**Publishing gates the whole check.** An induction that is not published produces neither a warning nor a green tick in Crew Finder — the badge is simply blank. That is deliberate, but it means an unpublished induction is an induction nobody is being warned about.

### Changing a validity period

Changing validity re-dates everyone who holds that induction, so THE GOAT works out the damage first and asks:

> Changing "Marvel Stadium" to 12 months re-dates 214 crew: 31 → Expired, 18 → Expiring Soon.
> Affected crew won't be re-notified for this change.

Cancel writes nothing.

### Deleting

If any crew have completed it, deleting is blocked — it would drop their history. THE GOAT offers **Unpublish + remove from Checker** instead, which takes it out of circulation and keeps the record.
