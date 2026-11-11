import { createClient } from "https://esm.sh/@supabase/supabase-js@2.118.0";

// goat-booking-docs — documents and the quoted figure for one SmartStaff
// booking, for THE GOAT's Documents and Figures tabs.
// BRIEF-booking-documents-figures.md §3.2 (v0.2). Release 5.102.0.
//
//   POST {mode:"list",         booking_id}                        -> {documents:[…]}
//   POST {mode:"add_link",     booking_id, category, title, note?, url, user_id}
//                                                                 -> {document}
//   POST {mode:"upload_url",   booking_id, filename, mime_type, size_bytes, user_id}
//                                                                 -> {path, upload_url, token}
//   POST {mode:"add_file",     booking_id, category, title, note?, path,
//                              mime_type, size_bytes, user_id}    -> {document}
//   POST {mode:"sign",         document_id}                       -> {url}
//   POST {mode:"remove",       document_id, user_id}              -> {ok:true}
//   POST {mode:"quote_get",    booking_id}                        -> {quote}   (null if none)
//   POST {mode:"quote_set",    booking_id, total_ex_gst, user_id} -> {quote}   (409 if frozen)
//   POST {mode:"quote_import", …1.3 fields…, user_id}             -> {quote}
//
// THIS FUNCTION NEVER DECIDES WHO MAY DO WHAT. Flask enforces the cohorts
// (@require_cohort: Documents is READ_ALL_COHORTS to view and admin to change;
// Figures is admin throughout, §6). The function trusts its key and nothing
// else. Adding an authorisation rule here would put the decision in two places
// and let them drift.
//
// Auth is a dedicated secret, GOAT_BOOKING_DOCS_KEY, rather than a shared one,
// so booking-document access can be revoked on its own — the same reasoning as
// GOAT_CREW_NOTES_KEY. Deploy with --no-verify-jwt: THE GOAT authenticates
// with the header, not a JWT.
//
// user_id is a SmartStaff user ID that Flask takes from current_user_id(). It
// is never read from the browser, and this function has no way to check it —
// that is Flask's job, stated here so the next reader does not assume
// otherwise.

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const SERVICE_ROLE = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const DOCS_KEY = Deno.env.get("GOAT_BOOKING_DOCS_KEY") ?? "";

const admin = createClient(SUPABASE_URL, SERVICE_ROLE, {
  auth: { autoRefreshToken: false, persistSession: false },
});

const BUCKET = "booking-documents";
const MAX_BYTES = 20 * 1024 * 1024; // 20 MB (Q4), also enforced on the bucket
const SIGN_SECS = 300; // 5 minutes for a download URL (§3.2)
const MAX_TITLE = 200;
const MAX_NOTE = 500;
const MAX_NAME = 80; // safe_name is cut to this

// Q3. Kept in step with the category CHECK in 20261112_booking_documents.sql —
// if one changes, change both, or an insert that passes here fails in the
// database with a constraint error nobody can read.
const CATEGORIES = new Set([
  "quote",
  "invoice",
  "requirements",
  "special_conditions",
  "site_info",
  "other",
]);

// Q4. Also set on the bucket, so a bug here cannot store an executable.
const ALLOWED_MIME = new Set([
  "application/pdf",
  "image/jpeg",
  "image/png",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
]);

// The columns the tab shows. storage_path is deliberately absent: the browser
// opens a file through mode "sign", never by path (§3.2 "without storage_path").
const DOC_COLS =
  "id, booking_id, kind, category, title, note, url, mime_type, size_bytes, " +
  "added_by_user_id, added_at";

const QUOTE_COLS =
  "booking_id, source, estimate_id, quote_number, estimate_version, " +
  "labour_ex_gst, non_labour_ex_gst, total_ex_gst, minimums_applied, " +
  "billable_hours, set_by_user_id, set_at";

const cors = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
  "Access-Control-Allow-Headers":
    "authorization, x-client-info, apikey, content-type, x-goat-service-key",
};

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { ...cors, "Content-Type": "application/json" },
  });

// ── helpers ───────────────────────────────────────────────────────────────

const UUID_RE =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const posInt = (v: unknown): number | null => {
  const n = Number(v);
  return Number.isInteger(n) && n > 0 ? n : null;
};

const nonNegInt = (v: unknown): number | null => {
  const n = Number(v);
  return Number.isInteger(n) && n >= 0 ? n : null;
};

/** Trimmed string, or null when absent/blank/not a string. */
const str = (v: unknown): string | null => {
  if (typeof v !== "string") return null;
  const s = v.trim();
  return s ? s : null;
};

/**
 * Constant-time secret comparison (§3.2).
 *
 * Both sides are hashed to a fixed 32 bytes first, so the loop runs the same
 * number of times whatever the candidate's length — comparing the raw strings
 * would leak the secret's length through the timing of the length check.
 */
async function sameSecret(a: string, b: string): Promise<boolean> {
  const enc = new TextEncoder();
  const [ha, hb] = await Promise.all([
    crypto.subtle.digest("SHA-256", enc.encode(a)),
    crypto.subtle.digest("SHA-256", enc.encode(b)),
  ]);
  const x = new Uint8Array(ha);
  const y = new Uint8Array(hb);
  let diff = 0;
  for (let i = 0; i < x.length; i++) diff |= x[i] ^ y[i];
  return diff === 0;
}

/**
 * §3.2: keep [A-Za-z0-9._-], replace everything else with _, cut to 80.
 *
 * The result is only ever the LAST segment of a path this function builds, and
 * the character class excludes "/" and ".." can never introduce a separator,
 * so a crafted filename cannot climb out of its booking's folder. The leading
 * dot is stripped as well, so nothing lands as a hidden file.
 */
function safeName(name: string): string {
  const cleaned = name.replace(/[^A-Za-z0-9._-]/g, "_").slice(0, MAX_NAME);
  const noLeadingDot = cleaned.replace(/^\.+/, "");
  return noLeadingDot || "file";
}

/** The shared validation for add_link and add_file. Returns an error string, or null. */
function badCommonFields(
  category: unknown,
  title: unknown,
  note: unknown,
): string | null {
  const c = str(category);
  if (!c || !CATEGORIES.has(c)) {
    return `category must be one of ${[...CATEGORIES].join(", ")}`;
  }
  const t = str(title);
  if (!t) return "title required";
  if (t.length > MAX_TITLE) return `title may be at most ${MAX_TITLE} characters`;
  if (note != null && typeof note !== "string") return "note must be a string";
  if (typeof note === "string" && note.trim().length > MAX_NOTE) {
    return `note may be at most ${MAX_NOTE} characters`;
  }
  return null;
}

// ── modes ─────────────────────────────────────────────────────────────────

async function list(body: Record<string, unknown>) {
  const bookingId = posInt(body.booking_id);
  if (!bookingId) return json({ error: "booking_id required" }, 400);

  const { data, error } = await admin
    .from("booking_documents")
    .select(DOC_COLS)
    .eq("booking_id", bookingId)
    .is("removed_at", null)
    .order("added_at", { ascending: false });

  if (error) {
    console.error("goat-booking-docs list failed:", error.message);
    return json({ error: "Failed to read documents" }, 500);
  }
  return json({ documents: data ?? [] });
}

async function addLink(body: Record<string, unknown>) {
  const bookingId = posInt(body.booking_id);
  if (!bookingId) return json({ error: "booking_id required" }, 400);
  const userId = posInt(body.user_id);
  if (!userId) return json({ error: "user_id required" }, 400);

  const bad = badCommonFields(body.category, body.title, body.note);
  if (bad) return json({ error: bad }, 400);

  const url = str(body.url);
  // Matches the column's own CHECK. http:// and javascript: are refused here
  // and again in the database.
  if (!url || !url.startsWith("https://")) {
    return json({ error: "url must start with https://" }, 400);
  }

  const { data, error } = await admin
    .from("booking_documents")
    .insert({
      booking_id: bookingId,
      kind: "link",
      category: str(body.category),
      title: str(body.title),
      note: str(body.note),
      url,
      added_by_user_id: userId,
    })
    .select(DOC_COLS)
    .single();

  if (error || !data) {
    console.error("goat-booking-docs add_link failed:", error?.message);
    return json({ error: "Failed to add link" }, 500);
  }
  return json({ document: data });
}

async function uploadUrl(body: Record<string, unknown>) {
  const bookingId = posInt(body.booking_id);
  if (!bookingId) return json({ error: "booking_id required" }, 400);
  if (!posInt(body.user_id)) return json({ error: "user_id required" }, 400);

  const filename = str(body.filename);
  if (!filename) return json({ error: "filename required" }, 400);

  const mime = str(body.mime_type);
  if (!mime || !ALLOWED_MIME.has(mime)) {
    return json({ error: "File type not allowed. PDF, JPEG, PNG, DOCX or XLSX only." }, 400);
  }

  // size_bytes is the caller's claim, checked again against the stored object
  // in add_file, and a third time by the bucket's own limit. This check is
  // only here to refuse a doomed upload before the bytes move.
  const size = nonNegInt(body.size_bytes);
  if (size === null) return json({ error: "size_bytes required" }, 400);
  if (size === 0) return json({ error: "File is empty" }, 400);
  if (size > MAX_BYTES) return json({ error: "File is larger than 20 MB" }, 400);

  const path = `${bookingId}/${crypto.randomUUID()}-${safeName(filename)}`;

  // Signed upload URLs last 2 hours (storage-js 2.118.0 docs). upsert is left
  // off: the path carries a fresh uuid, so it can never collide, and allowing
  // overwrite would let a second call replace a file a row already points at.
  const { data, error } = await admin.storage.from(BUCKET)
    .createSignedUploadUrl(path);

  if (error || !data) {
    console.error("goat-booking-docs upload_url failed:", error?.message);
    return json({ error: "Failed to create upload URL" }, 500);
  }
  return json({ path: data.path, upload_url: data.signedUrl, token: data.token });
}

async function addFile(body: Record<string, unknown>) {
  const bookingId = posInt(body.booking_id);
  if (!bookingId) return json({ error: "booking_id required" }, 400);
  const userId = posInt(body.user_id);
  if (!userId) return json({ error: "user_id required" }, 400);

  const bad = badCommonFields(body.category, body.title, body.note);
  if (bad) return json({ error: bad }, 400);

  const path = str(body.path);
  if (!path) return json({ error: "path required" }, 400);

  // THE CHECK THAT MATTERS (§3.2): the object must sit under THIS booking's
  // folder. Without it, a caller could name any path in the bucket and attach
  // another booking's quote or invoice to this one. The prefix test is exact
  // and the remainder must be a single segment, so "12220/../12221/x" and
  // "122200/x" are both refused.
  const prefix = `${bookingId}/`;
  const rest = path.startsWith(prefix) ? path.slice(prefix.length) : null;
  if (!rest || rest.includes("/") || rest.includes("..")) {
    return json({ error: "path is not under this booking" }, 400);
  }

  // The object must already exist — add_file runs after the PUT. info() also
  // gives the SIZE AND TYPE AS STORED, which are what get recorded: the
  // caller's claims are not trusted for the row.
  const { data: info, error: infoErr } = await admin.storage.from(BUCKET).info(path);
  if (infoErr || !info) {
    console.error("goat-booking-docs add_file: object missing at", path, infoErr?.message);
    return json({ error: "Uploaded file not found. Please try the upload again." }, 400);
  }

  const storedMime = str(info.contentType) ?? str(body.mime_type);
  if (!storedMime || !ALLOWED_MIME.has(storedMime)) {
    return json({ error: "File type not allowed. PDF, JPEG, PNG, DOCX or XLSX only." }, 400);
  }
  const storedSize = nonNegInt(info.size) ?? nonNegInt(body.size_bytes);
  if (storedSize !== null && storedSize > MAX_BYTES) {
    return json({ error: "File is larger than 20 MB" }, 400);
  }

  const { data, error } = await admin
    .from("booking_documents")
    .insert({
      booking_id: bookingId,
      kind: "file",
      category: str(body.category),
      title: str(body.title),
      note: str(body.note),
      storage_path: path,
      mime_type: storedMime,
      size_bytes: storedSize,
      added_by_user_id: userId,
    })
    .select(DOC_COLS)
    .single();

  if (error || !data) {
    // The object is left where it is, deliberately. Deleting it here would
    // destroy a file the Admin has already uploaded on the strength of a row
    // insert that may have failed for a transient reason; §6 says Flask tells
    // them plainly that the object is orphaned, not lost. Mike can attach it
    // by hand or it can be cleaned up later.
    console.error("goat-booking-docs add_file insert failed:", error?.message, "orphan at", path);
    return json({ error: "File uploaded but could not be recorded" }, 500);
  }
  return json({ document: data });
}

async function sign(body: Record<string, unknown>) {
  const documentId = str(body.document_id);
  if (!documentId || !UUID_RE.test(documentId)) {
    return json({ error: "document_id must be a uuid" }, 400);
  }

  // Live FILE rows only: a removed row must not be openable by anyone still
  // holding its id, and a link row has no object to sign.
  const { data: row, error } = await admin
    .from("booking_documents")
    .select("storage_path, kind")
    .eq("id", documentId)
    .is("removed_at", null)
    .maybeSingle();

  if (error) {
    console.error("goat-booking-docs sign lookup failed:", error.message);
    return json({ error: "Failed to read document" }, 500);
  }
  if (!row || row.kind !== "file" || !row.storage_path) {
    return json({ error: "Document not found" }, 404);
  }

  const { data, error: signErr } = await admin.storage.from(BUCKET)
    .createSignedUrl(row.storage_path, SIGN_SECS);

  if (signErr || !data) {
    console.error("goat-booking-docs sign failed:", signErr?.message);
    return json({ error: "Failed to create download URL" }, 500);
  }
  return json({ url: data.signedUrl });
}

async function remove(body: Record<string, unknown>) {
  const documentId = str(body.document_id);
  if (!documentId || !UUID_RE.test(documentId)) {
    return json({ error: "document_id must be a uuid" }, 400);
  }
  const userId = posInt(body.user_id);
  if (!userId) return json({ error: "user_id required" }, 400);

  // SOFT DELETE (Q6). The storage object is NEVER touched, so a document
  // removed by mistake is restored by clearing removed_at. The is-null filter
  // makes a second remove a no-op rather than re-stamping who removed it.
  const { data, error } = await admin
    .from("booking_documents")
    .update({ removed_at: new Date().toISOString(), removed_by_user_id: userId })
    .eq("id", documentId)
    .is("removed_at", null)
    .select("id")
    .maybeSingle();

  if (error) {
    console.error("goat-booking-docs remove failed:", error.message);
    return json({ error: "Failed to remove document" }, 500);
  }
  if (!data) return json({ error: "Document not found" }, 404);
  return json({ ok: true });
}

async function quoteGet(body: Record<string, unknown>) {
  const bookingId = posInt(body.booking_id);
  if (!bookingId) return json({ error: "booking_id required" }, 400);

  const { data, error } = await admin
    .from("booking_quote")
    .select(QUOTE_COLS)
    .eq("booking_id", bookingId)
    .maybeSingle();

  if (error) {
    console.error("goat-booking-docs quote_get failed:", error.message);
    return json({ error: "Failed to read quote" }, 500);
  }
  // No row is not an error: most bookings have no quote. null, never 0 —
  // "nothing quoted" and "quoted nil" are different things on the tab.
  return json({ quote: data ?? null });
}

/**
 * Money from the caller: a finite, non-negative number, rounded to cents.
 *
 * An empty or blank string is REFUSED rather than read as zero — Number("")
 * is 0, so without this a cleared input box would store a $0 quote and the
 * tab would show "Quoted $0.00" as though someone had meant it.
 *
 * The $100m ceiling is a sanity bound, well inside numeric(12,2); a quote
 * larger than that is a typo or a unit mix-up, not a booking.
 */
function money(v: unknown): number | null {
  let n: number;
  if (typeof v === "number") {
    n = v;
  } else if (typeof v === "string") {
    const s = v.trim();
    if (!s) return null;
    n = Number(s);
  } else {
    return null;
  }
  if (!Number.isFinite(n) || n < 0) return null;
  if (n > 99_999_999.99) return null;
  return Math.round(n * 100) / 100;
}

async function quoteSet(body: Record<string, unknown>) {
  const bookingId = posInt(body.booking_id);
  if (!bookingId) return json({ error: "booking_id required" }, 400);
  const userId = posInt(body.user_id);
  if (!userId) return json({ error: "user_id required" }, 400);

  const total = money(body.total_ex_gst);
  if (total === null) return json({ error: "total_ex_gst must be an amount of $0 or more" }, 400);

  // A FROZEN QUOTE IS NEVER OVERWRITTEN BY HAND (§3.2). The Estimator total is
  // what the customer was shown; retyping it would quietly replace evidence
  // with a recollection. Checked before the upsert rather than relying on a
  // constraint, so the caller gets 409 and a sentence instead of a 500.
  const { data: existing, error: readErr } = await admin
    .from("booking_quote")
    .select("source")
    .eq("booking_id", bookingId)
    .maybeSingle();

  if (readErr) {
    console.error("goat-booking-docs quote_set read failed:", readErr.message);
    return json({ error: "Failed to read quote" }, 500);
  }
  if (existing && existing.source === "estimate_import") {
    return json({ error: "Quote came from the Estimator and cannot be overwritten" }, 409);
  }

  // Only the manual fields are written. estimate_id, quote_number and the rest
  // stay null: a manual figure has no Estimator behind it to cite.
  const { data, error } = await admin
    .from("booking_quote")
    .upsert({
      booking_id: bookingId,
      source: "manual",
      total_ex_gst: total,
      set_by_user_id: userId,
      set_at: new Date().toISOString(),
    }, { onConflict: "booking_id" })
    .select(QUOTE_COLS)
    .single();

  if (error || !data) {
    console.error("goat-booking-docs quote_set failed:", error?.message);
    return json({ error: "Failed to save quote" }, 500);
  }
  return json({ quote: data });
}

async function quoteImport(body: Record<string, unknown>) {
  const bookingId = posInt(body.booking_id);
  if (!bookingId) return json({ error: "booking_id required" }, 400);
  const userId = posInt(body.user_id);
  if (!userId) return json({ error: "user_id required" }, 400);

  const total = money(body.total_ex_gst);
  if (total === null) return json({ error: "total_ex_gst must be an amount of $0 or more" }, 400);

  // Optional money: present-but-unparseable is a bad import, not a null.
  const labour = body.labour_ex_gst == null ? null : money(body.labour_ex_gst);
  if (body.labour_ex_gst != null && labour === null) {
    return json({ error: "labour_ex_gst must be an amount" }, 400);
  }
  const nonLabour = body.non_labour_ex_gst == null ? null : money(body.non_labour_ex_gst);
  if (body.non_labour_ex_gst != null && nonLabour === null) {
    return json({ error: "non_labour_ex_gst must be an amount" }, 400);
  }

  const version = body.estimate_version == null ? null : posInt(body.estimate_version);
  if (body.estimate_version != null && version === null) {
    return json({ error: "estimate_version must be a positive integer" }, 400);
  }

  if (body.minimums_applied != null && typeof body.minimums_applied !== "boolean") {
    return json({ error: "minimums_applied must be a boolean" }, 400);
  }
  if (body.billable_hours != null && !Array.isArray(body.billable_hours)) {
    return json({ error: "billable_hours must be an array" }, 400);
  }

  // estimate_id is stored as sent, EXCEPT that slice D passes null for an
  // unsaved "est_<timestamp>" quote (FINDINGS-quote-totals Q2). Deciding that
  // is the importer's job, not this function's — it records what it is given.
  const { data, error } = await admin
    .from("booking_quote")
    .upsert({
      booking_id: bookingId,
      source: "estimate_import",
      estimate_id: str(body.estimate_id),
      quote_number: str(body.quote_number),
      estimate_version: version,
      labour_ex_gst: labour,
      non_labour_ex_gst: nonLabour,
      total_ex_gst: total,
      minimums_applied: body.minimums_applied == null
        ? null
        : (body.minimums_applied as boolean),
      billable_hours: body.billable_hours ?? null,
      set_by_user_id: userId,
      set_at: new Date().toISOString(),
    }, { onConflict: "booking_id" })
    .select(QUOTE_COLS)
    .single();

  if (error || !data) {
    console.error("goat-booking-docs quote_import failed:", error?.message);
    return json({ error: "Failed to store quote" }, 500);
  }
  return json({ quote: data });
}

// ── entry point ───────────────────────────────────────────────────────────

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: cors });

  // Fail loudly rather than silently letting everyone in if the secret was
  // never set on the project.
  if (!DOCS_KEY) {
    console.error("GOAT_BOOKING_DOCS_KEY is not set — refusing every request");
    return json({ error: "Not configured" }, 503);
  }

  // No key at all is a caller that has not authenticated; a wrong key is a
  // caller that has, and is not allowed.
  const key = req.headers.get("X-Goat-Service-Key");
  if (!key) return json({ error: "Unauthorized" }, 401);
  if (!(await sameSecret(key, DOCS_KEY))) return json({ error: "Forbidden" }, 403);

  if (req.method !== "POST") return json({ error: "Method not allowed" }, 405);

  let body: Record<string, unknown>;
  try {
    const parsed = await req.json();
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
      throw new Error("not an object");
    }
    body = parsed as Record<string, unknown>;
  } catch {
    return json({ error: "Body must be a JSON object" }, 400);
  }

  switch (body.mode) {
    case "list":
      return await list(body);
    case "add_link":
      return await addLink(body);
    case "upload_url":
      return await uploadUrl(body);
    case "add_file":
      return await addFile(body);
    case "sign":
      return await sign(body);
    case "remove":
      return await remove(body);
    case "quote_get":
      return await quoteGet(body);
    case "quote_set":
      return await quoteSet(body);
    case "quote_import":
      return await quoteImport(body);
    default:
      return json({ error: "Unknown mode" }, 400);
  }
});
