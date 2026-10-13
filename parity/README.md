# PHP / Rust fixture replay

Rust is the source of truth. This harness replays the same fixture inputs through the PHP and Rust implementations, normalizes volatile fields, and writes a divergence report. Different output does not fail CI. The job fails only when the harness breaks or a Rust producer errors.

No Postgres and no live worker. PHP runs through `api/bin/php-policy-test` (real mbstring). Rust runs `vaak-worker parity-replay`, which does not load worker config or open a database.

## Run

```bash
python3 .github/scripts/php-rust-parity.py
python3 .github/scripts/php-rust-parity.py --only audience
```

Reports land in `parity/out/report.md` and `parity/out/report.json`. CI writes the same files to the `php-rust-parity-report` artifact and to the job summary.

Normalization:

- object key order is ignored
- configured keys are dropped (`rendered_at`, `csrf`, and the list in `parity/catalog.json`)
- timestamp strings that parse as the same UTC instant compare equal
- a case may set `unordered_paths` when array order does not matter

## Add a case

1. Put inputs in `parity/fixtures/<name>.json`, or reuse a fixture that already exists (`api/fixtures/…`, `rust/vaak-worker/fixtures/…`, or a PHP reference script that already embeds the corpus).
2. Add `parity/php/<name>.php`. It must call the real PHP function and print one JSON object:

   ```json
   {"cases":[{"id":"example","input":{},"output":{}}]}
   ```

   `input` is what Rust replays. `output` is the PHP result. Exit 0. A non-zero exit is recorded as a PHP-side divergence and does not fail CI.
3. Handle the producer in `rust/vaak-worker/src/parity_replay.rs`. Read `input`, call the existing Rust function, and return JSON. Do not open a database. Return an error if the Rust function errors; that fails the job.
4. Register the case in `parity/catalog.json` with `id`, `area`, `summary`, and `php.script`. Set `rust_producer` when the catalog id and the Rust match arm differ. Optional `drop_keys` and `unordered_paths` extend the normalizer for that case.
5. Run `python3 .github/scripts/php-rust-parity.py --only <id>`.

ActivityPub normalize fixtures under `api/fixtures/normalize/` are not in the catalog. `normalize-fixture-smoke.php` opens Postgres, and `vaak-types` only checks that `expected.json` deserializes. Add them when a DB-free PHP producer and a Rust producer both exist.
