"""Build eval-real-abcd.jsonl from ABCD (ASAPP, MIT): human-written e-commerce support chats.

The label is the scenario the writer was given (flow/subflow), mapped to the demo task's category.
ABCD has no urgency or needs_human, so only category is labelled (the worker scores only the fields
present in the expected output). Only the dev and test splits are used.

Usage: python3 -I build_abcd.py <abcd_v1.1.json.gz> <out.jsonl> [per_class=50] [splits=dev,test]
(The train split is only for training data, R2; dev and test are the eval.)
Source: https://github.com/asappresearch/abcd (data/abcd_v1.1.json.gz)
"""
import gzip
import json
import random
import re
import sys

# subflow (or flow) -> category; anything not listed is dropped as ambiguous for this task
MAP = {
    "shipping_issue": "shipping",
    "status_delivery_time": "shipping", "status_delivery_date": "shipping",
    "manage_cancel": "orders", "manage_create": "orders", "status_quantity": "orders",
    "status_mystery_fee": "payments", "status_payment_method": "payments",
    "product_defect": "returns",
    "promo_code_invalid": "payments", "promo_code_out_of_date": "payments",
    "bad_price_competitor": "payments", "bad_price_yesterday": "payments",
    "mistimed_billing_never_bought": "payments",
    "single_item_query": "product_questions",
    "account_access": "other",
    "slow_speed": "other", "search_results": "other", "shopping_cart": "other",
    "manage_change_address": "other", "manage_change_name": "other", "manage_change_phone": "other",
    "manage_payment_method": "payments", "status_credit_missing": "payments",
    "manage_dispute_bill": "payments", "manage_pay_bill": "payments", "status_due_amount": "payments",
}
# order matters: subflow-level entries win over flow-level ones
FLOW_LEVEL = {"shipping_issue", "product_defect", "single_item_query", "account_access"}
# read two ways under this task's guidelines: shipping fee (shipping or payments), shipping-speed
# upgrades (orders or shipping), a refund missing after a return (returns or payments)
DROP = {"cost", "manage_upgrade", "manage_downgrade", "mistimed_billing_already_returned"}

INTRO = re.compile(r"(hi|hello|hey|good (morning|afternoon|evening))[.!,]*|my name is [^.?!]*[.?!]?|"
                   r"(my )?(order|account|user)\s*id( number)? is [^.?!]*[.?!]?|i'?m [a-z]+ [a-z]+[.!]", re.I)


def category(scn: dict) -> str | None:
    if scn["subflow"] in DROP:
        return None
    if scn["subflow"] in MAP and scn["flow"] not in FLOW_LEVEL:
        return MAP[scn["subflow"]]
    return MAP.get(scn["flow"]) if scn["flow"] in FLOW_LEVEL else None


def opening(convo: dict) -> str:
    """The customer's words before the agent does anything (first action) or answers a real request."""
    out = []
    for speaker, text in convo["original"]:
        if speaker == "action":
            break
        if speaker == "customer":
            out.append(text.strip())
        elif out and len(INTRO.sub(" ", " ".join(out)).split()) >= 6:
            break
    return re.sub(r"\s+", " ", " ".join(out)).strip()


def main(src: str, dst: str, per_class: int = 50, splits: str = "dev,test") -> None:
    data = json.load(gzip.open(src))
    pool: dict[str, list[dict]] = {}
    seen = set()
    for split in splits.split(","):
        for c in data[split]:
            cat = category(c["scenario"])
            text = opening(c)
            if not cat or len(INTRO.sub(" ", text).split()) < 6 or text.lower() in seen:
                continue
            seen.add(text.lower())
            pool.setdefault(cat, []).append({"input": text, "output": {"category": cat}, "source": "abcd",
                                             "origin_id": f"abcd-{split}-{c['convo_id']}",
                                             "abcd_flow": f"{c['scenario']['flow']}/{c['scenario']['subflow']}"})
    rng = random.Random(20261014)
    rows = []
    for cat in sorted(pool):
        rows += rng.sample(pool[cat], min(per_class, len(pool[cat])))
    rng.shuffle(rows)
    with open(dst, "w") as f:
        for i, r in enumerate(rows, 1):
            f.write(json.dumps({"id": f"abcd-{'-'.join(splits.split(','))}-{i:04d}" if splits != "dev,test" else f"abcd-{i:04d}", **r}, ensure_ascii=False) + "\n")
    print(json.dumps({"n": len(rows), "pool": {k: len(v) for k, v in sorted(pool.items())},
                      "per_class": {k: min(per_class, len(v)) for k, v in sorted(pool.items())}}))


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2], *(int(a) for a in sys.argv[3:4]), *sys.argv[4:5])
