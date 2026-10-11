#!/usr/bin/env python3
"""Explicit approved maintenance only; one index, no schema bootstrap or feeds."""
import json,os,pathlib,signal,subprocess,sys,time
name='idx_events_object_key_id'
expected="CREATE INDEX idx_events_object_key_id ON public.events USING btree (rtrim(object_id, '/'::text), id DESC)"
deadline=time.monotonic()+45
base=['runuser','-u','postgres','--','psql','-X','-A','-t','-v','ON_ERROR_STOP=1','-d','novalandia']
def sql(query,seconds=5):
 seconds=min(seconds,int(deadline-time.monotonic())-3)
 if seconds<1:raise RuntimeError('Overall 45-second maintenance budget exhausted; inspect in a separate phase')
 env={**os.environ,'PGOPTIONS':f'-c lock_timeout=10000 -c statement_timeout={seconds*1000} -c maintenance_work_mem=64MB -c max_parallel_maintenance_workers=0 -c application_name=vaak-navigation-index-maintenance'}
 proc=subprocess.Popen(base+['-c',query],env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,start_new_session=True)
 try:stdout,stderr=proc.communicate(timeout=seconds+2)
 except subprocess.TimeoutExpired:
  try:os.killpg(proc.pid,signal.SIGKILL)
  except ProcessLookupError:pass
  proc.communicate(timeout=1)
  raise RuntimeError('Client process group deadline exceeded; inspect server DDL in a separate phase')
 if proc.returncode:raise RuntimeError(stderr.strip())
 return stdout.strip()
def inspect():
 raw=sql("SELECT json_build_object('valid',i.indisvalid,'ready',i.indisready,'definition',pg_get_indexdef(i.indexrelid)) FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname='idx_events_object_key_id'")
 return json.loads(raw) if raw else None
phase=sys.argv[1] if len(sys.argv) in (2,3) else ''
if phase=='rollback' and sys.argv[2:]!=['--confirm-created-by-this-maintenance']:raise SystemExit('Rollback requires explicit confirmation that this maintenance created the index; never remove a preexisting operator index')
if phase not in ['inspect','create','rollback']:raise SystemExit('Explicit phase required: inspect, create, rollback')
before=inspect();print(json.dumps({'phase':phase,'before':before}),flush=True)
if before is not None and before['definition']!=expected:raise SystemExit('Refusing same-name different-definition index')
if phase=='create':
 if before is not None:
  if not before['valid'] or not before['ready']:raise SystemExit('Existing invalid index; inspect and approve rollback separately before retry')
 else:
  # Avoid duplicating an equivalent operator-managed index with another name.
  raw=sql("SELECT coalesce(json_agg(json_build_object('name',c.relname,'valid',i.indisvalid,'definition',pg_get_indexdef(i.indexrelid))),'[]'::json) FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid WHERE i.indrelid='public.events'::regclass")
  equivalent=[row for row in json.loads(raw) if row['definition'].split(' ON ',1)[-1]==expected.split(' ON ',1)[-1]]
  if equivalent:raise SystemExit('Equivalent event index exists; independently validate and reuse it instead of creating a duplicate')
  cpu_text=pathlib.Path('/proc/pressure/cpu').read_text().splitlines()[0]
  pressure=float(next(v.split('=',1)[1] for v in cpu_text.split() if v.startswith('avg10=')))
  mem=next(int(line.split()[1]) for line in pathlib.Path('/proc/meminfo').read_text().splitlines() if line.startswith('MemAvailable:'))
  load=os.getloadavg()[0];cpus=len(os.sched_getaffinity(0))
  print(json.dumps({'load1':load,'cpu_pressure10':pressure,'available_memory_kib':mem}),flush=True)
  if pressure>=90 or load>=cpus*4 or mem<512*1024:raise SystemExit('Resource gate failed; defer the single index build and resample')
  # A PostgreSQL timeout can leave an invalid index. Inspect explicitly on failure;
  # never retry automatically or claim client cancellation rolled back DDL.
  try:sql("CREATE INDEX CONCURRENTLY idx_events_object_key_id ON public.events (rtrim(object_id, '/'), id DESC)",30)
  except Exception as error:
   print(json.dumps({'create_error':str(error),'after':inspect()}),flush=True);raise SystemExit(1)
 after=inspect()
 if not after or not after['valid'] or not after['ready'] or after['definition']!=expected:raise SystemExit('Post-create validation failed')
 print(json.dumps({'after':after}),flush=True)
elif phase=='rollback':
 if before is not None:sql('DROP INDEX CONCURRENTLY public.idx_events_object_key_id',30)
 after=inspect()
 if after is not None:raise SystemExit('Rollback did not remove expected index')
 print(json.dumps({'after':after}),flush=True)
