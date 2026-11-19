#!/usr/bin/env python3
"""generate-invoice.php's own invariants, read out of the file.

BRIEF-accounting-slice-b.md §7 ("PHP (static)"). These cannot be tested by
running anything: the file needs global.php, a SmartStaff session and a
MariaDB. What CAN be checked on this machine is the shape it relies on, and
every item below is a bug that has actually bitten this codebase before.

THE ONE THAT MATTERS: a named lock that is not released. GET_LOCK holds until
the connection closes, so a Generate that die()s on a path with no
RELEASE_LOCK would leave the booking refusing `busy` for as long as PHP keeps
the connection pooled. The file is written with exactly two exits, both of
which release, plus a shutdown function — and this test asserts there is no
third way out.

Run:  python3 test_accounting_generate_php.py
"""
import io
import os
import re
import shutil
import subprocess
import unittest

HERE  = os.path.dirname(os.path.abspath(__file__))
SS    = os.path.join(HERE, "smartstaff")
PHP   = shutil.which("php")

GEN   = os.path.join(SS, "generate-invoice.php")
LIST  = os.path.join(SS, "list-ready-to-invoice.php")
LIB   = os.path.join(SS, "accounting-ready-lib.php")
SLICE_A = [os.path.join(SS, f) for f in ("list-invoices.php", "get-invoice.php")]

ENDPOINTS = [GEN, LIST]
ALL_NEW   = [GEN, LIST, LIB]


def read(path):
    with io.open(path, encoding="utf-8") as fh:
        return fh.read()


def strip_comments(src):
    """The source without /* ... */ blocks or // lines.

    The file's comments talk ABOUT die() and RELEASE_LOCK at length, so every
    grep below would match prose rather than code without this.
    """
    src = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
    return re.sub(r"(?m)//.*$", "", src)


class TheLockIsAlwaysReleased(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        cls.src  = read(GEN)
        cls.code = strip_comments(cls.src)

    def test_the_lock_is_taken_with_get_lock_and_a_wait(self):
        self.assertIn("GET_LOCK(", self.code)
        self.assertRegex(self.code, r"\$GOAT_GEN_LOCK_WAIT\s*=\s*10")

    def test_only_an_exact_one_counts_as_holding_it(self):
        """0 is a timeout and NULL is an error; both mean someone else may be
        mid-write, so neither may proceed."""
        self.assertRegex(self.code, r"\(int\)\s*\$got\s*!==\s*1")
        self.assertIn("'busy'", self.code)

    def test_there_is_a_release_function_and_a_shutdown_hook(self):
        self.assertIn("function gen_release_lock()", self.code)
        self.assertIn("register_shutdown_function('gen_release_lock')", self.code)
        self.assertIn("RELEASE_LOCK(", self.code)

    def test_every_die_is_inside_gen_fail_or_gen_ok(self):
        """THE INVARIANT. Both of those release the lock first, so if no other
        function can die(), no exit can skip the release.

        Brace-matched rather than grepped by line, because a die() added to a
        new helper three months from now is exactly the regression this is
        here to catch.
        """
        spans = {}
        for name in ("gen_fail", "gen_ok"):
            i = self.code.index("function %s(" % name)
            j = self.code.index("{", i)
            depth, k = 0, j
            while k < len(self.code):
                if self.code[k] == "{":
                    depth += 1
                elif self.code[k] == "}":
                    depth -= 1
                    if depth == 0:
                        break
                k += 1
            spans[name] = (i, k)

        stray = []
        for m in re.finditer(r"\b(?:die|exit)\s*\(", self.code):
            at = m.start()
            if not any(a <= at <= b for a, b in spans.values()):
                line = self.code[:at].count("\n") + 1
                stray.append((line, self.code[at:at + 60]))

        self.assertEqual(stray, [], "a die()/exit() outside gen_fail/gen_ok "
                                   "would skip the lock release: %r" % (stray,))

    def test_gen_fail_and_gen_ok_both_release_before_they_die(self):
        for name in ("gen_fail", "gen_ok"):
            i = self.code.index("function %s(" % name)
            body = self.code[i:i + 700]
            rel  = body.index("gen_release_lock()")
            die  = body.index("die(")
            self.assertLess(rel, die, name)

    def test_the_lock_name_carries_the_database(self):
        """Test and prod share one MariaDB server and named locks are
        server-wide (callout.php l. 31), so an unqualified name would let a
        test Generate make a real one wait."""
        self.assertIn("SELECT DATABASE()", self.code)
        self.assertRegex(self.code, r"'goat_gen_inv_'\s*\.\s*\$dbName")

    def test_the_lock_name_is_cut_to_fit(self):
        """MariaDB lock names are limited to 64 characters."""
        self.assertRegex(self.code, r"substr\(preg_replace\(.*?,\s*\$dbName\),\s*0,\s*30\)")


class TheGuardsAreInThisOrder(unittest.TestCase):
    """§3.2: lock, re-read, note the max id, write, read back. Out of order,
    each guard stops working: re-reading BEFORE the lock would let two
    requests both pass it."""

    @classmethod
    def setUpClass(cls):
        cls.code = strip_comments(read(GEN))

    def at(self, needle):
        return self.code.index(needle)

    def test_the_lock_comes_before_the_state_is_read(self):
        self.assertLess(self.at("GET_LOCK("), self.at("goat_ready_scan("))

    def test_the_state_is_read_before_the_write(self):
        self.assertLess(self.at("goat_ready_scan("),
                        self.at("generateAccountingData("))

    def test_the_max_invoice_id_is_noted_before_the_write(self):
        self.assertLess(self.at("COALESCE(MAX(id), 0)"),
                        self.at("generateAccountingData("))

    def test_the_read_back_comes_after_the_write(self):
        self.assertLess(self.at("generateAccountingData("),
                        self.at("'new invoice'"))

    def test_post_only(self):
        self.assertRegex(self.code, r"\$method\s*!==\s*'POST'")
        self.assertIn("405", self.code)

    def test_both_inputs_are_validated_before_the_database_is_touched(self):
        self.assertLess(self.at("[0-9a-f]{40}"), self.at("goat_ready_connect("))
        self.assertIn("'bad_booking_id'", self.code)
        self.assertIn("'bad_fingerprint'", self.code)

    def test_success_is_never_assumed(self):
        """The class returns nothing and reports nothing, so `ok` is decided by
        the read-back alone."""
        self.assertRegex(self.code, r"'ok'\s*=>\s*\(count\(\$failed\) < 1\)")

    def test_the_class_is_checked_for_before_it_is_called(self):
        """No ajax/crew endpoint has ever used a legacy class, so whether
        global.php loads it under SS_NO_SMARTY is checked, not assumed."""
        self.assertLess(self.at("class_exists('accounting')"),
                        self.at("accounting::getInstance()"))
        self.assertIn("'class_unavailable'", self.code)

    def test_exactly_one_new_invoice_row_is_required(self):
        self.assertIn("new invoice rows were created, not one", self.code)

    def test_the_money_check_is_skipped_when_the_prediction_is_not_exact(self):
        """A false alarm on an invoice that cannot be rolled back is worse
        than no check: mixed crew groups and NULL `calculated` make the
        class's own figure unpredictable in SQL."""
        self.assertRegex(self.code, r"\$expectedExact\s*=")
        self.assertRegex(self.code, r"&&\s*\$expectedExact")
        self.assertIn("'money_checked'", self.code)

    def test_one_log_line_per_generate(self):
        self.assertRegex(self.code, r"error_log\('generate-invoice: booking '")


class HouseRules(unittest.TestCase):
    """The same checks every new ajax/crew file in this repo gets."""

    def test_global_php_is_included_exactly_once_in_each_endpoint(self):
        for p in ENDPOINTS:
            self.assertEqual(read(p).count("include('../../global.php')"), 1,
                             os.path.basename(p))

    def test_the_lib_does_not_include_global_php(self):
        """It is included BY the endpoints; a second include would run
        global.php twice."""
        self.assertEqual(read(LIB).count("include('../../global.php')"), 0)

    def test_ss_no_smarty_is_defined_above_the_include(self):
        for p in ENDPOINTS:
            src = read(p)
            self.assertLess(src.index("define('SS_NO_SMARTY', true)"),
                            src.index("include('../../global.php')"),
                            os.path.basename(p))

    def test_the_cohort_and_the_user_are_both_checked(self):
        """Two steps, as get-performance-cost.php does: an admin logged
        straight into SmartStaff could otherwise open the URL in a browser."""
        for p in ENDPOINTS:
            code = strip_comments(read(p))
            self.assertIn("goat_user_cohort() !== 'admin'", code, os.path.basename(p))
            self.assertIn("$GOAT_ACCOUNTING_USERS", code, os.path.basename(p))
            self.assertIn("'Not enabled for this account'", code, os.path.basename(p))

    def test_the_gate_list_is_the_same_in_all_five_places(self):
        """ACCOUNTING_USERS in app.py plus four PHP files. Widening one and
        forgetting another is the whole reason this test exists."""
        import app as A
        want = sorted(A.ACCOUNTING_USERS)
        for p in ENDPOINTS + SLICE_A:
            m = re.search(r"\$GOAT_ACCOUNTING_USERS\s*=\s*array\(([^)]*)\)", read(p))
            self.assertIsNotNone(m, os.path.basename(p))
            got = sorted(int(x) for x in m.group(1).split(",") if x.strip())
            self.assertEqual(got, want, os.path.basename(p))

    def test_each_file_says_the_sets_must_change_together(self):
        for p in ENDPOINTS + SLICE_A:
            self.assertIn("change both together", read(p), os.path.basename(p))

    def test_no_php_7_only_syntax(self):
        """The server is PHP 5.6; the local php is 8.x and would accept all of
        this quietly."""
        for p in ALL_NEW:
            code = strip_comments(read(p))
            name = os.path.basename(p)
            self.assertNotIn("??", code, name)
            self.assertNotIn("<=>", code.replace("g.vmin <=> g.vmax", ""), name)
            self.assertNotRegex(code, r"=>\s*\[", name)
            self.assertNotRegex(code, r"=\s*\[\s*\]", name)
            self.assertNotIn("?->", code, name)
            self.assertNotIn("str_contains", code, name)

    def test_tabs_not_spaces(self):
        for p in ALL_NEW:
            lines = [l for l in read(p).split("\n")
                     if l.startswith("    ") and not l.startswith("\t")]
            self.assertEqual(lines, [], os.path.basename(p))

    def test_the_read_only_endpoint_writes_nothing(self):
        """list-ready-to-invoice.php and the lib are READS. One stray UPDATE
        in the lib would be run by BOTH endpoints."""
        for p in (LIST, LIB):
            code = strip_comments(read(p)).upper()
            # Word-boundaried: the JSON key `truncated` is not a TRUNCATE.
            for verb in (r"INSERT\s+INTO", r"\bUPDATE\b", r"DELETE\s+FROM",
                         r"REPLACE\s+INTO", r"\bTRUNCATE\b", r"\bDROP\b",
                         r"\bGET_LOCK\b"):
                self.assertIsNone(re.search(verb, code),
                                  (os.path.basename(p), verb))

    def test_the_sums_are_not_cast_to_float_in_php(self):
        """fix 1: mysqli returns a DECIMAL as a string, and (float) would put
        it into a double where json_encode could print 32783.950000000004."""
        code = strip_comments(read(LIB))
        self.assertIn("(string) $row['expected_charge']", code)
        self.assertNotRegex(code, r"\(float\)\s*\$row\['expected_charge'\]")

    # The columns and JSON fields whose values started life as a single-
    # precision FLOAT or a JSON double. Multiplying two of these inside SQL is
    # what put invoice #34334 a cent out (fix 1); an integer qty or a COUNT(*)
    # times an already-DECIMAL expression is exact and needs no cast.
    FLOATY = (r"(?:jt\.t|jt\.co|chargeout_rate|hours|flatrate"
              r"|day_chargeout|day_hours)")

    def test_no_float_column_is_ever_a_bare_factor(self):
        """The other half of fix 1. A bare `jt.t * jt.co` would accumulate
        single-precision error over a booking's worth of lines, so every
        float-origin value is CAST to DECIMAL before it is multiplied."""
        for p in (LIB, GEN):
            code = strip_comments(read(p))
            name = os.path.basename(p)
            for pat in (self.FLOATY + r"\s*\*", r"\*\s*" + self.FLOATY):
                for m in re.finditer(pat, code):
                    self.fail("%s: %r is multiplied without a CAST (near %r)"
                              % (name, m.group(0),
                                 code[max(0, m.start() - 60):m.end() + 20]))

    def test_the_casts_are_actually_there(self):
        """Otherwise the test above would pass on a file with no maths at
        all."""
        self.assertIn("CAST(jt.t AS DECIMAL(14,6))", read(LIB))
        self.assertIn("CAST(jt.co AS DECIMAL(12,4))", read(LIB))
        self.assertIn("CAST(chargeout_rate AS DECIMAL(12,4))", read(GEN))
        self.assertIn("CAST(flatrate AS DECIMAL(12,4))", read(GEN))

    @unittest.skipUnless(PHP, "php not installed")
    def test_every_file_lints(self):
        for p in ALL_NEW:
            out = subprocess.run([PHP, "-l", p], capture_output=True, text=True)
            self.assertEqual(out.returncode, 0,
                             "%s: %s" % (os.path.basename(p), out.stdout))


class ThePredictionMirrorsTheClass(unittest.TestCase):
    """accounting-ready-lib.php exists to predict generateAccountingData().
    These assert it still quotes the conditions it is copying, so a change to
    the class shows up as a failing test rather than as a wrong invoice."""

    @classmethod
    def setUpClass(cls):
        cls.lib   = read(LIB)
        cls.code  = strip_comments(cls.lib)
        cls.klass = read(os.path.join(SS, "classes", "class.accounting.php"))

    def test_the_crew_status_condition_is_the_classs_own(self):
        """l. 406 / l. 440: confirmed crew, and cancellations still paid or
        charged. Compared with whitespace removed, so formatting does not
        matter but the logic does."""
        squash = lambda s: re.sub(r"\s+", "", s)
        want = squash("(call_crew_map.status=5 OR (call_crew_map.status=9 AND "
                      "call_crew_map.prev_status=5 AND (call_crew_map.cancel_pay=1 "
                      "OR call_crew_map.cancel_charge=1)))")
        self.assertIn(want, squash(self.klass),
                      "the class's condition changed — re-read l. 406")
        got = squash("(m.status = 5 OR (m.status = 9 AND m.prev_status = 5 AND "
                     "(m.cancel_pay = 1 OR m.cancel_charge = 1)))")
        self.assertIn(got, squash(self.code))

    def test_the_grouping_key_is_the_classs_own(self):
        """l. 408. If the class ever adds a column here, qty changes and the
        expected amount with it."""
        squash = lambda s: re.sub(r"\s+", "", s)
        self.assertIn(squash("GROUP BY accounting.callID, call_crew_map.on, "
                             "call_crew_map.off, CONCAT(call_crew_map.on, "
                             "call_crew_map.off, call_crew_map.break, "
                             "call_crew_map.break_night), accounting.day_hours, "
                             "accounting.day_chargeout"),
                      squash(self.klass),
                      "the class's GROUP BY changed — re-read l. 408")
        self.assertIn(squash("GROUP BY r.callID, r.m_on, r.m_off, CONCAT(r.m_on, "
                             "r.m_off, r.m_break, r.m_break_night), r.day_hours, "
                             "r.day_chargeout"),
                      squash(self.code))

    def test_the_call_list_rule_is_locked_and_uninvoiced(self):
        """l. 366: call_locked = 1 AND invoiceID = 0 — NOT invoice_generated."""
        self.assertIn("call_locked=1 AND invoiceID = 0", self.klass)

    def test_the_lines_query_still_does_not_filter_on_invoiceid(self):
        """THE REASON A STUCK CALL BLOCKS ITS BOOKING. If the class ever starts
        filtering on invoiceID, this slice is over-cautious and the blocked
        path can go."""
        i = self.klass.index("calls.invoice_generated=0 AND calls.call_locked=1")
        self.assertNotIn("invoiceID", self.klass[i:i + 400])

    def test_the_lib_names_the_lines_it_copied_from(self):
        for ref in ("l. 366", "l. 406", "l. 408", "l. 604"):
            self.assertIn(ref, self.lib, ref)

    def test_the_legacy_null_calculated_branch_is_accounted_for(self):
        """The class re-prices day/night hours in PHP and substitutes Sunday
        and public-holiday rates when `calculated` is NULL, which SQL cannot
        reproduce — so those rows are counted, not priced."""
        self.assertIn("null_calc", self.code)
        self.assertIn("expected_exact", self.code)
        self.assertIn("is_pubhol", self.klass)


if __name__ == "__main__":
    unittest.main(verbosity=2)
