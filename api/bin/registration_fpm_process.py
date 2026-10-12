"""Exact fixture process identity and bounded pidfd cleanup; no name/group matching."""
import pathlib,os,hashlib,signal,time,json

def identity(pid):
 p=pathlib.Path('/proc')/str(pid)
 try:
  stat=(p/'stat').read_text().rsplit(')',1)[1].split()
  try:exe=os.readlink(p/'exe')
  except PermissionError:exe=None
  return {'pid':pid,'start_ticks':int(stat[19]),'exe':exe,'exe_sha256':hashlib.sha256(pathlib.Path(exe).read_bytes()).hexdigest() if exe else None,'ppid':int(stat[1])}
 except (FileNotFoundError,ProcessLookupError):return None

def matches(row):
 cur=identity(row['pid'])
 if cur is None:return False
 if row.get('inherited_exe_identity') and cur['exe'] is None:return all(cur[k]==row[k] for k in ['pid','start_ticks'])
 return all(cur[k]==row[k] for k in ['pid','start_ticks','exe','exe_sha256'])

def send(row,sig):
 if not matches(row):return False
 fd=os.pidfd_open(row['pid'])
 try:
  if not matches(row):return False
  signal.pidfd_send_signal(fd,sig)
  return True
 except ProcessLookupError:return False
 finally:os.close(fd)

def children(master):
 rows=[]
 task=pathlib.Path('/proc')/str(master['pid'])/'task'/str(master['pid'])/'children'
 for pid in task.read_text().split():
  row=identity(int(pid))
  if row and row['ppid']==master['pid']:
   if row['exe'] is None:
    row.update(exe=master['exe'],exe_sha256=master['exe_sha256'],inherited_exe_identity=True,parent_start_ticks=master['start_ticks'])
   if row['exe_sha256']==master['exe_sha256']:rows.append(row)
 return rows

def cleanup(rows,master_process=None):
 master=rows[0] if rows else None
 if master:send(master,signal.SIGQUIT)
 for sig in [None,signal.SIGTERM,signal.SIGKILL]:
  if sig:
   for row in rows:send(row,sig)
  end=time.monotonic()+2
  while time.monotonic()<end:
   if master_process:master_process.poll()
   if all(not matches(row) for row in rows):break
   time.sleep(.03)
  if all(not matches(row) for row in rows):break
 receipt={'identities':rows,'all_absent':all(not pathlib.Path('/proc/'+str(row['pid'])).exists() for row in rows),'identity_no_longer_active':all(not matches(row) for row in rows)}
 if master_process:
  try:master_process.wait(timeout=.2)
  except Exception:pass
 return receipt
