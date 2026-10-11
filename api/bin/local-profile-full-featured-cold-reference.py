#!/usr/bin/env python3
"""Fresh actual Featured media resolver/full painter; only external IO responses declared."""
import json,os,pathlib,subprocess,sys,hashlib
root=pathlib.Path(__file__).resolve().parents[2];output=pathlib.Path(sys.argv[1]);output.parent.mkdir(parents=True,exist_ok=True);captures=[]
php=['php','-d','extension=/tmp/vaak-php-mbstring-test/usr/lib/php/20230831/mbstring.so','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so']
for width in ['INTEGER','BIGINT']:
 for kind in ['positive','missing','download_failure','put_failure']:
  for owner in [0,2]:
   for shell in [False,True]:
    case='featured_cold_'+kind;stem=f'{width.lower()}-{case}-{owner}-{int(shell)}';log=output.parent/(stem+'-transport.jsonl');log.write_text('');config=output.parent/(stem+'-transport.json');source=f'https://media.test/featured-cold-{kind}.webp'
    actor='https://featured.test/users/remote';negative=pathlib.Path('/tmp')/('vaak-media-negative-'+hashlib.sha256((actor+'|avatar|'+source).encode()).hexdigest());negative.unlink(missing_ok=True)
    transport={'document':None if kind=='missing' else {'id':actor,'type':'Person','preferredUsername':'remote','name':'Featured & remote','icon':{'type':'Image','url':source}},'download':{'ok':kind!='download_failure','body':'declared-avatar-bytes','content_type':'image/webp'},'put_ok':kind!='put_failure','public_url':'https://media.test/featured-generated.webp','log':str(log.resolve())};config.write_text(json.dumps(transport));path=output.parent/(stem+'-capture.json')
    run=subprocess.run(php+[str(root/'api/bin/local-profile-full-reference.php'),width,case,str(owner),str(int(shell)),str(path)],env={**os.environ,'PROFILE_FEATURED_MEDIA_TRANSPORT':str(config.resolve()),'LIBRARY_FIXTURE_SOCKET':'/tmp/vaak-library-facts-pg-socket','DIRECT_PRODUCTION_HELPERS':'1'},capture_output=True,text=True)
    if run.returncode:raise RuntimeError(run.stdout+run.stderr)
    item=json.loads(path.read_text());item['declared_media_transport']=transport;item['diagnostic_stderr']=run.stderr;captures.append(item);negative.unlink(missing_ok=True)
assert len(captures)==len({(c['width'],c['case'],c['owner'],c['shell'])for c in captures})==32
output.write_text(json.dumps({'captures':captures,'scope':'actual full/shell Featured card+unchanged ap_remote_media_ensure SQL/cache body; actor-doc/download/R2 response delivery declared, no live network/credentials'}));print(output)
