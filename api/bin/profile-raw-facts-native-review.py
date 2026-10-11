#!/usr/bin/env python3
"""Review fresh authenticated SQL factories against independent PHP captures.

No expected HTML or supplemental presentation facts enter the native factory.
All ordinary construction errors remain failures, including cold Ask warming.
"""
import hashlib, importlib.util, json, pathlib, sys

here = pathlib.Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('raw_tokens', here / 'search-native-acceptance-review.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
reference_path, native_path, report_path = map(pathlib.Path, sys.argv[1:4])
reference, native = json.loads(reference_path.read_text()), json.loads(native_path.read_text())
expected = {(r['width'], r['case']): r for r in reference['captures'] if r['context']['owner'] > 0}
seen, failures, matches, fact_matches = set(), [], 0, 0
fact_fields = ('owner_id', 'owner_actor', 'return_view', 'auto_unblur_sensitive',
               'resolved_avatar', 'csrf', 'favourited', 'bookmarked', 'boosted',
               'lazy_media', 'actor_key', 'actor', 'display_name', 'note_dom_id',
               'masto_row', 'status_id', 'own_note', 'guest', 'poll_row')
for row in native['captures']:
    key = row['width'], row['case']
    if key in seen or key not in expected:
        failures.append({'key': key, 'error': 'unexpected or repeated capture'})
        continue
    seen.add(key)
    original = expected[key]
    if original['actual_shared_caller_accepted'] or not original['request_cache_cold']:
        failures.append({'key': key, 'error': 'reference is not a genuine cold raw caller'})
    if row['source'] != original['source']:
        failures.append({'key': key, 'error': 'selected source changed'})
    if row.get('error'):
        failures.append({'key': key, 'error': row['error']})
        continue
    left, right = module.Tokens(original['php']['html']).tokens, module.Tokens(row['html']).tokens
    if left != right:
        failures.append({'key': key, 'first_dom': next(((a, b) for a, b in zip(left, right) if a != b), ('length', len(left), len(right)))})
    else:
        matches += 1
    facts, captured = row['facts'], original['render_facts']
    differences = {k: [captured.get(k), facts.get(k)] for k in fact_fields if captured.get(k) != facts.get(k)}
    if captured.get('ask_row'):
        for field in ('asker_display', 'asker_handle', 'asker_avatar'):
            if captured['ask_row'].get(field) != facts['ask_row'].get(field):
                differences['ask_row.' + field] = [captured['ask_row'].get(field), facts['ask_row'].get(field)]
    if row.get('warm_context'):
        expected_warms = original['php']['effects']['fixture_actor_warm_requests']['value']
        if row['actor_warm_requests'] != expected_warms:
            differences['actor_warm_requests'] = [expected_warms, row['actor_warm_requests']]
    # Media map supplementary exports deliberately omit cached previews. Derive
    # their exact cache values from the original current production SQL rows.
    previews = {r['public_url']: r['preview_url'] for r in original['current_tables']['masto_media']
                if r.get('public_url') and r.get('preview_url') is not None}
    if any(previews.get(url) != preview for url, preview in facts['media_preview_by_url'].items()):
        differences['media_preview_by_url'] = ['current SQL preview mismatch', facts['media_preview_by_url']]
    if differences:
        failures.append({'key': key, 'facts': differences})
    else:
        fact_matches += 1
if seen != set(expected):
    failures.append({'error': 'missing authenticated captures', 'count': len(set(expected) - seen)})
report = {'captures': len(native['captures']), 'complete_significant_dom_matches': matches,
          'current_fact_comparisons': fact_matches, 'failures': failures,
          'compared_fact_fields': fact_fields,
          'php_sha256': hashlib.sha256(reference_path.read_bytes()).hexdigest(),
          'native_sha256': hashlib.sha256(native_path.read_bytes()).hexdigest(),
          'scope': 'authenticated current SQL presentation factory and complete raw card; no native admission, IO effects, full page or HTTP claim'}
report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2))
print(json.dumps(report, ensure_ascii=False))
sys.exit(bool(failures))
