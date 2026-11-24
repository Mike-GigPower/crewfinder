-- "Schedule" document category (BRIEF-crewlist-export-and-doc-schedule D6, §4.1).
--
-- The category list lives in FOUR places and they must agree:
--   this CHECK, supabase/functions/goat-booking-docs/index.ts (CATEGORIES),
--   app.py (BOOKING_DOC_CATEGORIES) and templates/index.html (BK_DOC_CATEGORIES).
-- An insert that passes the edge function but fails here gives the operator a
-- constraint error nobody can read, so the database goes FIRST and the build
-- that sends 'schedule' ships after it.
--
-- The constraint is dropped and recreated rather than altered: Postgres has no
-- ALTER CONSTRAINT for a CHECK expression. Existing rows all hold one of the
-- six original values, so the new, wider check validates without a rewrite.
--
-- Apply with the Supabase MCP apply_migration, NOT execute_sql: DDL through
-- execute_sql fails silently on this project.

alter table public.booking_documents drop constraint booking_documents_category_check;

alter table public.booking_documents add constraint booking_documents_category_check
  check (category in ('quote', 'invoice', 'requirements', 'schedule',
                      'special_conditions', 'site_info', 'other'));
