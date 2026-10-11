#!/usr/bin/env python3
"""Actual saved cold collector, fresh PHP process for each complete request."""
import json, os, pathlib, subprocess, sys
root=pathlib.Path(__file__).resolve().parents[2]
output=pathlib.Path(sys.argv[1]);captures=[];parts=[]
for width in ('INTEGER','BIGINT'):
 for case in ('saved_ap','mixed_saved','folder_selected','action_saved_ap'):
  path=output.with_name(output.stem+'-'+width.lower()+'-'+case+'.json')
  env={**os.environ,'ROOT_SAVED_PRODUCER_COLD':'1','ROOT_SAVED_WIDTH_FILTER':width,'ROOT_SAVED_CASE_FILTER':case,'ROOT_CAPTURE_FRESH':'1','LIBRARY_FIXTURE_SOCKET':'/tmp/vaak-library-facts-pg-socket'}
  env.pop('ROOT_NATIVE_ACCEPT',None)
  run=subprocess.run([str(root/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so',str(root/'api/bin/library-saved-producer-reference.php'),str(path)],env=env,capture_output=True,text=True)
  path.with_suffix('.log').write_text(run.stdout+run.stderr);assert run.returncode==0,(run.stdout,run.stderr)
  part=json.loads(path.read_text());assert len(part['captures'])==1
  expected_actor_calls=(['https://remote.test/users/alice'] if case=='action_saved_ap' else [])+['https://savedproducer.test/users/alice']
  assert part['captures'][0]['php']['effects']['actor_warm']==expected_actor_calls,'all actual first-request cold helper calls must be retained'
  assert part['captures'][0]['php']['effects']['media_warm']==(['https://remote.test/users/alice'] if case=='action_saved_ap' else [])
  parts.append(part);captures.extend(part['captures'])
output.write_text(json.dumps({'execution_wall_start':min(part['execution_wall_start'] for part in parts),'execution_wall_end':max(part['execution_wall_end'] for part in parts),'declarations':parts[0]['declarations'],'scope':'Eight actual complete saved producer/caller requests, fresh PHP process per request, distinct unprimed cold actor; session/external lookup/worker delivery declared. Original per-request artifacts retained.','captures':captures},indent=2))
print(f'Actual request-isolated cold producer8: {output}')
