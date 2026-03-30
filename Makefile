.PHONY: test test-unit test-db-up test-db-down

# Run the full test suite (unit + integration + server) with a local test DB.
# Mirrors what CI does: spins up postgres:16, seeds it, runs all suites, tears down.
test: test-db-up
	composer test ; $(MAKE) test-db-down

# Run only the unit tests (no DB required — matches the pre-push hook).
test-unit:
	composer test:quick

test-db-up:
	docker compose -f docker-compose.test.yml up -d --wait

test-db-down:
	docker compose -f docker-compose.test.yml down
