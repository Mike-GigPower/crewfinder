-- Change pay grade, Slice 1: the audit log.
-- BRIEF-promotion-paygrade-slice1.md §3; DESIGN-promotion-paygrade-cascade v0.2.
--
-- One row per grade change applied from THE GOAT, plus one row per call it
-- considered. Written and read ONLY by the grade-change-log edge function
-- running as the service role.
--
-- WHY EACH PIECE IS THE WAY IT IS:
--
--   user_id / applied_by / call_id are plain integers with NO foreign key:
--   people and calls live in SmartStaff (MariaDB), not here. applied_by is the
--   SmartStaff userID from Flask's current_user_id(), never the browser.
--
--   grade_change_rows.action 'kept' is a row Rich unticked; 'before_wef' a row
--   dated before the WEF date. The crew record's safety-net banner ignores
--   those two for the person's CURRENT grade, so a deliberate choice doesn't
--   nag forever. The partial index serves exactly that read.
--
--   eh_status is null until Slice 2 (Employment Hero) writes it.
--
--   RLS ON WITH ZERO POLICIES -- the verified pattern (candidates, crew_notes,
--   goat_audit_log, booking_documents). Deny-all to anon and authenticated; the
--   service role bypasses RLS, which is how the edge function gets in.
--   DO NOT ADD A POLICY.
--
-- Additive only: nothing here touches an existing table.

create table public.grade_change_log (
  id               bigserial primary key,
  user_id          integer     not null,
  ein              integer,
  from_grade       integer     not null,
  to_grade         integer     not null,
  wef              date        not null,
  applied_by       integer     not null,
  applied_at       timestamptz not null default now(),
  rows_updated     integer     not null default 0,
  rows_skipped     integer     not null default 0,
  rows_kept        integer     not null default 0,
  rows_locked      integer     not null default 0,
  rows_before_wef  integer     not null default 0,
  eh_status        text
);

create index grade_change_log_user_idx on public.grade_change_log (user_id, applied_at desc);

create table public.grade_change_rows (
  log_id      bigint  not null references public.grade_change_log (id) on delete cascade,
  call_id     integer not null,
  from_grade  integer not null,
  to_grade    integer,
  action      text    not null
              check (action in ('updated', 'kept', 'skipped', 'before_wef', 'locked')),
  reason      text,
  primary key (log_id, call_id)
);

create index grade_change_rows_ignore_idx on public.grade_change_rows (log_id)
  where action in ('kept', 'before_wef');

alter table public.grade_change_log  enable row level security;
alter table public.grade_change_rows enable row level security;
