# THE GOAT — v3.6.1

_Release date: 2026-06-17_

The manual Booking form can now create a **Customer, Contact, or Venue inline**
via "+ Add" links beside each field — mirroring SmartStaff's own (Add New)
affordance — so an operator building a booking for a brand-new client or venue
no longer has to break off, open SmartStaff, add the record, and come back. Each
link opens a small modal, writes through to SmartStaff, then drops the new record
straight into the dropdown and selects it. No new SmartStaff PHP (the existing
add pages are reused), no config, no database changes.

## Added — quick-add Customer / Contact / Venue from the booking form

Each of the **Customer**, **Contact**, and **Venue** labels gains a "+ Add" link.
Clicking it opens the shared `entity-modal` with a lean form; on submit the record
is created in SmartStaff and appended to the form's cached lookups, the relevant
dropdown is rebuilt, and the new entry is selected — all without leaving the form
or reloading the lists.

The writes reuse SmartStaff's existing add pages in ajax mode (`?ajax=1` → the page
returns the bare new id instead of redirecting), so there is no new endpoint and no
second copy of the insert logic to keep in sync:

- **Venue** → `POST venues/add`. Name only is required; created Active.
- **Customer** → `POST customer/add`. Lean form (name, optional phone/email),
  created Active.
- **Contact** → `POST contact/add?customerID={id}`, mapped to the selected customer
  via `customer_map`.

## How it works — the decisions worth remembering

**Endpoint slugs are inconsistent** (confirmed against the live forms, not guessed):
`venues/add` is plural, `customer/add` and `contact/add` are singular, and the
contact endpoint carries `customerID` in the query string, not the body.

**Customer quick-add is deliberately lean** (the chosen path): `add-customer.php`'s
new-customer branch always inserts a linked portal `users` row (usergroupID 42) from
the username/password posted. The quick form sends those blank, so a name-only add
spawns a credential-less portal user — an accepted trade for reusing the existing
endpoint rather than adding a customer-only insert path.

**Contact is gated on a selected customer.** A new contact is mapped to a customer
via `customer_map`, so the "+ Add" on Contact prompts to pick a customer first if
none is chosen. Username is required and must be unique; `firstname.lastname` is
suggested until the field is edited by hand. Note the interaction with the lean
customer add: the credential-less users it creates hold *blank* usernames, so a
blank contact username would collide — hence username is mandatory here.
`add-contact.php` doesn't echo its validation error, so a collision (or any non-id
response) surfaces as a generic "the username may already be in use" message.

**Response parsing.** Venue and contact expect a strict bare-integer id. Customer is
slightly more tolerant — `add-customer.php` reads `->info_hash` off a null object on
new adds, so if that PHP instance has `display_errors` on, a notice can precede the
id; the parser takes the trailing integer in that case.

**Dropdown refresh.** A created record is pushed into the cached `_mbLookups` list,
the list re-sorted, and the select(s) rebuilt. Contact refreshes **both** the Contact
and On-site dropdowns (they share the contacts list) while preserving whatever
On-site already had selected; the new contact is selected in the Contact dropdown.

All three write routes are admin-cohort gated, consistent with the rest of the
manual Booking surface.

## Code changes

- `app.py` — three SmartStaff write helpers `ss_create_venue` / `ss_create_customer`
  / `ss_create_contact` (POST to the existing add pages in ajax mode, parse the
  returned id); three admin routes `POST /api/booking/venue`, `/api/booking/customer`,
  `/api/booking/contact`, each returning `{id, name}`; `APP_VERSION` → 3.6.1.
- `templates/index.html` — "+ Add" links on the Customer / Contact / Venue labels;
  `openAddEntity` (modal forms on the shared `entity-modal`, customer-gated contact
  form, `firstname.lastname` username suggestion) and `submitAddEntity` (CFG-driven
  POST, record injected into `_mbLookups` + dropdown, dual-select refresh for contact
  that preserves the On-site selection).

No new SmartStaff PHP (existing `add-customer` / `add-venue` / `add-contact` endpoints
reused), no config, no database changes.
