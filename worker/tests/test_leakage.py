import json

import numpy as np

from chirak_worker.leakage import LeakageGuard, dedup, ngram_set, read_avoid_texts
from conftest import FakeEmbedder

EMB = FakeEmbedder()


def test_ngram_ignores_case_and_punctuation():
    a = ngram_set("Where is my ORDER, number 4412 -- it is late!")
    b = ngram_set("where is my order number 4412 it is late")
    assert a == b and len(a) == 2


def test_dedup_keeps_first_of_near_duplicates():
    texts = ["my parcel is late again", "my parcel is late again!", "refund the shoes please"]
    keep = dedup(EMB.encode(texts))
    assert keep == [True, False, True]


def test_dedup_against_known_rows():
    known = EMB.encode(["refund the shoes please"])
    assert dedup(EMB.encode(["refund the shoes please", "card charged twice"]), known) == [False, True]


def test_guard_reports_reason_per_candidate():
    avoid = ["the quick brown fox jumps over the lazy dog today", "completely different eval sentence about invoices"]
    guard = LeakageGuard(EMB, avoid)
    cands = [
        "completely different eval sentence about invoices",              # same vector -> cosine
        "so the quick brown fox jumps over the lazy dog and runs away",  # 8-gram overlap, low cosine
        "my parcel has not arrived yet, please check the tracking",       # clean
    ]
    assert guard.check(cands, EMB.encode(cands)) == ["leakage_cosine", "leakage_ngram", None]


def test_guard_without_avoid_files_passes_everything():
    assert LeakageGuard(EMB, []).check(["a"], np.zeros((1, 3), dtype=np.float32)) == [None]


def test_read_avoid_texts(tmp_path):
    p = tmp_path / "e.jsonl"
    p.write_text(json.dumps({"input": "x y"}) + "\n\n" + json.dumps({"input": "z"}) + "\n")
    assert read_avoid_texts([str(p)]) == ["x y", "z"]
