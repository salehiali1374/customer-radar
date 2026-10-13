#!/usr/bin/env python3
"""
PROVENANCE ONLY - the PHP tests read the generated JSON files and never run this script.
Regenerate (and review the diff!) with:   cd tests/analytics/fixtures && python3 generate_reference.py

Synthetic reference dataset for the Customer Radar analytics audit.
No real data. Site timezone: Asia/Tehran (UTC+3:30, no DST since 2022). Currency: IRT (whole numbers).
As-of ("now") for recency: 2026-10-10 12:00 Asia/Tehran.

Writes dataset.json (the orders, as WooCommerce would hold them) and expected.json (answers computed here, from first
principles, WITHOUT using any plugin code).
"""
import json, math, re, statistics
from datetime import datetime
from zoneinfo import ZoneInfo

TZ = ZoneInfo("Asia/Tehran")
ASOF = int(datetime(2026, 10, 10, 12, 0, 0, tzinfo=TZ).timestamp())
DAY = 86400


def ts(s):
    return int(datetime.strptime(s, "%Y-%m-%d %H:%M:%S").replace(tzinfo=TZ).timestamp())


# id, status, local time, uid, phone, email, [(product, line_total_after_discount_excl_tax)], shipping, tax, total, refunded, note
O = [
    (1,  "completed",  "2026-06-05 11:00:00", 101, "0912 000 0001",  "u1@example.test", [(1, 800000), (2, 200000)], 0,     0,     1000000, 0,      "registered repeat buyer, order 1"),
    (2,  "completed",  "2026-07-20 15:00:00", 101, "09120000001",    "u1@example.test", [(1, 500000)],             50000, 45000, 595000,  0,      "same buyer; order WITH shipping and tax"),
    (3,  "processing", "2026-09-22 23:59:59", 101, "+98 912 000 0001", "u1@example.test", [(3, 300000)],            0,     27000, 327000,  0,      "same buyer; last second of Shahrivar 1405 (period boundary)"),
    (4,  "completed",  "2026-09-23 00:00:00", 0,   "09350000002",    "g1@example.test", [(2, 400000), (3, 100000)], 40000, 0,     540000,  0,      "guest, shipping; first second of Mehr 1405 (period boundary)"),
    (5,  "completed",  "2026-08-01 12:00:00", 102, "09120000002",    "u2@example.test", [(1, 1000000)],            0,     0,     1000000, 400000, "registered, PARTIALLY refunded (400,000)"),
    (6,  "refunded",   "2026-08-15 12:00:00", 0,   "09120000003",    "u3@example.test", [(1, 700000)],             0,     0,     700000,  700000, "FULLY refunded, status refunded"),
    (7,  "on-hold",    "2026-09-01 10:00:00", 0,   "09120000004",    "u4@example.test", [(2, 250000)],             0,     0,     250000,  0,      "on-hold"),
    (8,  "cancelled",  "2026-09-02 10:00:00", 0,   "09120000004",    "u4@example.test", [(3, 300000)],             0,     0,     300000,  0,      "cancelled"),
    (9,  "failed",     "2026-09-03 10:00:00", 0,   "09120000004",    "u4@example.test", [(1, 150000)],             0,     0,     150000,  0,      "failed"),
    (10, "pending",    "2026-09-04 10:00:00", 0,   "09120000004",    "u4@example.test", [(2, 100000)],             0,     0,     100000,  0,      "pending payment"),
    (11, "completed",  "2026-04-01 10:00:00", 0,   "0912-345-6789",  "g2@example.test", [(2, 300000)],             0,     0,     300000,  0,      "guest G2, phone with dashes"),
    (12, "completed",  "2026-08-30 10:00:00", 0,   "",               "G2@Example.test", [(3, 200000)],             0,     0,     200000,  0,      "SAME person G2, phone left empty (email only)"),
    (13, "processing", "2026-10-01 10:00:00", 0,   "09351234567",    "g2@example.test", [(1, 250000)],             0,     0,     250000,  0,      "SAME person G2, NEW phone number"),
    (14, "completed",  "2026-03-10 09:00:00", 0,   "00000000000",    "deleted@site.invalid", [(2, 120000)],        0,     0,     120000,  0,      "personal data ERASED by WooCommerce privacy tool"),
    (15, "completed",  "2026-05-12 09:00:00", 0,   "00000000000",    "deleted@site.invalid", [(3, 130000)],        0,     0,     130000,  0,      "ANOTHER erased order (a different, unknown person)"),
    (16, "completed",  "2026-03-01 14:00:00", 0,   "09120000005",    "u5@example.test", [(1, 450000)],             0,     0,     450000,  0,      "single-order buyer, never returned"),
    (17, "completed",  "2026-09-10 12:00:00", 0,   "09120000006",    "u6@example.test", [(2, 200000)],             0,     0,     200000,  200000, "FULLY refunded but status still completed (possible via the woocommerce_order_fully_refunded_status filter)"),
    (18, "completed",  "2026-09-15 10:00:00", 0,   "abc",            "not-an-email",    [(1, 100000)],             0,     0,     100000,  0,      "malformed billing data, guest"),
]
COUNTED = {"completed", "processing"}  # plugin default setting 'statuses'

orders = [dict(id=o[0], status=o[1], local=o[2], ts=ts(o[2]), uid=o[3], phone=o[4], email=o[5], items=o[6],
               shipping=o[7], tax=o[8], total=o[9], refunded=o[10], note=o[11]) for o in O]
for o in orders:  # sanity: total = items + shipping + tax
    assert o["total"] == sum(t for _, t in o["items"]) + o["shipping"] + o["tax"], o["id"]
json.dump(dict(asof=ASOF, orders=orders), open("reference-dataset.json", "w"), ensure_ascii=False, indent=1)

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


counted = [o for o in orders if o["status"] in COUNTED]
for o in counted:
    o["ckey"] = ckey(o)
    o["net"] = max(0, o["total"] - o["refunded"])          # plugin: total - refunded (incl. shipping and tax)
    o["net_sales"] = sum(t for _, t in o["items"]) - o["refunded"]  # WooCommerce-style: excl. shipping & tax (refund assumed against items)

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
    for p, t in o["items"]:
        x = prod.setdefault(p, dict(orders=0, revenue=0))
        x["orders"] += 1
        x["revenue"] += t
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
    ff = freq(r["orders"])
    mm = score_high(float(r["spent"]), scuts)
    exp["rfm"][k] = dict(days_since=round(d, 3), r=rr, f=ff, m=mm, segment=segment(rr, ff, mm, r["orders"], d))
exp["rfm_cuts"] = dict(recency_days=dcuts, spent=scuts)
# Split: "what the plugin is documented to compute today" vs "what is actually true" (ground truth)
truth = {k: exp.pop(k) for k in list(exp) if k.startswith("truth_")}
truth["note"] = ("Ground truth = what a human would say about the same orders. Defect tests assert THESE values; the "
                 "characterization tests assert reference-expected.json (the plugin's own definitions).")
truth["people_by_order"] = {str(o["id"]): ("G2" if o["id"] in (11, 12, 13) else "ERASED-%d" % o["id"] if o["id"] in (14, 15) else o["ckey"]) for o in counted}
truth["net_sales_excl_tax_shipping"] = exp["net_sales_excl_tax_shipping"]
truth["orders_that_are_real_sales"] = [o["id"] for o in counted if o["net_sales"] > 0]
json.dump(exp, open("reference-expected.json", "w"), ensure_ascii=False, indent=1, default=str)
json.dump(truth, open("reference-ground-truth.json", "w"), ensure_ascii=False, indent=1, default=str)
print("dataset: %d orders (%d counted by default statuses), %d customer keys" % (len(orders), len(counted), len(by_c)))
