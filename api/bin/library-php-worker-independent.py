#!/usr/bin/env python3
"""Genuine PHPcurl→actual Rust on a disposable PG schema; no external traffic."""
import json,os,pathlib,re,subprocess,time,urllib.request,urllib.error
root=pathlib.Path(__file__).resolve().parents[2]
container=os.environ['LIBRARY_FIXTURE_CONTAINER'];socket=os.environ['LIBRARY_FIXTURE_SOCKET'];worker=os.environ['LIBRARY_REVIEW_WORKER']
php=[str(root/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so',str(root/'api/bin/library-php-worker-independent.php')]
reference=json.loads(subprocess.check_output(php+['--export'],text=True))
source=subprocess.check_output(['python3',str(root/'api/bin/library-facts-postgres-fixture.py'),'--emit-rust-harness'],text=True)
sql=next(json.loads(m) for m in re.findall(r'db.batch_execute\(("(?:[^"\\]|\\.)*")\)\.await',source) if 'CREATE SCHEMA library_facts_integer' in json.loads(m)).replace('library_facts_integer','library_php_transport_integer')
def quote(s):return "'"+str(s).replace("'","''")+"'"
sql+="DELETE FROM ap_user_blocks;DELETE FROM ap_mutes;DELETE FROM ap_deprioritized_actors;DELETE FROM masto_favourites;DELETE FROM masto_reblogs;DELETE FROM masto_bookmarks;"
sql+=f"UPDATE ap_users SET actor_id={quote(reference['owner_actor'])},actor_key='fixture' WHERE id=1;UPDATE actor_profile SET actor_key='fixture',auto_unblur_sensitive={reference['auto_unblur']} WHERE actor_key='viewer';"
sql+=f"INSERT INTO remote_media_cache VALUES({quote(reference['statuses'][0]['account']['uri'])},'avatar',{quote(reference['avatar'])});"
subprocess.run(['docker','exec','-i',container,'psql','-U','postgres','-q'],input=sql,text=True,check=True,stdout=subprocess.DEVNULL)
token='fixture-actual-php-curl-secret-32-bytes';port=18804
baseenv={**os.environ,'VAAK_ENV_FILE':'/tmp/nonexistent-library-fixture-env','VAAK_PHYRIAN_BRIDGE_ENV_FILE':'/tmp/nonexistent-library-fixture-env','VAAK_DATABASE_URL':f"host={socket} user=postgres dbname=postgres options='-c search_path=library_php_transport_integer'",'VAAK_LIBRARY_NATIVE_RENDER':'1','VAAK_LIBRARY_NATIVE_SECRET':token,'VAAK_LIBRARY_NATIVE_URL':f'http://127.0.0.1:{port}/internal/library/render','RUST_LOG':'error'}
proc=subprocess.Popen([worker,'serve','--bind',f'127.0.0.1:{port}'],env=baseenv,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
try:
 for _ in range(100):
  try:urllib.request.urlopen(f'http://127.0.0.1:{port}/internal/library/render',timeout=1)
  except urllib.error.HTTPError:break
  except urllib.error.URLError:time.sleep(.02)
 subprocess.run(php,env=baseenv,check=True)
finally:
 proc.terminate();proc.wait(timeout=5)
 subprocess.run(['docker','exec',container,'psql','-U','postgres','-q','-c','DROP SCHEMA IF EXISTS library_php_transport_integer CASCADE'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
