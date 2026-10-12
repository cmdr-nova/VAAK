#!/usr/bin/env python3
"""Finite real FPM replay of retained complete handlers; fixture-only source adaptations."""
import pathlib,os,sys,shutil,subprocess,json,hashlib,socket,signal
R=pathlib.Path(__file__).resolve().parents[2];B=R/'rust/target/parity-evidence/registration-fpm-isolated';F=B/'source';kind,width=sys.argv[1:];O=B/(kind+'-'+width);O.mkdir(parents=True,exist_ok=False);schema='registration_fpm_'+kind+'_'+width;role=schema+'_role'
B.mkdir(parents=True,exist_ok=True)
for name in ['fpm-bridge.py','egress-guard.c','egress-probe.c','fpm-process.py']:
 shutil.copy2(R/'api/bin'/('registration_fpm_process.py' if name=='fpm-process.py' else 'registration-'+name),B/('registration_fpm_process.py' if name=='fpm-process.py' else name))
subprocess.run(['cc','-shared','-fPIC','-O2',str(B/'egress-guard.c'),'-o',str(B/'egress-guard.so')],check=True)
if not F.exists():
 rows=[]
 for d in ['api','vaak']:
  for p in (R/d).rglob('*'):
   if p.is_file() and '__pycache__' not in p.parts:
    q=F/p.relative_to(R);q.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,q);rows.append({'path':str(p.relative_to(R)),'sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'mode':oct(p.stat().st_mode&511)})
 (B/'inputs.json').write_text(json.dumps(rows,indent=2))
os.environ['VAAK_TEST_PHP_BINARY']=str(F/'api/bin/php-supporter-isolated');os.environ['SUPPORTER_WHOLE_SOURCE']=str(F)
# Strict loopback-only Python sockets as well as PHP primitive denial.
_dns=socket.getaddrinfo;_con=socket.socket.connect
socket.getaddrinfo=lambda h,*a,**k:_dns(h,*a,**k) if h in('127.0.0.1','localhost','::1',None) else (_ for _ in()).throw(RuntimeError('External DNS denied'))
def con(self,a):
 if isinstance(a,tuple) and a[0] not in('127.0.0.1','::1'):raise RuntimeError('External connect denied')
 return _con(self,a)
socket.socket.connect=con
realrun=subprocess.run;realpop=subprocess.Popen;procs=[];provisioned=False
php=[str(F/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so']
def admin(sql):
 p=O/'owned-admin.php';p.write_text("<?php $d=new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres');$d->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$d->exec("+json.dumps(sql)+");")
 realrun(php+[str(p)],check=True)
def run(args,*a,**k):
 global provisioned
 result=realrun(args,*a,**k)
 if not provisioned and isinstance(args,list) and pathlib.Path(str(args[-1])).name in('provision.php','provision-integer.php'):
  admin('CREATE ROLE '+role+' LOGIN; ALTER SCHEMA '+schema+' OWNER TO '+role+'; GRANT ALL ON ALL TABLES IN SCHEMA '+schema+' TO '+role+'; GRANT ALL ON ALL SEQUENCES IN SCHEMA '+schema+' TO '+role+';')
  # Owning fixture tables permits real module ALTER/INDEX migrations without global authority.
  p=O/'owners.php';p.write_text("<?php $d=new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres');foreach($d->query(\"SELECT tablename FROM pg_tables WHERE schemaname='"+schema+"'\") as $r)$d->exec('ALTER TABLE "+schema+".\"'.$r['tablename'].'\" OWNER TO "+role+"');foreach($d->query(\"SELECT sequencename FROM pg_sequences WHERE schemaname='"+schema+"'\") as $r)$d->exec('ALTER SEQUENCE "+schema+".\"'.$r['sequencename'].'\" OWNER TO "+role+"');echo json_encode($d->query(\"SELECT rolname,rolsuper,rolcreatedb,rolcreaterole FROM pg_roles WHERE rolname='"+role+"'\")->fetch());")
  receipt=subprocess.check_output(php+[str(p)]);(O/'role.json').write_bytes(receipt);provisioned=True
  # The running full suite global environment, used by subsequent seed/control/HTTP calls.
  ns['e']['AP_DB_DSN']=ns['e']['AP_DB_DSN'].replace('user=postgres','user='+role)
 return result
def pop(args,*a,**k):
 if isinstance(args,list) and '-S' in args:
  env=k['env'];assert role in env['AP_DB_DSN'];n=len(procs);fr=O/('fpm-'+str(n));fr.mkdir();port=args[args.index('-S')+1].split(':')[1]
  p=realpop([sys.executable,str(B/'fpm-bridge.py'),str(fr),port,str(O/'app')],env=env,stdout=k.get('stdout'),stderr=k.get('stderr'),start_new_session=True);procs.append(p);return p
 return realpop(args,*a,**k)
subprocess.run=run;subprocess.Popen=pop
ns={'__file__':str(R/'api/bin/supporter-whole-flow-sapi.py')}
sys.argv=[sys.argv[0]]+(['--integer'] if width=='integer' else [])
try:
 if kind=='whole':
  setup=(F/'api/bin/supporter-whole-flow-setup.py').read_text().replace("o=r/'rust/target/parity-evidence/supporter-whole-flow'","o=r/"+repr(str(O.relative_to(R))))
  exec(compile(setup,'owned-setup','exec'),{'__file__':str(R/'api/bin/supporter-whole-flow-setup.py')})
  p=R/'rust/target/parity-evidence/supporter-whole-flow/provision.php';(O/'provision.php').write_text(p.read_text().replace('supporter_whole_flow',schema.removesuffix('_integer') if width=='integer' else schema))
  code=(F/'api/bin/supporter-whole-flow-sapi.py').read_text().replace("O=R/'rust/target/parity-evidence/supporter-whole-flow'","O=R/"+repr(str(O.relative_to(R)))).replace('supporter_whole_flow',schema.removesuffix('_integer') if width=='integer' else schema).replace('18997','19083')
 else:
  shutil.copytree(F,O/'source');shutil.copy2(R/'rust/target/parity-evidence/supporter-bootstrap-activation/provision-template.php',O/'provision-template.php')
  code=(F/'api/bin/supporter-bootstrap-activation.py').read_text().replace("ORIGINAL=R/'rust/target/parity-evidence/supporter-bootstrap-activation'","ORIGINAL=R/"+repr(str(O.relative_to(R)))).replace("O=R/('rust/target/parity-evidence/supporter-bootstrap-policy-repair' if POLICY_ONLY else 'rust/target/parity-evidence/supporter-bootstrap-activation')","O=R/"+repr(str(O.relative_to(R)))).replace("SCHEMA='supporter_activation_policy' if POLICY_ONLY else 'supporter_activation'","SCHEMA="+repr(schema.removesuffix('_integer') if width=='integer' else schema)).replace('18995','19084')
  # Its nested exec exposes e in this same global namespace.
 ns['__file__']=str(R/'api/bin'/('supporter-bootstrap-activation.py' if kind=='bootstrap' else 'supporter-whole-flow-sapi.py'))
 exec(compile(code,'actual-fpm-'+kind,'exec'),ns)
finally:
 subprocess.run=realrun;subprocess.Popen=realpop
 for p in procs:
  try:os.killpg(p.pid,signal.SIGTERM)
  except ProcessLookupError:pass
  try:p.wait(timeout=5)
  except subprocess.TimeoutExpired:os.killpg(p.pid,signal.SIGKILL);p.wait()
 import importlib.util
 spec=importlib.util.spec_from_file_location('registration_fpm_process',R/'api/bin/registration_fpm_process.py');helper=importlib.util.module_from_spec(spec);spec.loader.exec_module(helper)
 cleanup_receipts=[]
 for owned_path in O.glob('fpm-*/owned-processes.json'):
  rows=json.loads(owned_path.read_text());receipt=helper.cleanup(rows);cleanup_receipts.append(receipt)
  if not receipt['all_absent']:raise RuntimeError('Actual fixture FPM master/child remains')
 (O/'process-cleanup.json').write_text(json.dumps(cleanup_receipts,indent=2))
 admin('DROP SCHEMA IF EXISTS '+schema+' CASCADE; DROP ROLE IF EXISTS '+role+';')
 audits=[]
 for p in O.glob('fpm-*/effective.jsonl'):
  audits.extend(json.loads(l) for l in p.read_text().splitlines())
 (O/'fpm-receipt.json').write_text(json.dumps({'runtime_sha256':hashlib.sha256(pathlib.Path('/tmp/vaak-registration-fpm-runtime/extracted/usr/sbin/php-fpm8.3').read_bytes()).hexdigest(),'owned_pids':[p.pid for p in procs],'requests':len(audits),'all_fpm':all(x['sapi']=='fpm-fcgi' and x['denied'] for x in audits),'audit':audits,'cleanup':True},indent=2))
