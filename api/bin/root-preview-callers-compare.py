#!/usr/bin/env python3
"""Exact original-input / actual PHP caller comparison for the Search guard."""
import json
import sys
from pathlib import Path
before_path = Path(sys.argv[1] if len(sys.argv) > 1 else '/tmp/vaak-root-preview-callers-before-reference.json')
after_path = Path(sys.argv[2] if len(sys.argv) > 2 else '/tmp/vaak-root-preview-callers-after-reference.json')
before = json.loads(before_path.read_text())['captures']
after = json.loads(after_path.read_text())['captures']
assert len(before) == len(after) == 1052
changed = []
for index, (old, new) in enumerate(zip(before, after)):
    for key in ('width', 'case', 'entry', 'initial_status_budget', 'clock', 'source', 'source_sha256', 'granted_tables'):
        assert old.get(key) == new.get(key), (index, key)
    for key in ('html', 'result', 'headers', 'collector_calls'):
        assert old['php'].get(key) == new['php'].get(key), (index, key)
    # Ordinary baseline SQL snapshots precede painting. Withdrawal controls also
    # retain the exact granted SQL snapshot; their final snapshot includes cache
    # writes from preview IO, so permitted denial repairs can change those rows.
    if not old['case'].startswith('collector_withdrawal_'):
        assert old['current_tables'] == new['current_tables'], (index, 'current_tables')
    old_effect = old['php']['effects']
    new_effect = new['php']['effects']
    assert old_effect['quote_budget'] == new_effect['quote_budget'], index
    assert old_effect['fetch_budget'] == new_effect['fetch_budget'], index
    if old_effect == new_effect:
        continue
    assert old['entry'].startswith('search'), index
    assert old['case'] in ('denied_leading', 'collector_withdrawal_revoked', 'collector_withdrawal_revoked_no_url'), index
    assert not new_effect['http'] and not new_effect['warm'], index
    if old['case'].startswith('collector_withdrawal_'):
        assert new['current_audience_denied'] is True, index
        assert new_effect['status_budget'] == old['initial_status_budget'], index
    changed.append(index)
assert len(changed) == 60
summary = {'captures': len(before), 'exact_inputs_clocks_html_results_headers_collector_calls': True,
           'exact_baseline_current_sql_and_withdrawal_granted_sql': True,
           'identical_effects': len(before) - len(changed), 'denied_search_effect_repairs': len(changed),
           'changed_indices': changed, 'native_parity': False}
Path('/tmp/vaak-root-preview-callers-repair-comparison.json').write_text(json.dumps(summary, indent=2))
print('root-preview-callers comparison PASS 1052 exact originals/clocks/HTML/metadata; 992 exact effects; 60 denied Search preview repairs')
