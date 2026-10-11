#!/usr/bin/env python3
"""Before/after two real PHP processes, same file-backed session; bounded delay."""
import json,os,pathlib,subprocess,tempfile,time
root=pathlib.Path(__file__).resolve().parents[2]
php=[str(root/'api/bin/php-policy-test'),str(root/'api/bin/trending-session-lock-reference.php')]
with tempfile.TemporaryDirectory(prefix='vaak-trend-lock-') as work:
 before=pathlib.Path(work)/'before.php';before.write_bytes(subprocess.check_output(['git','show','HEAD:api/ap-admin.php'],cwd=root))
 results={}
 for name,source in [('before',before),('after',root/'api/ap-admin.php')]:
  session_dir=pathlib.Path(work)/name;session_dir.mkdir();(session_dir/'sess_trends-session-regression').touch()
  env={**os.environ,'TREND_SESSION_DIR':str(session_dir),'TREND_REFERENCE_SOURCE':str(source)}
  subprocess.run(php+['seed'],env=env,check=True,capture_output=True)
  producer=subprocess.Popen(php+['producer'],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
  try:
   deadline=time.monotonic()+5
   while not (session_dir/'ready').exists():
    if producer.poll() is not None:raise RuntimeError(producer.communicate())
    if time.monotonic()>deadline:raise RuntimeError('producer failed readiness')
    time.sleep(.01)
   ready=json.loads((session_dir/'ready').read_text())
   consumer=subprocess.run(php+['consumer'],env=env,check=True,capture_output=True,text=True,timeout=5)
   out,err=producer.communicate(timeout=5)
   assert producer.returncode==0 and not err,(producer.returncode,err)
   results[name]={'cache_io':ready,'consumer':json.loads(consumer.stdout),'producer':json.loads(out)}
  finally:
   if producer.poll() is None:producer.kill();producer.wait()
 assert results['before']['cache_io']['active'] and results['before']['consumer']['wait']>.7
 assert not results['after']['cache_io']['active'] and results['after']['consumer']['wait']<.5
 assert results['before']['producer']['html'] and results['after']['producer']['html']==''
 assert not results['after']['producer']['active_after']
 assert all(r['consumer']['draft']=='retained' for r in results.values())
 print(json.dumps({'passed':7,'results':results},indent=2))
