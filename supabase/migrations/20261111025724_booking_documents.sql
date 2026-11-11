-- Booking Documents and Figures, slice A: the bucket and the two tables.
-- BRIEF-booking-documents-figures.md §3.1 (v0.2). Release 5.102.0.
--
-- Two tables and one private bucket, all reached ONLY by the goat-booking-docs
-- edge function running as the service role. THE GOAT's Flask layer enforces
-- who may do what (§6: @require_cohort); the function trusts its key; these
-- tables trust the function. Nothing here is reachable from a browser.
--
-- WHY EACH PIECE IS THE WAY IT IS:
--
--   booking_id is a plain integer with NO foreign key -- bookings live in
--   SmartStaff (MariaDB), not here. Same shape as crew_notes.scope_id and
--   checkin_booking_modes.booking_id, which are the verified precedent for a
--   SmartStaff booking ID in this database.
--
--   added_by_user_id / removed_by_user_id / set_by_user_id are SmartStaff user
--   IDs, integers, taken from Flask's current_user_id() and NEVER from the
--   browser (§6 "Identity"). Not EINs: admin accounts all report EIN 0, which
--   would put Mike, Rich, Monty and Joe in one bucket.
--
--   kind + the exactly-one-of check -- a row is a file OR a link, never both
--   and never neither. Without the constraint a half-written row would render
--   as an untitled, unopenable entry and nobody would know which half failed.
--
--   category is a CHECK, not a lookup table or an enum. The six values are a
--   product decision (Q3) that changes by editing this list in a migration;
--   an enum would need ALTER TYPE, and a table would need a join on every read
--   for six fixed strings.
--
--   url ~ '^https://' -- a document link is a URL someone will click from an
--   Admin's desktop. http:// and javascript: are refused in the column, not
--   only in the function, because the column is the last line of defence.
--
--   removed_at / removed_by_user_id, no delete (Q6) -- a removed invoice or
--   contract can be restored by Mike. The storage object is NEVER deleted
--   either, so restoring a row is enough to make the file openable again.
--
--   The partial index matches the only hot query there is: "live documents for
--   this booking". A full index would carry every removed row for nothing.
--
--   RLS ON WITH ZERO POLICIES -- deliberate, and the verified pattern across
--   candidates, crew_notes, crew_photos, goat_audit_log and candidate_notes
--   (all relrowsecurity = true, policy_count = 0). Deny-all to anon and
--   authenticated; the service role bypasses RLS, which is how the edge
--   function gets in. DO NOT ADD A POLICY.
--
-- Read-only and reversible: nothing here touches an existing table.

-- ── The bucket ───────────────────────────────────────────────────────────
--
-- Private, 20 MB, five MIME types (Q4). The limits live on the BUCKET as well
-- as in the function, so a bug in the function cannot store a 300 MB file or
-- an executable: storage refuses it server-side.
--
-- If this INSERT is refused for want of privilege on storage.buckets, create
-- the bucket in the dashboard with exactly these settings and re-run the rest
-- (§3.1 allows either route). crew-photos and self-time-photos on this project
-- already carry file_size_limit and allowed_mime_types, so the shape is known
-- good.

insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('booking-documents', 'booking-documents', false, 20971520,
        array['application/pdf', 'image/jpeg', 'image/png',
              'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
              'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
on conflict (id) do nothing;


-- ── booking_documents ────────────────────────────────────────────────────

create table public.booking_documents (
  id                  uuid primary key default gen_random_uuid(),
  booking_id          integer not null,
  kind                text not null check (kind in ('file', 'link')),
  category            text not null check (category in
                        ('quote', 'invoice', 'requirements',
                         'special_conditions', 'site_info', 'other')),
  title               text not null check (length(btrim(title)) between 1 and 200),
  note                text check (note is null or length(note) <= 500),
  url                 text check (url is null or url ~ '^https://'),
  storage_path        text,
  mime_type           text,
  size_bytes          integer,
  added_by_user_id    integer not null,
  added_at            timestamptz not null default now(),
  removed_at          timestamptz,
  removed_by_user_id  integer,
  check ((kind = 'link' and url is not null and storage_path is null)
      or (kind = 'file' and storage_path is not null and url is null))
);

create index booking_documents_booking
    on public.booking_documents (booking_id)
 where removed_at is null;

alter table public.booking_documents enable row level security;   -- no policies: service role only

comment on table public.booking_documents is
  'Files and links attached to a SmartStaff booking, shown on the booking dialog''s Documents tab. Written only by the goat-booking-docs edge function; Flask gates who may add and remove (Admin only, §6). booking_id is a SmartStaff booking ID with no FK — bookings live in MariaDB. Exactly one of url / storage_path is set. Remove is a SOFT delete: removed_at is stamped, the row is hidden, and the storage object is kept so Mike can restore it (Q6). RLS on with zero policies, deny-all except the service role — do not add a policy.';

comment on column public.booking_documents.storage_path is
  'Object path inside the private booking-documents bucket, always "{booking_id}/{uuid}-{safe_name}". The leading booking_id segment is what the edge function checks on add_file, so a caller cannot attach another booking''s object to this one. Never returned to the browser: the Documents tab opens a file through a 300-second signed URL (mode "sign") instead.';

comment on column public.booking_documents.added_by_user_id is
  'SmartStaff user ID from Flask current_user_id(), never from the browser. Not an EIN: admin accounts all report EIN 0.';


-- ── booking_quote ────────────────────────────────────────────────────────
--
-- One row per booking: the Quoted figure on the Figures tab, all ex GST.
--
--   source -- 'estimate_import' is the total FROZEN in the Estimator's 1.3
--   export and stored at import (D2/D6); 'manual' is an Admin typing it in for
--   an older import or a hand-made booking. The figure always says where it
--   came from, and quote_set REFUSES to overwrite an estimate_import row, so a
--   frozen quote is never quietly replaced by hand (§3.2).
--
--   estimate_id is quotes.id (a uuid) in the ESTIMATOR's database, keyed on
--   deliberately instead of quote_number: numbers are reused after deletes
--   (FINDINGS-quote-totals Q2, partial unique index on live rows only). It is
--   text rather than uuid because an unsaved quote exports a fake "est_<ts>"
--   that matches no row — slice D stores null and warns, but the column must
--   be able to hold what the Estimator actually sends.
--
--   minimums_applied (Q9) -- whether the 4-hour minimums were in the total the
--   customer saw. The tab prints "(no minimums)" beside Quoted when false.
--   NULL for manual rows, where nobody can know.
--
--   billable_hours -- the 1.3 per-line array, kept as sent so a later question
--   about how the quote was built can be answered without the Estimator. NULL
--   for manual rows.
--
--   No history table and no updated_at: changes to a quoted figure are rare,
--   and set_by_user_id + set_at answer "who typed this, and when", which is
--   the only question asked of it.
--
--   booking_id is the PRIMARY KEY, so "one quote per booking" is structural
--   rather than a rule the function has to remember on every upsert.

create table public.booking_quote (
  booking_id          integer primary key,
  source              text not null check (source in ('estimate_import', 'manual')),
  estimate_id         text,             -- quotes.id (uuid) from the export; null for manual
  quote_number        text,
  estimate_version    integer,
  labour_ex_gst       numeric(12,2),
  non_labour_ex_gst   numeric(12,2),
  total_ex_gst        numeric(12,2) not null check (total_ex_gst >= 0),
  minimums_applied    boolean,          -- Q9; null for manual
  billable_hours      jsonb,            -- 1.3 per-line [{call_name, crew_type, qty, billable_hours}]; null for manual
  set_by_user_id      integer not null,
  set_at              timestamptz not null default now()
);

alter table public.booking_quote enable row level security;       -- no policies: service role only

comment on table public.booking_quote is
  'The Quoted figure for one SmartStaff booking, ex GST, shown on the Figures tab. One row per booking (booking_id is the PK). source "estimate_import" is the total frozen in the Estimator 1.3 export and stored at import, keyed on estimate_id because quote numbers are reused after deletes; "manual" is an Admin-entered amount for older imports and hand-made bookings. The edge function refuses quote_set against an estimate_import row (409), so a frozen quote is never overwritten by hand. Written only by the goat-booking-docs edge function. RLS on with zero policies — do not add a policy.';

comment on column public.booking_quote.estimate_id is
  'quotes.id (uuid) in the Estimator database. Text, not uuid: an unsaved quote exports a fake "est_<timestamp>" matching no row, which slice D stores as null with a warning. Keyed on in preference to quote_number, which is unique only among live quotes.';

comment on column public.booking_quote.minimums_applied is
  'Q9: whether the 4-hour minimums were included in the total the customer was shown. The Figures tab prints "(no minimums)" beside Quoted when false. NULL for manual rows — nobody can know.';
