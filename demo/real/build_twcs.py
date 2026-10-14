"""Sample real customer tweets to online retailers from "Customer Support on Twitter" (TwCS).

Source: https://www.kaggle.com/datasets/thoughtvector/customer-support-on-twitter (CC BY-NC-SA 4.0;
mirror used: https://huggingface.co/datasets/SunidhiSriram/twcs, twcs.csv). The tweets are real but
unlabelled; labels come from the Claude judge (chirak_worker.hard_slice judge, two passes). Because of
the licence the derived file is NOT committed to this MIT repository.

Takes the first tweet of a thread written by a customer and answered by one of the retailers below,
removes @handles and links, keeps English tweets of 8+ words and drops duplicates.

Usage: python3 -I build_twcs.py <twcs.csv> <out.jsonl> [n=400]
"""
import csv
import json
import random
import re
import sys

EN = set("the a an i my you your to and is it of for in on not but with have has was this that me do can be are what why when no".split())

RETAILERS = {"AmazonHelp", "AskeBay", "ArgosHelpers", "AskTarget", "Walmart", "NeweggService", "NikeSupport",
             "marksandspencer", "sizehelpteam", "MOO"}


def clean(text: str) -> str:
    text = re.sub(r"https?://\S+", " ", text)
    text = re.sub(r"(^|\s)@\w+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def main(src: str, dst: str, n: int = 400) -> None:
    csv.field_size_limit(10**7)
    rows = list(csv.DictReader(open(src, newline="", encoding="utf8")))
    author = {r["tweet_id"]: r["author_id"] for r in rows}
    pool, seen = [], set()
    for r in rows:
        if r["inbound"] != "True" or r["in_response_to_tweet_id"]:
            continue
        replied_by = {author.get(t) for t in r["response_tweet_id"].split(",") if t}
        brand = next(iter(replied_by & RETAILERS), None)
        text = clean(r["text"])
        words = re.findall(r"[a-z']+", text.lower())
        if (not brand or len(text.split()) < 8 or sum(c.isascii() for c in text) < 0.95 * len(text)
                or sum(w in EN for w in words) < 0.2 * len(words)):
            continue
        key = re.sub(r"\W+", " ", text.lower()).strip()
        if key in seen:
            continue
        seen.add(key)
        pool.append({"input": text, "source": "twcs", "brand": brand, "origin_id": f"twcs-{r['tweet_id']}",
                     "created_at": r["created_at"]})
    rng = random.Random(20261014)
    sample = rng.sample(pool, min(n, len(pool)))
    with open(dst, "w") as f:
        for i, r in enumerate(sample, 1):
            f.write(json.dumps({"id": f"tw-{i:04d}", **r}, ensure_ascii=False) + "\n")
    brands = {}
    for r in sample:
        brands[r["brand"]] = brands.get(r["brand"], 0) + 1
    print(json.dumps({"pool": len(pool), "n": len(sample), "brands": brands}))


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2], *(int(a) for a in sys.argv[3:]))
