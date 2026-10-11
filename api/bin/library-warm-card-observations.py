#!/usr/bin/env python3
"""Assert observed actual before-remediation PHP/native Library full HTML."""
import json,pathlib
rows=[json.loads(line) for line in pathlib.Path('/tmp/vaak-search-context-independent-cards.jsonl').read_text().splitlines()]
assert len(rows)==6
for width in ['integer','bigint']:
 a,b,c=[r for r in rows if r['schema'].endswith(width)]
 assert a['owner']==1 and b['owner']==2 and c['owner']==1
 for r in [a,b,c]:
  assert 'Before assembly name' in r['php'] and 'Before assembly name' in r['native']
 assert 'After assembly body' in a['php'] and 'After assembly body' in a['native']
 assert 'After assembly warning' not in a['php'] and 'After assembly warning' in a['native']
 assert 'class="cw-gate"' not in a['php'] and 'class="cw-gate"' in a['native']
 assert 'Before assembly body' in b['php'] and 'After assembly body' not in b['php']
 assert 'Before assembly body' not in b['native'] and 'After assembly body' in b['native']
 assert 'After assembly warning' not in b['php'] and 'After assembly warning' in b['native']
 assert 'Removed stored warning' in c['php'] and 'Removed stored warning' not in c['native']
 assert 'class="cw-gate"' in c['php'] and 'class="cw-gate"' not in c['native']
print('library-warm-card-observations: PASS (six actual caller/fullHTML observations, distinct owner/nonowner warning/body/snapshot boundaries)')
