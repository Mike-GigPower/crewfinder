---
title: Getting Started
summary: Installing THE GOAT, signing in, finding your way around, and what each part of the app is for.
tags: install, dmg, update, login, sign in, navigation, tabs, keyboard, shortcuts, palette, admin, elevate
order: 10
updated: 2026-09-12
---

# Getting Started

THE GOAT is Gig Power's operations app. It sits on top of SmartStaff and does the things SmartStaff makes slow: finding crew who can actually work a call, creating bookings, chasing times, and watching compliance.

## Installing it

THE GOAT is distributed as a signed macOS disk image.

1. Download `TheGOAT.dmg` from the link in the update banner, or from whoever sent it to you.
2. Double-click the DMG, drag **The GOAT** into Applications.
3. The first time only: right-click the app in Applications and choose **Open**, then confirm.

The app is signed and notarised by Apple, so after that first launch it opens normally.

When you launch it, THE GOAT starts a small server on your own machine and opens the app in your default browser. That is why it looks like a web page but has a Dock icon — the page is being served from your own Mac, and nothing about it is on the internet. Closing the browser tab does not quit the app; click the Dock icon to get the tab back, and quit from the Dock to shut it down properly.

## Signing in

Use your SmartStaff username and password. THE GOAT signs in to SmartStaff on your behalf and works through that session for everything it does.

What you can see depends on your **cohort**, which comes from SmartStaff, not from anything set in the app:

| Cohort | What you get |
|---|---|
| **Admin** | Everything |
| **Operations** | Everything except Crew Finder, Create Booking, Administration, Records — read-only across the crew views |
| **Leadership** | As Operations, minus Recruitment |
| **Crew** | My Status only — your own shifts, inductions and availability |

If you are Operations and need admin for something, the **🔑 Admin** button in the top right steps you up for that session only. You authenticate with an admin account to do it, and **⤓ Exit Admin** drops back down. Elevation is never remembered between sessions.

## Finding your way around

The dark bar under the top nav holds five groups: **⚡ Today**, **📅 Bookings**, **👥 Crew**, **🧑‍💼 Recruitment**, **⚙️ Admin**. Clicking a group opens whichever view inside it you used last, so the grouping costs you nothing on the path you take every day. A second row of views appears only when a group has more than one.

| Group | What lives there |
|---|---|
| ⚡ Today | Operations (the landing page — what needs chasing right now) and Performance |
| 📅 Bookings | Schedule, Crew Finder, All Bookings, Create Booking |
| 👥 Crew | Utilization, Inductions, Records |
| 🧑‍💼 Recruitment | Operations, Dashboard, Sessions |
| ⚙️ Admin | Administration |

Beside your name in the top bar: **👤 My Status** (your own shifts and availability), **📖 Docs** (this documentation), and **Sign out**.

## Keyboard

- **⌘K** opens the command palette. Type a few letters of any view and press Enter. It knows what people actually type, so "cf" finds Crew Finder, "utilisation" finds Utilization however you spell it, and "manual" or "changelog" find Docs.
- **⧉ New window** opens the view you are on in a separate browser window. Useful for watching the Schedule on one screen while you work in another.
- **Esc** closes whatever is open — a dialog, the palette, an expanded crew row.
- In Docs, **/** jumps to the search box.

## The roster badge

Top right, beside your name, a badge reads something like `Roster: 391 (3.2 hours ago)`. That is THE GOAT's cached copy of the crew list, which powers Crew Finder, Utilization and Inductions.

- **Green with an age** — healthy, nothing to do.
- **"Roster stale … click to refresh"** — the cache has not rebuilt in over a day. Click it.
- **"no induction data, click to refresh"** — the crew list came back but the induction records did not. Click it. This matters: without it, the induction columns will show nothing and it will look like nobody has any inductions.

Clicking the badge rebuilds the cache. It normally refreshes itself every fifteen minutes while the app is open and you are signed in.

## When an update is available

A banner appears across the top saying a new version is ready, with a one-line summary of what changed. Click **Download update**, install it the same way as the first time. You can dismiss the banner and carry on; it will be back next time.

The current version number is in the top left under THE GOAT wordmark. **Patch History** in Docs has the full story for every release.

## If you see an amber TEST strip

A strip across the top reading **⚠ TEST** means the app is pointed at a test copy of SmartStaff, not the real one. Anything you do is not real. This strip cannot be dismissed, deliberately. If you see it on a machine that should be on production, stop and tell Mike.
