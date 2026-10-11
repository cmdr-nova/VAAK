#!/usr/bin/env python3
"""Rebuild the actual PHP source/header/full corpus after a fixture restart.

Each stage executes current providers and painters; never patches expected HTML.
The inherited historical event-comparison tail is unused in this current corpus.
"""
import hashlib,json,os,pathlib,subprocess,sys
root=pathlib.Path(__file__).resolve().parents[2]
output=pathlib.Path(sys.argv[1]).resolve() if len(sys.argv)>1 else root/'rust/target/parity-evidence/profile-featured-current-ui'
output.mkdir(parents=True,exist_ok=True)
env={**os.environ,'ROOT_LIBRARY_SEARCH_PRODUCER_BOOTSTRAP':'1','DIRECT_PRODUCTION_HELPERS':'1','LIBRARY_FIXTURE_SOCKET':'/tmp/vaak-library-facts-pg-socket','LP_RAW_COLD':'1','LP_RAW_PRODUCTION_MEDIA':'1'}
php=[str(root/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pgsql.so']
def stage(name,script,destination,extra=None):
 with (output/(name+'.log')).open('w')as log:
  subprocess.run(php+[str(root/'api/bin'/script),str(destination)],env={**env,**(extra or {})},stdout=log,stderr=subprocess.STDOUT,check=True)
 print(name,flush=True)
four=pathlib.Path('/tmp/vaak-local-profile-four-tab-extra72-reference.json')
stage('four-source','local-profile-four-tab-reference.php',four,{'LP_EXTRA_PAGING':'1'})
(output/'four-source-reference.json').write_bytes(four.read_bytes())
card=output/'card-reference.json';stage('card','local-profile-card-reference.php',card)
raw=output/'raw-reference.json';stage('raw','local-profile-raw-card-reference.php',raw,{'LP_RAW_INPUT':str(card)})
header=output/'header-reference.json';stage('header','local-profile-header-reference.php',header,{'PROFILE_HEADER_INPUT':str(raw),'PROFILE_HEADER_SECONDARY_INPUT':str(card)})
full=output/'full-featured32-reference.json'
with(output/'full-featured32-generation.log').open('w')as log:
 subprocess.run([sys.executable,str(root/'api/bin/local-profile-full-featured-reference.py'),str(full)],env={**env,'PROFILE_HEADER_INPUT':str(raw),'PROFILE_HEADER_SECONDARY_INPUT':str(card),'PROFILE_FULL_SECONDARY_INPUT':str(header),'PROFILE_FULL_HEADER_INPUT':str(header)},stdout=log,stderr=subprocess.STDOUT,check=True)
manifest={str(p.relative_to(root)):hashlib.sha256(p.read_bytes()).hexdigest()for p in [root/'api/ap-admin.php',root/'api/ap-featured.php',root/'api/ap-r2.php',root/'api/bin/local-profile-full-reference.php',root/'api/bin/local-profile-header-reference.php',root/'api/bin/local-profile-raw-card-reference.php']}
(output/'capture-source-manifest.json').write_text(json.dumps({'sha256':manifest,'scope':'actual current PHP fixture construction and full-featured32 capture; no native acceptance'},indent=2))
print(full)
