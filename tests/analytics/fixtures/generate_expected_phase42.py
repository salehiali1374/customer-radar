#!/usr/bin/env python3
"""
PROVENANCE ONLY - the PHP tests read the generated JSON and never run this script.
Regenerate (and review the diff!) with:   cd tests/analytics/fixtures && python3 generate_expected_phase42.py

Phase 4.2: the expected values under the APPROVED definitions, computed here from first principles WITHOUT any plugin code. It READS
reference-dataset.json (it never writes it, nor reference-ground-truth.json or survival-scenario.json) and writes reference-expected.json.
The version before this phase is kept as reference-expected.before-phase4.2.json.

What changed against generate_reference.py (and why):
  REV-1  an order's value is NET SALES: its product lines after discounts, before tax and shipping, minus refunds. (Before: order total - refunds.)
  REV-2  refunds are applied to the lines. The dataset has order-level refunds only, so they are spread over the lines in proportion to line value
         (whole cents, largest remainder). Product revenue now sums to the same number as order revenue.
  REV-3  an order whose net sales are not positive (fully refunded, free) is not an order and does not make a customer.
  RFM-1  past lost_days (365) a customer's recency score is capped at 2 (no recent label for a stale customer).
Everything else (people, identity rules, months, cohorts, Pareto, quintile cuts) is computed as before, over the valid orders.
"""
import json, math, re, statistics
from datetime import datetime
from zoneinfo import ZoneInfo

TZ = ZoneInfo("Asia/Tehran")
ASOF = int(datetime(2026, 10, 10, 12, 0, 0, tzinfo=TZ).timestamp())
DAY = 86400


def ts(s):
    return int(datetime.strptime(s, "%Y-%m-%d %H:%M:%S").replace(tzinfo=TZ).timestamp())


DATASET = json.load(open("reference-dataset.json"))  # READ ONLY: the independent audit fixture
ASOF = DATASET["asof"]
orders = DATASET["orders"]
for o in orders:
    o["items"] = [tuple(x) for x in o["items"]]
COUNTED = {"completed", "processing"}  # plugin default setting 'statuses'

# --------------------------------------------------------------------------- expected, "as the plugin is documented to work"


def norm_phone(raw):
    s = re.sub(r"\D+", "", raw)
    if s.startswith("0098"):
        s = "0" + s[4:]
    elif s.startswith("98") and len(s) == 12:
        s = "0" + s[2:]
    elif len(s) == 10 and s.startswith("9"):
        s = "0" + s
    return s if re.fullmatch(r"09\d{9}", s) else ""


def valid_email(e):  # enough for this dataset (WordPress is_email agrees on these values)
    return bool(re.fullmatch(r"[^@\s]+@[^@\s]+\.[A-Za-z]{2,}", e))


def is_placeholder(e):  # PHASE 3A: WordPress / WooCommerce write deleted@site.invalid when they erase a person
    return e == "deleted@site.invalid" or e.split("@")[-1].endswith("invalid")


def ckey(o):
    """The PROVISIONAL key of one order (what the plugin gives it before it joins the same person's orders)."""
    p = norm_phone(o["phone"])
    e = o["email"].strip().lower()
    if p:
        return "p:" + p
    if e and valid_email(e) and not is_placeholder(e):  # a placeholder is "no email"
        return "e:" + e
    if o["uid"]:
        return "u:%d" % o["uid"]
    return "o:%d" % o["id"]


def line_nets(o):
    """REV-2: refunds applied to lines. This dataset has order-level refunds only: spread over the lines in proportion to value,
    whole cents, largest remainder first. Returns net per line (in currency units, as ints/floats)."""
    cents = [int(round(t * 100)) for _, t in o["items"]]
    refund = int(round(o["refunded"] * 100))
    total = sum(cents)
    eligible = min(refund, total)
    share = [eligible * c // total if total else 0 for c in cents]   # floor of the exact share
    rest = eligible - sum(share)
    # largest remainder: remainder of each exact share, ties to the larger line, then the earlier line
    order = sorted(range(len(cents)), key=lambda i: (-((eligible * cents[i]) % total) if total else 0, -cents[i], i))
    for i in order[:rest]:
        share[i] += 1
    return [(c - s) / 100 for c, s in zip(cents, share)]


counted_all = [o for o in orders if o["status"] in COUNTED]
for o in counted_all:
    o["lines"] = line_nets(o)
    o["net"] = sum(o["lines"])                      # REV-1: net sales of the order (product lines minus refunds; no shipping, no tax)
    o["net_sales"] = o["net"]
    o["ckey"] = ckey(o)
void_ids = [o["id"] for o in counted_all if o["net"] <= 0]   # REV-3
counted = [o for o in counted_all if o["net"] > 0]

# Jalali month of a local date, from the official month start dates (independent of the plugin's algorithm)
MONTH_STARTS = [  # (jy, jm, first gregorian day)
    (1404, 12, "2026-02-20"), (1405, 1, "2026-03-21"), (1405, 2, "2026-04-21"), (1405, 3, "2026-05-22"),
    (1405, 4, "2026-06-22"), (1405, 5, "2026-07-23"), (1405, 6, "2026-08-23"), (1405, 7, "2026-09-23"), (1405, 8, "2026-10-23")]


def jmonth(local):
    d = local[:10]
    cur = None
    for jy, jm, start in MONTH_STARTS:
        if d >= start:
            cur = (jy, jm)
    return cur


# PHASE 3A: customers are PEOPLE. The grouping below is the ground truth written down by hand in this file (G2 = orders 11, 12, 13
# are one person; every erased order is its own unknown person; everybody else is identified by the provisional key). It uses no plugin
# code and not the plugin's identity rules: the tests check that the plugin ends up with exactly these people.
def person_of(o):
    if o["id"] in (11, 12, 13):
        return "G2"
    if o["id"] in (14, 15):
        return "ERASED-%d" % o["id"]
    return o["ckey"]


for o in counted:
    o["person"] = person_of(o)
by_c = {}
for o in sorted(counted, key=lambda x: x["ts"]):
    by_c.setdefault(o["person"], []).append(o)

exp = {}
exp["counted_order_ids"] = [o["id"] for o in counted]
exp["orders_total"] = len(counted)
exp["revenue_plugin_total"] = sum(o["net"] for o in counted)
exp["net_sales_excl_tax_shipping"] = sum(o["net_sales"] for o in counted)
exp["shipping_in_counted"] = sum(o["shipping"] for o in counted)
exp["tax_in_counted"] = sum(o["tax"] for o in counted)
exp["aov_plugin_mean"] = exp["revenue_plugin_total"] / len(counted)
exp["aov_net_sales_mean"] = exp["net_sales_excl_tax_shipping"] / len(counted)
exp["customers_total"] = len(by_c)
exp["customers"] = {k: dict(orders=len(v), spent=sum(o["net"] for o in v)) for k, v in by_c.items()}
rep = [k for k, v in by_c.items() if len(v) >= 2]
exp["repeat_customers"] = len(rep)
exp["repeat_customers_pct"] = len(rep) / len(by_c)
exp["repeat_revenue_pct"] = sum(sum(o["net"] for o in by_c[k]) for k in rep) / exp["revenue_plugin_total"]
gaps, second = [], []
for k, v in by_c.items():
    for i in range(1, len(v)):
        gaps.append((v[i]["ts"] - v[i - 1]["ts"]) / DAY)
    if len(v) >= 2:
        second.append((v[1]["ts"] - v[0]["ts"]) / DAY)
exp["gaps_days"] = sorted(gaps)
exp["second_purchase_days"] = sorted(second)
exp["second_median_days"] = statistics.median(second)
exp["gap_median_days"] = statistics.median(gaps)
monthly = {}
for o in counted:
    m = jmonth(o["local"])
    x = monthly.setdefault(m, dict(orders=0, revenue=0, new_rev=0, ret_rev=0))
    x["orders"] += 1
    x["revenue"] += o["net"]
for k, v in by_c.items():
    for i, o in enumerate(v):
        x = monthly[jmonth(o["local"])]
        x["new_rev" if i == 0 else "ret_rev"] += o["net"]
exp["monthly"] = {"%d-%02d" % k: v for k, v in sorted(monthly.items())}
# cohort retention: first-order month -> share of that cohort with an order in month +k, only up to the LAST month that has
# data (cells after it depend on today's date, so they are not pinned)
_order = [(1404, 12), (1405, 1), (1405, 2), (1405, 3), (1405, 4), (1405, 5), (1405, 6), (1405, 7)]
_idx = {m: i for i, m in enumerate(_order)}
_coh = {}
for _k, _v in by_c.items():
    _ms = sorted({jmonth(o["local"]) for o in _v})
    _coh.setdefault(_ms[0], []).append({_idx[m] for m in _ms})
exp["cohorts"] = {"%d-%02d" % f: dict(size=len(mem), ret=[round(100.0 * sum(1 for a in mem if _idx[f] + j in a) / len(mem), 1) for j in range(0, len(_order) - _idx[f])]) for f, mem in sorted(_coh.items())}
spent = sorted((c["spent"] for c in exp["customers"].values()), reverse=True)
exp["pareto_top10"] = spent[:max(1, math.ceil(len(spent) * 0.10))] and sum(spent[:max(1, math.ceil(len(spent) * 0.10))]) / sum(spent)
exp["pareto_top20"] = sum(spent[:max(1, math.ceil(len(spent) * 0.20))]) / sum(spent)
exp["pareto_top10_count"] = max(1, math.ceil(len(spent) * 0.10))
prod = {}
for o in counted:
    for (p, _), net in zip(o["items"], o["lines"]):
        x = prod.setdefault(p, dict(orders=0, revenue=0))
        x["orders"] += 1
        x["revenue"] += net
exp["product_revenue_plugin"] = prod
exp["product_revenue_total"] = sum(v["revenue"] for v in prod.values())

# ground truth people (what a human would say): G2's three keys are one person, erased orders are two unknown people,
# O17 is a full refund (no sale), O18 is a person we cannot identify (own key)
people = {}
for o in counted:
    if o["id"] in (11, 12, 13):
        key = "G2"
    elif o["id"] in (14, 15):
        key = "ERASED-%d" % o["id"]
    else:
        key = o["ckey"]
    people.setdefault(key, []).append(o)
exp["truth_people"] = len(people)
exp["truth_repeaters"] = sorted(k for k, v in people.items() if len(v) >= 2)
exp["truth_repeat_pct"] = len(exp["truth_repeaters"]) / len(people)
exp["truth_repeat_revenue_pct"] = sum(sum(o["net"] for o in people[k]) for k in exp["truth_repeaters"]) / exp["revenue_plugin_total"]

# --- RFM, re-implemented from the documented rules
def cutoffs(vals):
    s = sorted(vals)
    n = len(s)
    return [s[int(math.floor(k * (n - 1) / 5))] for k in range(1, 5)]


def score_high(v, cuts):
    if cuts[0] == cuts[3]:
        return 3
    return min(5, 1 + sum(1 for c in cuts if v > c))


def freq(n):
    return 1 if n <= 1 else 2 if n == 2 else 3 if n == 3 else 4 if n <= 5 else 5


def segment(r, f, m, orders, days_since, new_days=30, lost_days=365):
    if days_since > lost_days:   # RFM-1: nobody past lost_days counts as recent
        r = min(r, 2)
    if orders <= 1 and days_since <= new_days:
        return "new"
    if r >= 4 and f >= 4:
        return "champion"
    if r >= 3 and f >= 3:
        return "loyal"
    if r >= 4 and f <= 2:
        return "potential"
    if r <= 2 and (f >= 4 or (f >= 3 and m >= 4)):
        return "cant_lose"
    if r <= 2 and f >= 3:
        return "at_risk"
    if r == 3 and f <= 2:
        return "need_attention"
    if r <= 2 and f <= 2:
        return "lost" if (orders <= 1 and days_since > lost_days) else "hibernating"
    return "need_attention"


rows = {}
for k, v in by_c.items():
    rows[k] = dict(orders=len(v), spent=sum(o["net"] for o in v), first=v[0]["ts"], last=v[-1]["ts"])
days = [max(0, (ASOF - r["last"]) / DAY) for r in rows.values()]
dcuts = cutoffs(days)
scuts = cutoffs([r["spent"] for r in rows.values()])
neg = sorted(-c for c in dcuts)
exp["rfm"] = {}
for k, r in rows.items():
    d = max(0, (ASOF - r["last"]) / DAY)
    rr = score_high(-d, neg)
    if d > 365:   # RFM-1: the stored R agrees with the label
        rr = min(rr, 2)
    ff = freq(r["orders"])
    mm = score_high(float(r["spent"]), scuts)
    exp["rfm"][k] = dict(days_since=round(d, 3), r=rr, f=ff, m=mm, segment=segment(rr, ff, mm, r["orders"], d))
exp["rfm_cuts"] = dict(recency_days=dcuts, spent=scuts)
exp["net_sales_total"] = sum(o["net"] for o in counted)
exp["void_order_ids"] = void_ids
exp["valid_order_ids"] = [o["id"] for o in counted]
# keys kept for the characterization tests (their meaning is the Phase 4.2 definition: net sales)
exp["shipping_in_counted"] = 0
exp["tax_in_counted"] = 0
for k in [k for k in exp if k.startswith("truth_")]:
    exp.pop(k)   # ground truth lives in reference-ground-truth.json, which this script does not touch
json.dump(exp, open("reference-expected.json", "w"), ensure_ascii=False, indent=1, default=str)
print("expected: %d valid orders (%d void: %s), %d customers, net sales %s" % (len(counted), len(void_ids), void_ids, len(by_c), exp["net_sales_total"]))
