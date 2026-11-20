---
title: System tab
summary: What each panel on Today → System is telling you, and what to do when THE GOAT feels slow.
tags: system, health, slow, speed, performance, timeout, timeouts, re-login, relogin, busy, latency, health.log, diagnostics, smartstaff slow
order: 70
updated: 2026-11-20
---

# System tab

**Today → System.** Visible to **Admin** only.

When THE GOAT feels slow, this tab says where the time is going. It reads figures THE GOAT has already collected on your own Mac — nothing is sent anywhere, and it still opens when SmartStaff isn't answering, which is exactly when you'll want it.

The figures refresh every 5 seconds while the tab is open, and stop when it isn't.

## When it feels slow

1. Open **Today → System**.
2. Press **Test now**.
3. Press **Copy summary** and send it to Mike, or **Reveal log** and send `health.log`.
4. Note roughly what time it felt slow.

That is enough for Mike to work out what happened. The first number to look at yourself is **Check failed** under Silent re-logins — see below.

## The status colours

- **● green** — normal
- **● amber** — slower than usual
- **● red** — slow or failing
- **● grey** — no requests yet

The dot on the System tab button itself shows the worst of them.

## The toolbar

- **as at HH:MM:SS** — when these figures were last updated.
- **Test now** — asks SmartStaff three times, one after another, for the smallest possible answer. The total is the round trip from your Mac. The server time is how long SmartStaff itself took, including any wait behind other requests.
- **Copy summary** — copies a plain-text snapshot of this tab, ready to paste into a message to Mike.
- **Reveal log** — opens the folder holding `health.log`, a record of slow requests, errors, re-logins and background refreshes that survives restarts. It never contains crew names or what was searched for.

Every panel heading has an **ⓘ**. Hover it for the explanation, or click it to pin it open.

On a wide window the tab splits in two: the graphs on the left, the grey stat boxes on the right, and the slowest requests across the full width underneath. On a narrower window everything stacks into one column instead. Either way the tab scrolls, so nothing is out of reach.

## Services, right now

One speedo per service.

- **The needle** — how long a typical request has taken in the last 60 seconds.
- **The coloured arc** — green is normal, amber is slower than usual, red is slow.
- **The ticks** — grey marks the fastest request in the last 15 minutes, white the average, red the slowest.
- **The dot** (top right) — the overall state for the last 15 minutes.
- **"idle"** — nothing has gone to this service in the last 60 seconds, so this is the last value seen.
- **"no requests yet"** — nothing has gone to this service since THE GOAT started.

The four services:

- **SmartStaff** — the main system: bookings, calls, crew records. Almost everything you click talks to SmartStaff.
- **Supabase** — the database behind Crew Hub and recruitment: push delivery records, applicants, check-ins.
- **Crew Hub** — the crew app at crew.gigpower.com: offers and push notifications.
- **Other** — the Estimator (rate cards, public holidays), the GitHub update check, and anything else.

Ask the GOAT and Google Sheets/Drive are not measured yet.

## SmartStaff range, last 15 min

The thin line runs from the fastest request to the slowest. The solid bar is the usual range: from typical to slow (the slowest 5% start where the bar ends). The red mark is the slowest. The ring is **now**.

If the ring sits to the right of the bar, things are slower than usual right now.

## SmartStaff, last 60 minutes

Each point is the typical time for that minute. The red dot is the slowest minute. The dashed line is the average for the hour. The faint amber and red lines are where "slower than usual" and "slow" begin.

**Grey bands** are when THE GOAT's automatic crew-list refresh ran in the background. If spikes line up with grey bands, the refresh is what slowed things down.

**Gaps** are minutes when nothing was sent.

## Right now

How many requests are waiting at SmartStaff at this moment. SmartStaff answers one request at a time per login, so the rest queue. One or two lamps lit is normal. When many are lit, everything you click waits behind them until they clear.

## Timeouts (60 min)

Requests that gave up waiting for an answer. Usually it shows up as an error message or a panel that doesn't load. Any timeout turns the status red.

## Silent re-logins (60 min)

Times THE GOAT quietly logged you back in to SmartStaff.

- **Expired** is normal, for example after the Mac has been asleep.
- **Check failed** means SmartStaff was too slow to answer "am I still logged in?", so THE GOAT logged in again anyway. That can add up to 10 seconds or more to a click. **This is the number to watch when it feels slow.**

## Session checks (GET /dash, before every request)

Before almost every request, THE GOAT first asks SmartStaff "am I still logged in?". These cards show how many of those checks ran and how long they took. Because one runs before nearly every click, a slow check makes everything feel slow.

**Check timeouts** are checks that got no answer in time. Each one causes a "check failed" re-login.

## Background (15-minute crew-cache refresh)

THE GOAT rebuilds its crew list in the background every 15 minutes. This shows when that last ran, how long it took, and how many requests it made.

It runs on your own SmartStaff login, so while it is working your own clicks queue behind it. The grey bands on the 60-minute trace are these runs.

## Slowest 20 requests, last hour

**Total** is the round trip from your Mac; **Server** is how long the far end took, where it reports it. **Busy** is how many SmartStaff requests were already in flight when this one was sent.

**Origin** says whether your click caused it (*you*) or the automatic refresh did (*background*). **Result** is the response code, or the error if it failed.

## The log

`~/Library/Logs/The GOAT/health.log` keeps the last few megabytes and survives restarts, so "it was slow twenty minutes ago" is answerable after a restart. It records every request that errored, timed out or took over 2 seconds, every silent re-login with its reason, every background refresh, and a summary line every 15 minutes.

It records how long requests took and whether they failed — never what was in them, never a search term, and never anything about crew.
