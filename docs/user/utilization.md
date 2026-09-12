---
title: Utilization
summary: The crew booking grid — how many hours each person has, over up to 90 days, and what it deliberately leaves out.
tags: utilisation, utilization, forecast, capacity, hours, unavailable, unavailability, availability, resource forecaster
order: 50
updated: 2026-09-12
---

# Utilization

Who is booked, how much, and when. One row per crew member, one column per day.

Visible to **Admin, Operations and Leadership**. Read-only cohorts can see the grid and open someone's unavailability, but cannot add any.

## The window

Set a **FROM** date and a number of days. It **defaults to 28 days and goes up to 90**. Press **⟳ Refresh** to load.

The figures are read live from SmartStaff every time — there is no cache behind this view, which is why the indicator reads **● live**. Refresh and a plain reload do exactly the same thing.

## Reading it

| Column | Meaning |
|---|---|
| **Name** | Click it to manage that person's unavailability. |
| **Phone**, **★**, **EIN** | As recorded on the crew record. |
| **Hours** | Total confirmed hours **inside your window**, so a shift running past the end only contributes the part that falls inside it. |
| One per day | A coloured block, shaded by that day's hours. |

Day colours follow the legend: `0h`, `1–4h`, `4–8h`, `8h+`, `Unavailable` (purple), `Part-day` (a diagonally-split purple blob). Today's column is accented; weekends are tinted.

**Hover a booked day** for a card listing each call that day with its venue, call name, booking and times. Hover an unavailable day for the reason and, on a part day, the hours.

**Sort** by Name, Stars, EIN or Hours; clicking the active one flips the direction. **MIN ★** hides anyone below a rating.

The name and group filters are **shared with Inductions and Records** — narrow once, then switch views. The star slider, the sort and the date window are local to this view.

## What the numbers actually count

**Confirmed shifts only.** An unanswered offer, a pending assignment, a decline and a no-show all count as zero. This is deliberate: an offer nobody has accepted is not booked time, and counting it would overstate how committed the roster is.

Overnight shifts are split across the days they touch, so each calendar day gets its real hours rather than the whole shift landing on the start date.

## What it does not show

- **Any demand side.** This is who is booked, not what still needs filling. Nothing here compares booked crew against crew required — that lives on the Operations tab and in Crew Finder.
- **Offers.** See above.
- **Unavailability as hours.** Purple is a separate layer and never adds to the Hours column.
- **Inactive crew.** They are excluded by design. Switching Records to Inactive disables this view entirely, because inactive crew are not scheduled.

> **A booking hides unavailability.** If someone has both booked hours and an unavailability on the same day, the cell renders green. The unavailability survives only in the hover text. Worth remembering before you conclude someone is free to extend.

## Managing unavailability

Click a crew member's name. You get their periods listed chronologically with the year on every date, current and upcoming separated from past ones, and a month calendar view — full days filled solid, partial days showing their hours. Click a day to see the reason or remove it.

Adding unavailability is admin only.
