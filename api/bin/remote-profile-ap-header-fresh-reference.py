#!/usr/bin/env python3
"""Capture each genuine PHP remote header in an isolated process."""
import concurrent.futures, hashlib, json, os, pathlib, subprocess, sys
root=pathlib.Path(__file__).resolve().parents[2]
script=root/'api/bin/remote-profile-ap-header-reference.php'
cases=['baseline','missing_metadata','stale','placeholder','force_refresh','metadata','empty_fields','avatar_only','media_cache','following','inbound','mutual','muted','deprioritized','personal_block','server_block','server_mute','alias_metadata','wafrn','follow_intent','invalid_tab','from_filter']
folder=pathlib.Path('/tmp/vaak-remote-header-authority-requests');folder.mkdir(exist_ok=True)
def capture(key):
 width,case,owner=key
 output=folder/f'{width}-{case}-{owner}.json'
 if os.environ.get('REMOTE_HEADER_REUSE_CAPTURE')=='1':
  item=json.loads(output.read_text());assert len(item['captures'])==1
  row=item['captures'][0];assert (row['width'],row['case'],row['owner'])==key
  return item
 env=dict(os.environ,REMOTE_HEADER_WIDTH=width,REMOTE_HEADER_CASE=case,REMOTE_HEADER_OWNER=str(owner))
 proc=subprocess.run([str(root/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so',str(script),str(output)],env=env,capture_output=True,text=True)
 (output.with_suffix('.log')).write_text(proc.stdout+proc.stderr)
 if proc.returncode:raise RuntimeError(str(key)+' PHP failed; '+str(output.with_suffix('.log')))
 item=json.loads(output.read_text());assert len(item['captures'])==1
 return item
keys=[(width,case,owner) for width in ['INTEGER','BIGINT'] for case in cases for owner in [1,2,0]]
with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:results=list(executor.map(capture,keys))
merged={key:value for key,value in results[0].items() if key not in ['captures','current_schema_by_width']};merged['captures']=[];merged['current_schema_by_width']={}
for key,item in zip(keys,results):
 assert item['source_hashes']==merged['source_hashes']
 merged['captures']+=item['captures'];merged['current_schema_by_width'].update(item['current_schema_by_width'])
assert {(row['width'],row['case'],row['owner']) for row in merged['captures']}==set(keys)
assert len(merged['captures'])==len(keys)
merged['request_lifecycle']={'process':'one genuine PHP process per (width,case,owner)','requests':len(keys),'runner_sha256':hashlib.sha256(pathlib.Path(__file__).read_bytes()).hexdigest()}
output=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-remote-profile-ap-header-reference-authority.json');output.write_text(json.dumps(merged,ensure_ascii=False))
print(str(len(keys))+' fresh-process actual remote header captures '+str(output))
