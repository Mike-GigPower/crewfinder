---
title: Create Booking
summary: Creating a booking by hand or from a GigPower Estimator file, and what gets written to SmartStaff.
tags: booking, estimate, estimator, import, json, manual, calls, customer, venue, contact, invoice reference
order: 30
updated: 2026-09-12
---

# Create Booking

Creates a booking and its calls in SmartStaff without going near the SmartStaff booking screen. **Admin only.**

Two modes, chosen with the buttons at the top: **✎ Manual** (the default) and **⬆ From Estimate**.

## Manual

### Booking details

**Booking Name** is required — the house style is `Lil' Kim - Festival Hall`. **Booking Date**, **Invoice Reference** (optional) and **Notes** (optional) sit alongside it. Every booking is created Active.

### Customer and venue

**Customer**, **Contact** and **Venue** are all required. **On-site Contact** is optional and defaults to the booking contact if you leave it blank. Each has a **+ Add** link if what you need is not in the list yet.

### Calls

One row per call, and a booking is allowed to have none at all.

| Column | Notes |
|---|---|
| **Call Name** | The standard list. Choosing **Other** reveals a free-text box for a custom name. |
| **Date** | Defaults to the Booking Date. The weekday shows underneath — `Fri 21 May` — so a wrong date is obvious before you commit. |
| **Start** | Type it however you say it: `8.30pm`, `2030`, `830`, `10:30`, `8pm`. It normalises when you click away, and turns red if it cannot read it. |
| **Length (h)** | In hours, half-hour steps. |
| **Required** | How many crew. |
| **Notes** | Goes onto the SmartStaff call. |

**Changing the Booking Date re-dates every call row you have not touched by hand.** The moment you edit a row's date yourself, that row is pinned and stops following. This is what lets you set day two of a multi-day booking deliberately and still change the Booking Date afterwards without losing it. New rows take the Booking Date.

The **➕ Create Booking** button stays disabled until it has what it needs, and the line beside it says what is missing: *Enter a booking name*, *Select customer, contact and venue*, *Each call needs a name, date and valid time*. Once ready it reads *4 calls ready*, or **No calls — booking only**.

**✕ Clear** resets the form but leaves the Booking Date alone.

## From Estimate

Takes a JSON file exported from the GigPower Estimator. **There is no paste box** — drop the file on the zone or click to browse.

### What it checks before letting you continue

- **The estimate must be Approved.** Anything else is refused by name: *Estimate status is 'Draft' — only Approved estimates can be imported*.
- Every labour line needs a whole-number quantity of at least 1, a duration above zero, a valid date and a valid start time.
- **The same estimate cannot be imported twice.** If it has been, you are told when and which SmartStaff booking it became: *After initial import, updates must be made manually in SmartStaff.*

Anything it objects to appears in the **Validation errors** box on the left.

### The review screen

**📋 Booking Details** shows the booking name, date, invoice reference (the quote number) and how many calls will be created. These are read-only.

> **⚠ One thing to watch.** The Booking Date shown here is read from the file once and **is not updated when you edit a call's date**. The booking is actually created on the earliest date across your edited calls. If you re-date the earliest call, the booking will land on your new date, not the one still displayed. Check the dates in the Calls table, not this field.

**🔗 SmartStaff Matching** gives you four dropdowns — Customer, Contact, Onsite Contact, Venue — each marked **✓ matched** or **⚠ select manually**, with the name from the file underneath so you can see what it was trying to match. Only **Customer** and **Venue** have to be set for the button to enable.

**📞 Calls** is fully editable. Call name, date, start time, duration, crew required and notes can all be changed, rows can be deleted with **✕**, and **+ Add call** adds one the estimate missed. Only the crew type is fixed — rows you add yourself are tagged "added" instead.

**📦 Non-labour Items** is read-only. Each becomes an **Other** call with no time, no length and zero crew required, carrying the item description and a computed `Qty × price` line in its notes.

**⬆ Create in SmartStaff** tells you what is still missing while it is disabled: *Select customer and venue to continue*, *Select a call name for all lines (2 remaining)*, *3 calls missing a date*, *1 call with an invalid start time*.

### What you get

A progress bar, then one line per call — `✓ Load In · Fri 21 May` or `✗ … — <error>` — and a link straight to the new booking in SmartStaff. A clean run plays a sound.

**Import History**, down the left of this pane, lists every estimate import: quote number, booking number, an amber badge if it had errors, and the date. It is what stops the same estimate being imported twice. Manual bookings are not listed here.

## Importing times from a Google Sheet

This is **not** in this tab. It lives on the booking itself — open a booking and choose **📥 Import Times**.

### How a sheet becomes times

THE GOAT generates the sheet with one tab per call, crew pre-filled, and stamps the call's ID into cell **B1** with the label "GOAT Call ID" in **A1**. That stamp is how a tab is matched back to its call.

You then choose either **📊 Import from the Google Sheet** or upload the finished **.xlsx**.

### What the review screen will tell you

- **"Call ID recovered from B1"** — the A1 label was deleted while someone tidied the sheet, but the ID survived. Imported normally; worth putting the label back.
- **A tab that looks like a call but has no Call ID** — usually one built by hand. You get a dropdown of the calls that share crew with it, each showing how many crew match, and you pick the right one. If no call shares any crew, it cannot be placed at all.
- **Two tabs with the same Call ID** — **neither is imported**. Fix B1 so each has its own and re-import.
- **Tabs from a different booking** — named and not written.
- **"N crew on this sheet are not Confirmed in SmartStaff"** — they are ticked to write, and you can untick anyone who should not be.

> **Read the "Ignored support tabs" line at the bottom.** A generated call tab that nobody filled in is listed there, alongside genuinely ignored tabs like DATA LIST. It is the one place an empty call shows up, and it looks identical to a tab you never cared about.

Each matched call gets a card showing every crew member's times, breaks, and a red **LATE** flag where set. Confirmed crew are always written; anyone not confirmed gets their own tick box. Press **Write N times** when it looks right.
