#!/usr/bin/env python3
"""Compare every exported article token; retain all attributes and text bytes.
Only PHP ASCII whitespace surrounding template tokens is ignored. Controller
section wrappers are outside the renderer's article contract and are excluded.
No warning, action, audience, source, clock, or interaction value is masked.
"""
import json,re,pathlib,sys,difflib
path=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-search-context-independent-cards.jsonl')
trim=' \t\r\n\x00\x0b'
def tokens(html):
    start=html.index('<article'); end=html.rindex('</article>')+len('</article>')
    return [token.strip(trim) for token in re.findall(r'<[^>]+>|[^<]+',html[start:end]) if token.strip(trim)]
rows=[json.loads(line) for line in path.read_text().splitlines()]
failures=[]
for i,row in enumerate(rows):
    a,b=tokens(row['php']),tokens(row['native'])
    if a!=b:
        failures.append({'case':i,'schema':row['schema'],'markers':row['markers'],'diff':list(difflib.unified_diff(a,b))})
report={'cards':len(rows),'matching':len(rows)-len(failures),'mismatches':failures}
pathlib.Path('/tmp/vaak-search-context-card-comparison.json').write_text(json.dumps(report,ensure_ascii=False,indent=2))
print(json.dumps({'cards':len(rows),'matching':report['matching'],'mismatching':len(failures)}))
for failure in failures[:3]:print(json.dumps(failure,ensure_ascii=False))
sys.exit(bool(failures))
