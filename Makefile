# Top-level entry points. Worker targets talk to a RUNNING worker at $WORKER_BASE_URL
# (default http://127.0.0.1:8100, via `ssh -N -L 8100:127.0.0.1:8100 ...`), token from .env.
-include .env
export WORKER_BEARER_TOKEN
WORKER_BASE_URL ?= http://127.0.0.1:8100
export WORKER_BASE_URL

.PHONY: test worker-test app-test health-smoke generate-smoke train-smoke evaluate-smoke serve-smoke eval tunnel

test: worker-test app-test

worker-test:
	$(MAKE) -C worker test

app-test:
	app/vendor/bin/phpunit --configuration app/phpunit.xml

health-smoke generate-smoke train-smoke evaluate-smoke serve-smoke:
	$(MAKE) -C worker $@

# make eval RUN=<run_id> ADAPTER=/data/runs/<run_id>/iter-N/adapter   (or ADAPTER=base)
eval:
	$(MAKE) -C worker eval RUN=$(RUN) ADAPTER=$(ADAPTER)

tunnel:
	ssh -N -L 8100:127.0.0.1:8100 -p $(POD_SSH_PORT) root@$(POD_SSH_HOST)
