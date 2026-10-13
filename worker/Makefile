# Unit tests run anywhere (no GPU). Smoke targets talk to a RUNNING worker:
#   WORKER_BASE_URL (default http://127.0.0.1:8100) and WORKER_BEARER_TOKEN must be set,
#   on the pod or through `ssh -N -L 8100:127.0.0.1:8100`.
VENV_PY := .venv/bin/python
PY ?= $(VENV_PY)
CLI = $(PY) -m chirak_worker.cli
# The client-side targets need httpx and pydantic; build .venv on first use unless PY points elsewhere.
DEPS = $(if $(filter $(VENV_PY),$(PY)),$(VENV_PY))

$(VENV_PY): requirements.txt requirements-dev.txt
	if command -v uv >/dev/null; then uv venv .venv --python 3.12 && uv pip install -p .venv -r requirements-dev.txt; \
	else python3 -m venv .venv && .venv/bin/pip install -q -r requirements-dev.txt; fi
	touch $@

.PHONY: test generate-smoke train-smoke evaluate-smoke serve-smoke health-smoke eval

test: $(DEPS)
	$(PY) -m pytest -q

health-smoke: $(DEPS)
	$(CLI) smoke health

generate-smoke: $(DEPS)
	$(CLI) smoke generate

train-smoke: $(DEPS)
	$(CLI) smoke train

evaluate-smoke: $(DEPS)
	$(CLI) smoke evaluate

serve-smoke: $(DEPS)
	$(CLI) smoke serve

# make eval RUN=demo-1 ADAPTER=/data/runs/demo-1/iter-1/adapter   (or ADAPTER=base)
# EVAL=eval-hard scores the run's eval-hard.jsonl instead of the frozen eval.jsonl.
EVAL ?= eval
eval: $(DEPS)
	@test -n "$(RUN)" -a -n "$(ADAPTER)" || { echo "usage: make eval RUN=<run_id> ADAPTER=<adapter_path|base> [EVAL=eval|eval-hard]"; exit 2; }
	$(CLI) eval --run $(RUN) --adapter $(ADAPTER) --eval $(EVAL) $(EVAL_ARGS)
