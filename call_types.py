"""
call_types.py — Call Types and crew row overrides (Stage 2,
BRIEF-goat-call-types-stage2 §4–§6). Pure logic: no network, no Flask.

A call's CALL TYPE is the Estimator role it charges at (Standard Crew, Show
Crew, Event Operations…), stored in Supabase (call_type) keyed by SmartStaff
callID, separate from the free-text call name (D11, D17). A call with no stored
row still has an answer: derived on the fly from its call name on the booking's
card, and NEVER written back (PL-2). Nothing is priced from any of this yet:
SmartStaff bills and pays from its RATE column until cutover (D20).

The cards are pricing_rules' (rate_cards in the Estimator project), so role
names are validated HERE before the call-types edge function sees them. That
function checks shape only.
"""

import estimator_calc
import pricing_rules

# Shown wherever an Admin can set a Call Type or an override (§6.4).
HONESTY_LINE = ("Used by THE GOAT's pricing from cutover. Until then SmartStaff "
                "still bills and pays from its RATE column.")

# The Standard card's role a row-level Crew Boss charges at (§6.3), and the
# SmartStaff pay grade it pays at, found BY NAME in get-call-times.php's list.
CREW_BOSS_ROLE = "Crew Boss"
CREW_BOSS_GRADE_NAME = "Crew Boss"

# effective_call_type sources and statuses.
DERIVED = "derived"
OK = "ok"
OFF_CARD = "off_card"
NOT_SET = "not_set"


def roles_for_set(rules, set_code):
    """The roles on one card, in sort_order, each once. A role's
    effective_from versions share a name and merge. Inactive rows never
    appear (Broadcast Rate was retired on 16 Oct). [] for an unknown card."""
    st = pricing_rules.find_set(rules, set_code)
    best = {}
    for r in (st or {}).get("rates") or []:
        name = str(r.get("role_name") or "").strip()
        if not name or r.get("is_active") is False:
            continue
        order = r.get("sort_order")
        order = order if isinstance(order, (int, float)) else 10 ** 6
        if name not in best or order < best[name]:
            best[name] = order
    return sorted(best, key=lambda n: (best[n], n))


def override_roles(rules, set_code):
    """Roles a row may be CHARGED AS by a manual override (§6.1): the booking's
    card, plus the Standard card's. The Standard ones are the "manual override"
    route NOTES §2 names for work outside a customer's card."""
    out = list(roles_for_set(rules, set_code))
    if set_code != pricing_rules.STANDARD:
        out += [r for r in roles_for_set(rules, pricing_rules.STANDARD) if r not in out]
    return out


def call_names_for_set(rules, set_code):
    """The card's call names, in role sort_order: SUGGESTIONS for the free-text
    Name on Add call, the manual Booking form and the import preview. The Call
    Type carries the role, so a call may be named anything."""
    st = pricing_rules.find_set(rules, set_code)
    rates = sorted(((st or {}).get("rates") or []),
                   key=lambda r: (r.get("sort_order") if isinstance(r.get("sort_order"), (int, float)) else 10 ** 6))
    names = []
    for r in rates:
        if r.get("is_active") is False:
            continue
        for n in r.get("call_names") or []:
            n = str(n or "").strip()
            if n and n not in names:
                names.append(n)
    return names


def derive_role(call_name, rules, set_code):
    """The role a call name resolves to on the card (exact, then the prefix
    rule), or None. Never Standard Crew by default (the 5.76 rule)."""
    cfg = pricing_rules.cfg_for_set(rules, set_code) if rules else None
    if not cfg:
        return None
    role = estimator_calc.resolve_role(call_name, cfg)["role"]
    # cfg_for_set is the pricing config and keeps inactive rows; a retired
    # role is never offered, so it is never derived either.
    return role if role in roles_for_set(rules, set_code) else None


def call_name_roles(rules, set_code):
    """{call name: role} for every name on the card that maps to exactly one
    role: what the Add call form pre-selects from (§5.3)."""
    cfg = pricing_rules.cfg_for_set(rules, set_code) if rules else None
    if not cfg:
        return {}
    idx = estimator_calc.call_name_index(cfg)
    active = set(roles_for_set(rules, set_code))
    return {n: next(iter(rs)) for n, rs in idx.items() if len(rs) == 1 and next(iter(rs)) in active}


def effective_call_type(call_name, set_code, rules, stored):
    """§4.1. {role, source, status} for one call.

    stored  the call's call_type row from the edge function, or None.

      stored, on the card       -> stored role, its source, ok
      stored, NOT on the card   -> stored role, its source, off_card (the card
                                   changed afterwards; an Admin picks again,
                                   nothing is rewritten silently)
      no row, name resolves     -> the match, "derived", ok
      no row, nothing matches   -> None, None, not_set

    With the rules unavailable a stored row can't be checked against the card,
    so it is shown as stored (ok) rather than called off-card on no evidence;
    a call with no row is not_set."""
    card = roles_for_set(rules, set_code) if rules and set_code else None
    if stored and str(stored.get("role_name") or "").strip():
        role = str(stored["role_name"]).strip()
        status = OK if (card is None or role in card) else OFF_CARD
        return {"role": role, "source": stored.get("source"), "status": status,
                "set_by_user_id": stored.get("set_by_user_id"), "set_at": stored.get("set_at")}
    role = derive_role(call_name, rules, set_code) if card is not None else None
    if role:
        return {"role": role, "source": DERIVED, "status": OK}
    return {"role": None, "source": None, "status": NOT_SET}


def crew_type_errors(lines, rules, set_code, label):
    """§5.2: every line's crew_type must be a role on the booking's card. For
    a file line the Estimator guarantees it, so a miss is an error in the
    preview, never a guess. An operator-added line (line_id "new-N") carries
    the Call Type picked for it in the preview, and must have one.

    Returns (errors, warning). With the rules unavailable the check can't run;
    the warning says so and the caller does not write the types."""
    if rules is None:
        return [], ("The calls' types couldn't be checked: the Estimator's "
                    "pricing rules are unavailable. The calls will show a type "
                    "from their call names.")
    card = roles_for_set(rules, set_code)
    errs = []
    for ll in lines or []:
        lid = str(ll.get("line_id") or "?")
        added = lid.startswith("new-")
        ct = str(ll.get("crew_type") or "").strip()
        if not ct:
            errs.append(f"Added call ({ll.get('call_name') or 'no name'}): pick a Call Type."
                        if added else f"Line {lid}: no crew_type in the estimate.")
        elif ct not in card:
            errs.append(f"Added call ({ll.get('call_name') or 'no name'}): '{ct}' isn't on the {label} card."
                        if added else f"Line {lid}: crew type '{ct}' isn't on the {label} card.")
    return errs, None


def same_override(existing, kind, charge_role, pay_paygrade_id):
    """True when a row's stored override already says exactly this, so saving
    it again would change nothing and must not write or log."""
    if not existing:
        return False
    pay = existing.get("pay_paygrade_id")
    return (existing.get("kind") == kind
            and (existing.get("charge_role") or None) == (charge_role or None)
            and (None if pay is None else int(pay)) == pay_paygrade_id)


def same_call_type(stored, role_name, set_code):
    """True when the call's stored type is already this role on this card."""
    return bool(stored) and stored.get("role_name") == role_name and stored.get("set_code") == set_code


def find_crew_boss_grade(paygrades):
    """(grade, error) from get-call-times.php's paygrades list, matched by
    name, never by a hard-coded ID (§6.3). grade is {id, name, rate}."""
    hits = [p for p in (paygrades or [])
            if str(p.get("day_desc") or "").strip().lower() == CREW_BOSS_GRADE_NAME.lower()]
    if not hits:
        return None, "No Crew Boss pay grade in SmartStaff"
    if len(hits) > 1:
        return None, "More than one Crew Boss pay grade in SmartStaff"
    p = hits[0]
    return {"id": int(p.get("id")), "name": str(p.get("day_desc") or "").strip(),
            "rate": p.get("rate")}, None


def crew_boss_allowed(set_code):
    """Make Crew Boss is not offered on M&OP bookings (S2-1)."""
    return set_code == pricing_rules.STANDARD


def created_call_ids(n, result):
    """Map create-booking.php's {call_ids, call_errors} back to one entry per
    submitted call: its call ID, or None where that index failed. call_ids
    holds the successful inserts in order (same rule the import uses)."""
    failed = {e.get("index") for e in (result or {}).get("call_errors") or []}
    ids = iter((result or {}).get("call_ids") or [])
    return [None if i in failed else next(ids, None) for i in range(n)]


def as_call_id(raw):
    """A real SmartStaff call ID, or None. ss_create_call can return the
    string "created" when SmartStaff redirects without naming the call."""
    try:
        v = int(str(raw).strip())
    except (TypeError, ValueError):
        return None
    return v if v > 0 else None
