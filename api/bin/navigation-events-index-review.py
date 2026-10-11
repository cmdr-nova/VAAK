#!/usr/bin/env python3
"""Unchanged Rust query text, isolated PG exact-vector index parity and local cost."""
import hashlib,json,pathlib,re,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
queries=[]
for file in ['audience.rs','home_hydrate_ranked.rs']:
 source=(root/'rust/vaak-worker/src'/file).read_text()
 for match in re.finditer(r'"((?:[^"\\]|\\.)*)"',source):
  literal='"'+match.group(1).replace('\n','\\n')+'"'
  try: sql=json.loads(literal)
  except json.JSONDecodeError:continue
  if sql.startswith('SELECT ') and 'FROM events' in sql and 'rtrim(' in sql and 'ANY($1)' in sql and not any('$'+str(i) in sql for i in range(2,10)):
   if sql not in [q['sql'] for q in queries]:queries.append({'source':file,'sql':sql,'sha256':hashlib.sha256(sql.encode()).hexdigest()})
assert len(queries)>=5,len(queries)
result=subprocess.run([str(root/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so',str(root/'api/bin/navigation-events-index-reference.php')],input=json.dumps(queries),text=True,capture_output=True,check=True)
print(result.stdout)
if result.stderr:raise RuntimeError(result.stderr)
