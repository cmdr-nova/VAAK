#!/usr/bin/env python3
"""Actual PHP/native public handle TLS requests with owned ephemeral server."""
import http.server,json,os,pathlib,subprocess,threading,time,ssl,tempfile,urllib.parse
ROOT=pathlib.Path(__file__).resolve().parents[2]
class Handler(http.server.BaseHTTPRequestHandler):
 def log_message(self,*args):pass
 def do_GET(self):
  self.server.requests.append({'host':self.headers.get('Host'),'path':self.path,'ua':self.headers.get('User-Agent'),'accept':self.headers.get('Accept'),'authorization':self.headers.get('Authorization')})
  parsed=urllib.parse.urlsplit(self.path);query=urllib.parse.parse_qs(parsed.query);handle=(query.get('handle')or query.get('actor')or ['direct'])[0];method=parsed.path.split('/')[-1];body=b'{"did":"did:plc:resolved"}';code=200;location=None
  if handle=='fallback' and method.endswith('resolveHandle'):code=400;body=b'{"error":"not-found"}'
  elif handle.startswith('redirect') or handle=='loop':
   step=int(query.get('step',['0'])[0]);maximum=int(handle[-1]) if handle.startswith('redirect') else 99
   if step<maximum:code=302;location=parsed.path+'?'+urllib.parse.urlencode({'handle':handle,'step':step+1})
  elif handle=='badjson':body=b'not-json'
  elif handle=='didzero':body=b'{"did":"0"}'
  elif handle=='didnumber':body=b'{"did":5}'
  elif handle.startswith('fail'):code=int(handle[4:]);body=b'{"error":"FixtureFailure"}'
  elif handle=='budget_timeout':time.sleep(1.5)
  self.send_response(code)
  if location:self.send_header('Location',location)
  self.send_header('Content-Length',str(len(body)));self.end_headers()
  try:self.wfile.write(body)
  except (BrokenPipeError,ConnectionResetError,ssl.SSLError):pass
with tempfile.TemporaryDirectory(prefix='vaak-handle-tls-') as temp:
 cert=pathlib.Path(temp)/'ca.pem';key=pathlib.Path(temp)/'key.pem';leaf=pathlib.Path(temp)/'leaf.pem';cakey=pathlib.Path(temp)/'ca-key.pem';csr=pathlib.Path(temp)/'leaf.csr'
 subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(cakey),'-out',str(cert),'-days','1','-subj','/CN=public.test','-addext','subjectAltName=DNS:public.test'],check=True,capture_output=True)
 subprocess.run(['openssl','req','-new','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(csr),'-subj','/CN=public.test','-addext','subjectAltName=DNS:public.test','-addext','basicConstraints=critical,CA:FALSE'],check=True,capture_output=True)
 subprocess.run(['openssl','x509','-req','-in',str(csr),'-CA',str(cert),'-CAkey',str(cakey),'-CAcreateserial','-out',str(leaf),'-days','1','-copy_extensions','copy'],check=True,capture_output=True)
 server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(leaf,key);server.socket=context.wrap_socket(server.socket,server_side=True);server.requests=[];thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
 env=os.environ.copy();env.update(PROFILE_QUOTE_HANDLE_PORT=str(server.server_port),PROFILE_QUOTE_HANDLE_CERT=str(cert),LD_LIBRARY_PATH='/usr/lib/rustlib/x86_64-unknown-linux-gnu/lib')
 try:
  with open('/tmp/vaak-profile-quote-handle-http-php.log','w') as out:php=subprocess.run([str(ROOT/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so',str(ROOT/'api/bin/profile-quote-handle-http-reference.php')],cwd=ROOT,env=env,stdout=out,stderr=subprocess.STDOUT)
  php.check_returncode();php_requests=list(server.requests);server.requests=[]
  with open('/tmp/vaak-profile-quote-handle-http-native-tests.log','w') as out:native=subprocess.run(['cargo','test','--manifest-path','rust/Cargo.toml','--offline','actual_quote_handle_http_reference','--','--ignored'],cwd=ROOT,env=env,stdout=out,stderr=subprocess.STDOUT)
  result={'php_requests':php_requests,'native_requests':server.requests,'headers_order_exact':php_requests==server.requests,'native_exit':native.returncode}
  pathlib.Path('/tmp/vaak-profile-quote-handle-http-wire-review.json').write_text(json.dumps(result,indent=2))
  if native.returncode or not result['headers_order_exact']:raise RuntimeError('Actual public handle transport comparison failed; logs retained')
  print(f'Actual PHP/native handle16 and {len(server.requests)} TLS request headers/order pass')
 finally:server.shutdown();server.server_close();thread.join(timeout=2)
