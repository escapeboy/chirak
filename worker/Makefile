# Unit tests run anywhere (no GPU). Smoke targets talk to a RUNNING worker:
#   WORKER_BASE_URL (default http://127.0.0.1:8100) and WORKER_BEARER_TOKEN must be set,
#   on the pod or through `ssh -N -L 8100:127.0.0.1:8100`.
PY ?= $(shell test -x .venv/bin/python && echo .venv/bin/python || echo python3)
CLI = $(PY) -m chirak_worker.cli

.PHONY: test generate-smoke train-smoke evaluate-smoke serve-smoke health-smoke eval

test:
	$(PY) -m pytest -q

health-smoke:
	$(CLI) smoke health

generate-smoke:
	$(CLI) smoke generate

train-smoke:
	$(CLI) smoke train

evaluate-smoke:
	$(CLI) smoke evaluate

serve-smoke:
	$(CLI) smoke serve

# make eval RUN=demo-1 ADAPTER=/data/runs/demo-1/iter-1/adapter   (or ADAPTER=base)
eval:
	@test -n "$(RUN)" -a -n "$(ADAPTER)" || { echo "usage: make eval RUN=<run_id> ADAPTER=<adapter_path|base>"; exit 2; }
	$(CLI) eval --run $(RUN) --adapter $(ADAPTER) $(EVAL_ARGS)
