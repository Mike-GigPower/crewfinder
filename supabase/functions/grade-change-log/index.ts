import { createClient } from "https://esm.sh/@supabase/supabase-js@2.118.0";

// grade-change-log — the audit log for THE GOAT's Change pay grade.
// BRIEF-promotion-paygrade-slice1.md §3; DESIGN-promotion-paygrade-cascade v0.2.
//
//   POST {mode:"log",  user_id, ein?, from_grade, to_grade, wef, applied_by,
//                      rows:[{call_id, from_grade, to_grade?, action, reason?}]}
//                                               -> {id}
//   POST {mode:"kept", user_id, grade}          -> {call_ids:[…]}
//        call IDs logged 'kept' or 'before_wef' for this person in any change
//        whose to_grade is `grade` (their current grade). The crew record's
//        safety-net banner ignores these.
//
// THIS FUNCTION NEVER DECIDES WHO MAY DO WHAT. Flask enforces the cohort
// (@require_cohort("admin")). The function trusts its key and nothing else.
//
// Auth reuses GOAT_RATE_CARD_KEY, as call-types does: the same trust boundary
// (pay and pricing data THE GOAT writes), so no new secret to provision on
// either side. Deploy with --no-verify-jwt: THE GOAT sends the key header,
// not a JWT.
//
// applied_by is a SmartStaff user ID that Flask takes from current_user_id().
// It is never read from the browser, and this function cannot check it.

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const SERVICE_ROLE = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const SERVICE_KEY = Deno.env.get("GOAT_RATE_CARD_KEY") ?? "";

const admin = createClient(SUPABASE_URL, SERVICE_ROLE, {
  auth: { autoRefreshToken: false, persistSession: false },
});

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });

// Kept in step with the CHECK in 20261117060000_grade_change_log.sql.
const ACTIONS = new Set(["updated", "kept", "skipped", "before_wef", "locked"]);
const MAX_ROWS = 1000;

const isInt = (v: unknown) =>
  v !== null && v !== "" && v !== undefined && Number.isInteger(Number(v));
const isoDate = (v: unknown) =>
  typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v);

Deno.serve(async (req) => {
  if (!SERVICE_KEY) {
    console.error("GOAT_RATE_CARD_KEY is not set — refusing every request");
    return json({ error: "Not configured" }, 503);
  }
  const key = req.headers.get("X-Goat-Service-Key");
  if (!key) return json({ error: "Unauthorized" }, 401);
  if (key !== SERVICE_KEY) return json({ error: "Forbidden" }, 403);
  if (req.method !== "POST") return json({ error: "Method not allowed" }, 405);

  let body: Record<string, unknown>;
  try {
    body = await req.json();
  } catch {
    return json({ error: "Body must be JSON" }, 400);
  }

  if (!isInt(body.user_id)) return json({ error: "user_id must be an integer" }, 400);
  const userId = Number(body.user_id);

  // ── kept ──────────────────────────────────────────────────────────────────
  if (body.mode === "kept") {
    if (!isInt(body.grade)) return json({ error: "grade must be an integer" }, 400);
    const { data: logs, error: e1 } = await admin
      .from("grade_change_log").select("id")
      .eq("user_id", userId).eq("to_grade", Number(body.grade));
    if (e1) {
      console.error("grade-change-log kept read failed:", e1.message);
      return json({ error: "Failed to read the grade change log" }, 500);
    }
    const ids = (logs ?? []).map((l) => l.id);
    if (!ids.length) return json({ call_ids: [] });
    const { data: rows, error: e2 } = await admin
      .from("grade_change_rows").select("call_id")
      .in("log_id", ids).in("action", ["kept", "before_wef"]);
    if (e2) {
      console.error("grade-change-log kept rows read failed:", e2.message);
      return json({ error: "Failed to read the grade change log" }, 500);
    }
    return json({ call_ids: [...new Set((rows ?? []).map((r) => r.call_id))] });
  }

  // ── log ───────────────────────────────────────────────────────────────────
  if (body.mode === "log") {
    for (const f of ["from_grade", "to_grade", "applied_by"]) {
      if (!isInt(body[f])) return json({ error: `${f} must be an integer` }, 400);
    }
    if (!isoDate(body.wef)) return json({ error: "wef must be YYYY-MM-DD" }, 400);
    const rows = Array.isArray(body.rows) ? body.rows as Record<string, unknown>[] : [];
    if (rows.length > MAX_ROWS) return json({ error: `at most ${MAX_ROWS} rows` }, 400);

    const seen = new Set<number>();
    const clean: Record<string, unknown>[] = [];
    for (const r of rows) {
      if (!isInt(r.call_id) || !isInt(r.from_grade)) {
        return json({ error: "each row needs integer call_id and from_grade" }, 400);
      }
      const action = String(r.action ?? "");
      if (!ACTIONS.has(action)) return json({ error: `bad action ${action}` }, 400);
      const cid = Number(r.call_id);
      if (seen.has(cid)) continue;          // one row per call per change
      seen.add(cid);
      clean.push({
        call_id: cid,
        from_grade: Number(r.from_grade),
        to_grade: isInt(r.to_grade) ? Number(r.to_grade) : null,
        action,
        reason: r.reason == null ? null : String(r.reason).slice(0, 200),
      });
    }
    const count = (a: string) => clean.filter((r) => r.action === a).length;

    const { data: log, error } = await admin
      .from("grade_change_log")
      .insert({
        user_id: userId,
        ein: isInt(body.ein) ? Number(body.ein) : null,
        from_grade: Number(body.from_grade),
        to_grade: Number(body.to_grade),
        wef: body.wef,
        applied_by: Number(body.applied_by),
        rows_updated: count("updated"),
        rows_skipped: count("skipped"),
        rows_kept: count("kept"),
        rows_locked: count("locked"),
        rows_before_wef: count("before_wef"),
      })
      .select("id").single();
    if (error || !log) {
      console.error("grade-change-log insert failed:", error?.message);
      return json({ error: "Failed to write the grade change log" }, 500);
    }

    if (clean.length) {
      const { error: e2 } = await admin
        .from("grade_change_rows")
        .insert(clean.map((r) => ({ ...r, log_id: log.id })));
      if (e2) {
        // The header row stands: it records that the change happened, which is
        // the part that must never be lost. Say the detail is missing.
        console.error("grade-change-log rows insert failed:", e2.message);
        return json({ id: log.id, rows_logged: false }, 500);
      }
    }
    return json({ id: log.id, rows_logged: true });
  }

  return json({ error: "mode must be log or kept" }, 400);
});
