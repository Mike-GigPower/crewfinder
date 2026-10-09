"""
Tolerant person-name comparison, shared by the recruitment duplicate guard and
the Drive contract importer.

One definition on purpose: both callers compare a free-text name against
SmartStaff's "Lastname, Firstname" roster, and a fix to one (punctuation, case,
the similarity threshold) has to reach the other. app.py keeps its old
_recruit_* names as aliases of these, so recruitment code is unchanged.
"""

import difflib
import re

# Whole-string similarity at or above this counts as the same name with a typo
# ("Silva"/"Silvo"). Proven against the roster by the recruitment guard.
SIMILAR_RATIO = 0.87


def norm_name(s):
    """Lowercase, drop punctuation, collapse whitespace — for tolerant name
    comparison ("de Silva" == "De  Silva.")."""
    s = re.sub(r"[^a-z0-9 ]+", " ", str(s or "").lower())
    return re.sub(r"\s+", " ", s).strip()


def name_tokens(s):
    """Set of normalised word-tokens in a name. Order-independent, so it matches
    the roster's "Lastname, Firstname" against a "First Last" name."""
    return set(t for t in norm_name(s).split() if t)


def name_similarity(a_tokens, b_tokens):
    """(passes, ratio) for two token sets.

    passes is the recruitment rule: identical token sets, OR two shared tokens,
    OR a sorted-token similarity ratio >= SIMILAR_RATIO. An empty side never
    passes. ratio is returned so callers can rank."""
    if not a_tokens or not b_tokens:
        return False, 0.0
    ratio = difflib.SequenceMatcher(
        None, " ".join(sorted(a_tokens)), " ".join(sorted(b_tokens))).ratio()
    passes = (a_tokens == b_tokens
              or len(a_tokens & b_tokens) >= 2
              or ratio >= SIMILAR_RATIO)
    return passes, ratio
