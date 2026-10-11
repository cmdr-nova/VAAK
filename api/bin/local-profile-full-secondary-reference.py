#!/usr/bin/env python3
"""Finite actual full/shell fragment captures, each a fresh PHP request."""
import json,os,pathlib,subprocess,sys
root=pathlib.Path(__file__).resolve().parents[2];output=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-profile-full-secondary56-reference.json');captures=[]
php=['php','-d','extension=/tmp/vaak-php-mbstring-test/usr/lib/php/20230831/mbstring.so','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pgsql.so']
for width in ['INTEGER','BIGINT']:
 for case in ['empty_pinned','pins_public','pins_private','pins_missing_outbox','pins_deleted_event','asks_direct','asks_nonempty']:
  for owner in [0,2]:
   modes=[False,True]
   for shell in modes:
    path=pathlib.Path('/tmp')/f'vaak-profile-full-{width.lower()}-{case}-{owner}-{int(shell)}-reference.json'
    run=subprocess.run(php+[str(root/'api/bin/local-profile-full-reference.php'),width,case,str(owner),str(int(shell)),str(path)],env={**os.environ,'LIBRARY_FIXTURE_SOCKET':'/tmp/vaak-library-facts-pg-socket','DIRECT_PRODUCTION_HELPERS':'1'},capture_output=True,text=True)
    if run.returncode:raise RuntimeError(run.stdout+run.stderr)
    captured=json.loads(path.read_text());captured['diagnostic_stderr']=run.stderr;captures.append(captured)
keys={(x['width'],x['case'],x['owner'],x['shell'])for x in captures};assert len(keys)==len(captures)==56
output.write_text(json.dumps({'captures':captures,'scope':'actual current full/shell inner profile template, fresh PHP request per case; actual auth/bootstrap outer navigation excluded; no native expected normalization'}));print(str(output))
