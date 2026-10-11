#!/usr/bin/env python3
import json,os,pathlib,subprocess,sys
root=pathlib.Path(__file__).resolve().parents[2];source=pathlib.Path('/tmp/vaak-profile-full40-reference.json');data=json.loads(source.read_text())
for width in ['INTEGER','BIGINT']:
 for case,label,origin,post in [('posts','posts_mentions','mentions',''),('blog_detail','blog_detail','about','FULL-POST')]:
  out=pathlib.Path('/tmp')/f'vaak-profile-full-{width.lower()}-{label}-navigation-reference.json'
  args=['php','-d','extension=/tmp/vaak-php-mbstring-test/usr/lib/php/20230831/mbstring.so','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pgsql.so',str(root/'api/bin/local-profile-full-reference.php'),width,case,'2','1',str(out),origin,post]
  run=subprocess.run(args,env={**os.environ,'LIBRARY_FIXTURE_SOCKET':'/tmp/vaak-library-facts-pg-socket','DIRECT_PRODUCTION_HELPERS':'1'},capture_output=True,text=True)
  if run.returncode:raise RuntimeError(run.stdout+run.stderr)
  capture=json.loads(out.read_text());capture['case']=label;capture['actual_fixture_case']=case;capture['diagnostic_stderr']=run.stderr;data['captures'].append(capture)
keys={(x['width'],x['case'],x['owner'],x['shell'])for x in data['captures']};assert len(keys)==len(data['captures'])==44
path=pathlib.Path(sys.argv[1]if len(sys.argv)>1 else '/tmp/vaak-profile-full44-reference.json');path.write_text(json.dumps(data));print(path)
