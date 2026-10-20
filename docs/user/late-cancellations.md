---
title: Late cancellations
summary: Standing down a call when crew are paid or the customer is charged, what the times pre-fill does, checking those times, and the pay-only and charge-only reminders.
tags: cancel, cancellation, stand down, late cancellation, pay, charge, chargeable, pre-fill, enter times, times outstanding, reinstate
order: 60
updated: 2026-10-20
---

# Late cancellations

When a call is stood down late, the crew who were confirmed can still be paid, and the customer can still be charged, as if the call went ahead.

Standing down a call is **admin only**.

## The two questions

**⏹ Stand down** on the call asks two separate questions. Neither has a default:

- **Pay confirmed crew for this call?**
- **Charge the customer?**

They're separate because the cut-off for paying crew and the cut-off for charging the customer can differ. **Stand down call** stays greyed out until both are answered. A reason is still required.

Only crew who were **Confirmed** when the call was cancelled are paid. Backups and crew with an unanswered offer are never paid for a cancellation.

## What pre-fill does

If either answer is **Yes**, THE GOAT fills in times for every confirmed crew member:

- **On** is the call's scheduled start.
- **Off** is the start plus the call's estimated length, with a **4-hour minimum**.
- Breaks are zero.

Times that were already entered are never overwritten. If the call was stood down part-way through, the real times stay. A call with no start date isn't pre-filled, so its times have to be entered by hand.

The result panel lists who was pre-filled, alongside the usual list of people who still need telling.

## Checking the times

Open the call and choose **⏱ Enter Times**. It's also where a **Cancelled – paid** row in **Ops › Times outstanding** takes you.

- Rows marked **CANCELLED · PAID** can be edited, the same as confirmed crew. The fill-down arrows include them. On a charge-only call the same rows are marked **CANCELLED · CHARGED**: their hours are for the customer charge, and crew pay must be zeroed in SmartStaff.
- Other stood-down rows are greyed out. Hover over one to see *Stood down — not paid.*
- **Save** writes the times as ordinary hours. When every paid row is saved together, the call is marked as checked. **Times to check** then disappears from the call, and the call leaves the Times outstanding lane.

There is no **Accept** on a cancelled call. **Save** does that job.

## Chips on a cancelled call

The banner on a cancelled call shows:

| Chip | Meaning |
|---|---|
| **CREW PAID** / **NOT PAID** | The answer to the pay question |
| **CUSTOMER CHARGED** / **NOT CHARGED** | The answer to the charge question |
| **TIMES TO CHECK** | The pre-filled times haven't been saved in Enter Times yet |

Calls cancelled before late-cancellation pay existed show neither **Crew paid** nor **Times to check**. Their stood-down rows stay greyed out.

## Pay-only and charge-only reminders

SmartStaff still produces both pay and a charge from the same hours, whatever the answers were. When only one answer was **Yes**, an amber reminder appears on the call and in Enter Times:

- **Pay only:** zero the customer charge for this call in SmartStaff before invoicing.
- **Charge only:** zero crew pay in SmartStaff before the pay run.

Do this by hand in SmartStaff. THE GOAT only reminds you.

## Reinstating

**↩ Reinstate call** undoes a cancellation, including a paid one. Crew come back as they would for any other reinstatement. Pre-filled times are reset, and the pay and charge chips go away.
