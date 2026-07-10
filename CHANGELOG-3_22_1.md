# THE GOAT — v3.22.0 -> v3.22.1

Two small fixes to last release's Crew Lists. The **Phone** column now shows each
crew member's **mobile** (it was reading the mostly-empty landline field), and
**Create Crew Lists** is now on the Schedule page's booking popup too — not just
the All Bookings tab.

- Phone: the crew Phone column now falls back mobile -> phone, matching the header
  contact number and the call-dialog roster. get-booking.php already returned
  mobile, so there's no server change and no PHP re-deploy this release.
- Schedule access: a 📋 Crew Lists button now sits alongside Import Times /
  Generate Google Sheet on the booking dialog, calling the same openCrewLists() —
  which fetches its own booking data, so it behaves identically from both places.

### The fix
- templates/index.html — `_clBuildHtml()`: the Phone column reads
  `c.mobile || c.phone` instead of `c.phone`. `openBookingDialog()`: new
  📋 Crew Lists button wired to `openCrewLists(bookingId)`.
- app.py — APP_VERSION -> 3.22.1.

### Code changes
- templates/index.html, app.py, version.json (last).
