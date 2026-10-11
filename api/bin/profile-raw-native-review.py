#!/usr/bin/env python3
"""Compare independent actual raw PHP cards with pure native painter output.
The PHP source/facts capture is immutable and never used as expected HTML input
to the Rust renderer. This reviewer makes no admission or live HTTP claim.
"""
import hashlib, importlib.util, json, pathlib, sys
here = pathlib.Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('raw_tokens', here / 'search-native-acceptance-review.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
reference_path, native_path, report_path = map(pathlib.Path, sys.argv[1:4])
reference = json.loads(reference_path.read_text())
native = json.loads(native_path.read_text())
expected = {(r['width'], r['case']): r for r in reference['captures']}
seen, failures, matches = set(), [], 0
for row in native['captures']:
    key = row['width'], row['case']
    if key in seen or key not in expected:
        failures.append({'key': key, 'error': 'unexpected or repeated capture'})
        continue
    seen.add(key)
    original = expected[key]
    left, right = module.Tokens(original['php']['html']).tokens, module.Tokens(row['html']).tokens
    if left != right:
        failures.append({'key': key, 'first_dom': next(((a, b) for a, b in zip(left, right) if a != b), ('length', len(left), len(right)))})
    else:
        matches += 1
    if original['context']['guest']:
        if any(t[0] == 'open' and (t[1] == 'form' or any(k == 'href' and ('compose=1' in v or 'view=report' in v) for k, v in t[2])) for t in right):
            failures.append({'key': key, 'error': 'guest card exposed authenticated action'})
if seen != set(expected):
    failures.append({'error': 'missing captured cases', 'count': len(set(expected) - seen)})
report = {'captures': len(native['captures']), 'complete_significant_dom_matches': matches, 'failures': failures,
          'php_sha256': hashlib.sha256(reference_path.read_bytes()).hexdigest(),
          'native_sha256': hashlib.sha256(native_path.read_bytes()).hexdigest(),
          'scope': 'pure raw painter full HTML including ordered forms, attributes and significant body bytes; no native source admission, IO effects, full page or HTTP parity assertion'}
report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2))
print(json.dumps(report, ensure_ascii=False))
sys.exit(bool(failures))
