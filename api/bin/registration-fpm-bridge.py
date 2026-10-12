import os,sys,socket,struct,subprocess,pathlib,time,signal,json,http.server,urllib.parse
from registration_fpm_process import identity,children,cleanup
root=pathlib.Path(sys.argv[1]);port=int(sys.argv[2]);app=pathlib.Path(sys.argv[3]);sock=pathlib.Path('/tmp')/('vaak-fpm-'+str(os.getpid())+'.sock');cfg=root/'fpm.conf';audit=root/'effective.jsonl'
denied='exec,shell_exec,system,passthru,proc_open,popen,curl_init,fsockopen,pfsockopen,stream_socket_client,socket_create'
(root/'audit.php').write_text("<?php if(PHP_SAPI!=='fpm-fcgi'||ini_get('allow_url_fopen')!=='0')throw new RuntimeException('SAPI denial missing');foreach(explode(',', '"+denied+"') as $f)if(function_exists($f))throw new RuntimeException('Primitive enabled');if(str_contains(getenv('AP_DB_DSN'),'user=postgres')||!str_contains(getenv('AP_DB_DSN'),'options=--search_path=registration_fpm_'))throw new RuntimeException('Wrong database role');file_put_contents('"+str(audit)+"',json_encode(['sapi'=>PHP_SAPI,'script'=>$_SERVER['SCRIPT_FILENAME'],'role_is_fixture'=>true,'schema_is_fixture'=>true,'flags'=>[getenv('VAAK_REGISTRATION_APPROVALS'),getenv('VAAK_SUPPORTER_CODES')],'denied'=>true]).\"\\n\",FILE_APPEND|LOCK_EX);")
lines=['[global]',f'pid = {root}/fpm.pid',f'error_log = {root}/fpm.log','daemonize = no','[fixture]',f'listen = {sock}','listen.mode = 0600','pm = static','pm.max_children = 2','clear_env = no','catch_workers_output = yes',f'php_admin_value[disable_functions] = {denied}','php_admin_flag[allow_url_fopen] = off',f'php_admin_value[auto_prepend_file] = {root}/audit.php',f'php_admin_value[sys_temp_dir] = {os.environ["TMPDIR"]}','php_admin_flag[log_errors] = on',f'php_admin_value[error_log] = {root}/php-error.log']
cfg.write_text('\n'.join(lines)+'\n'); sock.unlink(missing_ok=True)
os.environ['PHP_INI_SCAN_DIR']='/etc/php/8.3/cli/conf.d'
fpm_env=dict(os.environ,LD_PRELOAD=str(pathlib.Path(__file__).parent/'egress-guard.so'))
fpm=None;owned=[];server=None
def interrupted(signum,frame):raise SystemExit(128+signum)
signal.signal(signal.SIGTERM,interrupted);signal.signal(signal.SIGINT,interrupted)
try:
 fpm=subprocess.Popen(['/tmp/vaak-registration-fpm-runtime/extracted/usr/sbin/php-fpm8.3','-F','-c','/etc/php/8.3/cli/php.ini','-y',str(cfg),'-d','extension=/tmp/vaak-php-mbstring-test/usr/lib/php/20230831/mbstring.so','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so'],env=fpm_env,start_new_session=True)
 owned=[identity(fpm.pid)]
 (root/'owned-processes.json').write_text(json.dumps(owned))
 def interrupted(signum,frame):raise SystemExit(128+signum)
 signal.signal(signal.SIGTERM,interrupted);signal.signal(signal.SIGINT,interrupted)
 runtime={'sapi_binary_sha256':__import__('hashlib').sha256(pathlib.Path('/tmp/vaak-registration-fpm-runtime/extracted/usr/sbin/php-fpm8.3').read_bytes()).hexdigest(),'guard_sha256':__import__('hashlib').sha256(pathlib.Path(fpm_env['LD_PRELOAD']).read_bytes()).hexdigest(),'pid':fpm.pid,'ld_preload_effective':fpm_env['LD_PRELOAD'].encode() in pathlib.Path('/proc/'+str(fpm.pid)+'/environ').read_bytes()}
 (root/'runtime.json').write_text(json.dumps(runtime))
 def rec(t,b):return struct.pack('!BBHHBB',1,t,1,len(b),0,0)+b
 def nv(k,v):
  k=k.encode();v=v.encode(); enc=lambda n: bytes([n]) if n<128 else struct.pack('!I',n|0x80000000)
  return enc(len(k))+enc(len(v))+k+v
 class H(http.server.BaseHTTPRequestHandler):
  def do_GET(self):self.run()
  def do_POST(self):self.run()
  def log_message(self,*a):pass
  def run(self):
   u=urllib.parse.urlsplit(self.path);body=self.rfile.read(int(self.headers.get('Content-Length','0')));p={'GATEWAY_INTERFACE':'CGI/1.1','REQUEST_METHOD':self.command,'SCRIPT_FILENAME':str(app/u.path.lstrip('/')) if u.path in ('/api/ap-auth.php','/api/ap-registration-approvals.php') else str(app/'router.php'),'SCRIPT_NAME':'/router.php','REQUEST_URI':self.path,'QUERY_STRING':u.query,'SERVER_PROTOCOL':'HTTP/1.1','REMOTE_ADDR':'127.0.0.1','SERVER_NAME':'127.0.0.1','SERVER_PORT':str(port),'CONTENT_LENGTH':str(len(body)),'CONTENT_TYPE':self.headers.get('Content-Type','')}
   for k,v in self.headers.items():p['HTTP_'+k.upper().replace('-','_')]=v
   with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as c:
    c.settimeout(30);c.connect(str(sock));c.sendall(rec(1,struct.pack('!HB5s',1,0,b'\0'*5))+rec(4,b''.join(nv(k,v) for k,v in p.items()))+rec(4,b''));
    for i in range(0,len(body),60000):c.sendall(rec(5,body[i:i+60000]))
    c.sendall(rec(5,b''));out=b''
    def read(n):
     b=b''
     while len(b)<n:
      x=c.recv(n-len(b));assert x,'FPM closed';b+=x
     return b
    while True:
     ver,t,r,n,pad,_=struct.unpack('!BBHHBB',read(8));b=read(n);read(pad)
     if t==6:out+=b
     if t==7 and b:sys.stderr.buffer.write(b)
     if t==3:break
   headers,sep,data=out.partition(b'\r\n\r\n');assert sep,out[:200];rows=headers.decode().split('\r\n');status=200
   for row in rows:
    if row.lower().startswith('status:'):status=int(row.split(':',1)[1].strip().split()[0])
   self.send_response(status)
   for row in rows:
    k,v=row.split(':',1)
    if k.lower()!='status':self.send_header(k,v.strip())
   self.end_headers();self.wfile.write(data)
 for _ in range(100):
  if sock.exists():break
  if fpm.poll() is not None:raise RuntimeError('FPM startup failed')
  time.sleep(.03)
 owned=[identity(fpm.pid)]+children(identity(fpm.pid))
 (root/'owned-processes.json').write_text(json.dumps(owned))
 if os.environ.get('VAAK_FPM_SMOKE_FAILURE')=='1':raise RuntimeError('Declared smoke setup failure')
 server=http.server.ThreadingHTTPServer(('127.0.0.1',port),H)
 if os.environ.get('VAAK_FPM_SMOKE_REQUESTS'):
  server.timeout=3
  for _ in range(int(os.environ['VAAK_FPM_SMOKE_REQUESTS'])):server.handle_request()
 else:server.serve_forever()
finally:
 if server:server.server_close()
 receipt=cleanup(owned,fpm) if fpm else {'all_absent':True,'identities':[]}
 (root/'cleanup.json').write_text(json.dumps(receipt))
 sock.unlink(missing_ok=True)
 if not receipt['all_absent']:raise RuntimeError('Owned FPM process cleanup incomplete')
