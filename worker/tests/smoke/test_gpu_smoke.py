"""Runs the same checks as `make *-smoke` under pytest. Needs a running worker: RUN_GPU_SMOKE=1 pytest -m gpu."""
import pytest

from chirak_worker import smoke
from chirak_worker.client import WorkerClient

pytestmark = pytest.mark.gpu


@pytest.fixture(scope="module")
def worker():
    return WorkerClient()


@pytest.mark.parametrize("step", ["health", "generate", "train", "evaluate", "serve"])
def test_smoke_step(worker, step):
    smoke.STEPS[step](worker)
