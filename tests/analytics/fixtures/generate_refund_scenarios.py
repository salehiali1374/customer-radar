#!/usr/bin/env python3
"""
PROVENANCE ONLY - the PHP tests read refund-scenarios.json and never run this script.
Regenerate (and review the diff!) with:   cd tests/analytics/fixtures && python3 generate_refund_scenarios.py

Refund scenarios for CRDR_Sales::allocate() (Phase 4.2, REV-2). Each scenario is an order as WooCommerce would describe it (lines, the refund recorded
against each line, the order's refunded total, the refunded shipping and tax, the order total) and the answer worked out HERE, with exact rational
arithmetic and without any plugin code, from the written rules:

  1. Each line is worth its total (after discount, before tax). A refund recorded against a line takes that much off the line, at most the line.
  2. What is left of the order's refunded amount after the recorded line refunds and the refunded shipping and tax is spread over the lines in
     proportion to what each line is still worth, in whole cents, largest remainder first (ties: the larger line, then the earlier one). It never takes
     more than the lines are still worth.
  3. Whatever is not explained is reported as `gap`, with a reason:
       line_over_refund          recorded line refund beyond the line's value
       components_exceed_total   recorded line + shipping + tax refunds exceed the refunded total
       exceeds_items             refund left after the lines, beyond what the shipping / tax / fees not yet refunded could explain
  4. net = what the lines are worth after both steps. An order with net <= 0 is void.
"""
import json
from fractions import Fraction

S = [
    ("line-level refund on one line",
     [(100000, 0), (200000, 80000), (300000, 0)], 80000, 0, 0, 710000),
    ("order-level refund only (no line chosen): spread in proportion",
     [(100000, 0), (200000, 0), (300000, 0)], 120000, 0, 0, 600000),
    ("recorded line refund plus an unallocated rest",
     [(100000, 50000), (200000, 0), (300000, 0)], 110000, 0, 0, 600000),
    ("a line refunded more than it was worth",
     [(100000, 130000), (200000, 0)], 130000, 0, 0, 300000),
    ("refund made of lines, shipping and tax: only the lines count",
     [(100000, 50000), (200000, 0)], 90000, 25000, 15000, 360000),
    ("amount-only full refund of an order with shipping: the shipping explains the excess, net 0, void",
     [(300000, 0)], 350000, 0, 0, 350000),
    ("refund bigger than the whole order: the excess is unreconciled",
     [(300000, 0)], 600000, 0, 0, 350000),
    ("recorded components add up to more than the refunded total",
     [(100000, 70000), (200000, 0)], 80000, 40000, 0, 300000),
    ("rounding: 100 split over three equal lines, the first line takes the odd cent",
     [(100.00, 0), (100.00, 0), (100.00, 0)], 100.00, 0, 0, 300.00),
    ("cents: 10.55 + 20.45 with 3.00 refunded by amount",
     [(10.55, 0), (20.45, 0)], 3.00, 0, 0, 31.00),
    ("nothing refunded",
     [(100000, 0), (50000, 0)], 0, 0, 0, 150000),
    ("a free order (all lines worth 0)",
     [(0, 0)], 0, 0, 0, 0),
    ("an order with no product lines at all",
     [], 0, 0, 0, 20000),
    ("a refund on an order with no lines is entirely unreconciled",
     [], 5000, 0, 0, 20000),
    ("refund exactly equal to the lines, order has tax: void, no gap",
     [(100000, 0), (100000, 0)], 200000, 0, 0, 218000),
    ("a zero-value line next to a real one",
     [(0, 0), (100000, 0)], 25000, 0, 0, 100000),
]


def c(x):  # to whole cents
    return int(round(Fraction(str(x)) * 100))


def work(lines, r_total, r_ship, r_tax, o_total):
    T = [c(t) for t, _ in lines]
    L = [c(r) for _, r in lines]
    eff = [min(l, t) for l, t in zip(L, T)]
    reasons = {}
    over = sum(l - e for l, e in zip(L, eff))
    if over:
        reasons["line_over_refund"] = over
    V = [t - e for t, e in zip(T, eff)]
    left = c(r_total) - (sum(L) + c(r_ship) + c(r_tax))
    if left < 0:
        reasons["components_exceed_total"] = -left
        left = 0
    sv = sum(V)
    eligible = min(left, sv)
    extra = left - eligible
    if extra:
        capacity = max(0, c(o_total) - sum(T) - c(r_ship) - c(r_tax))
        unexplained = extra - min(extra, capacity)
        if unexplained:
            reasons["exceeds_items"] = unexplained
    share = [0] * len(V)
    if eligible and sv:
        exact = [Fraction(eligible * v, sv) for v in V]
        share = [int(e) for e in exact]           # floor
        give = eligible - sum(share)
        rank = sorted(range(len(V)), key=lambda i: (-(exact[i] - share[i]), -V[i], i))
        for i in rank[:give]:
            share[i] += 1
        share = [min(s, v) for s, v in zip(share, V)]
    nets = [v - s for v, s in zip(V, share)]
    return nets, reasons


out = []
for name, lines, r_total, r_ship, r_tax, o_total in S:
    nets, reasons = work(lines, r_total, r_ship, r_tax, o_total)
    out.append(dict(
        name=name,
        lines=[dict(total=t, refund=r) for t, r in lines],
        refund_total=r_total, refund_shipping=r_ship, refund_tax=r_tax, order_total=o_total,
        expect=dict(lines=[n / 100 for n in nets], net=sum(nets) / 100, gap=sum(reasons.values()) / 100,
                    reasons={k: v / 100 for k, v in reasons.items()}, void=sum(nets) <= 0),
    ))
json.dump(dict(note="Independent worked answers for CRDR_Sales::allocate (see generate_refund_scenarios.py). Amounts in currency units.", scenarios=out),
          open("refund-scenarios.json", "w"), ensure_ascii=False, indent=1)
print("%d scenarios" % len(out))
