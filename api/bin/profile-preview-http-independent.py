#!/usr/bin/env python3
"""Own one ephemeral HTTP server for genuine PHP versus native transport."""
import http.server,json,os,pathlib,subprocess,threading,time
ROOT=pathlib.Path(__file__).resolve().parents[2]
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_GET(self):
        self.server.requests.append({'host':self.headers.get('Host'),'path':self.path,'ua':self.headers.get('User-Agent'),'accept':self.headers.get('Accept'),'language':self.headers.get('Accept-Language')})
        case=self.path.split('?',1)[0].strip('/'); body=b'<title>Native wire</title>';code=200;location=None
        if case=='empty':body=b''
        elif case in ('big','over-max'):body=b'x'*1025
        elif case=='exact-max':body=b'x'*1024
        elif case=='redirect-absolute':code=302;location=f'http://public.test:{self.server.server_port}/ok'
        elif case=='redirect-relative':code=301;location='ok'
        elif case=='loop':code=302;step=int(self.path.split('next=',1)[1]) if 'next=' in self.path else 0;location=f'/loop?next={step+1}'
        elif case=='missing-location':code=302
        elif case=='private-redirect':code=302;location=f'http://private.test:{self.server.server_port}/ok'
        elif case=='fail500':code=500
        elif case=='429':code=429
        elif case=='ctype-json':body=b'{"plain":"json"}'
        elif case=='slow':time.sleep(1.5)
        self.send_response(code)
        if location:self.send_header('Location',location)
        self.send_header('Content-Length',str(len(body)));self.end_headers()
        try:self.wfile.write(body)
        except (BrokenPipeError,ConnectionResetError):pass
server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);server.requests=[]
thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
env=os.environ.copy();env['PROFILE_PREVIEW_HTTP_PORT']=str(server.server_port);env['LD_LIBRARY_PATH']='/usr/lib/rustlib/x86_64-unknown-linux-gnu/lib'
try:
    php=subprocess.run([str(ROOT/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so',str(ROOT/'api/bin/profile-preview-http-reference.php'),'/tmp/vaak-profile-preview-http-reference.json'],cwd=ROOT,env=env,capture_output=True,text=True)
    pathlib.Path('/tmp/vaak-profile-preview-http-php.log').write_text(php.stdout+php.stderr)
    php.check_returncode()
    php_requests=list(server.requests);server.requests=[]
    with open('/tmp/vaak-profile-preview-http-native-tests.log','w') as out:
        native=subprocess.run(['cargo','test','--manifest-path','rust/Cargo.toml','--offline','actual_preview_native_http_reference','--','--ignored'],cwd=ROOT,env=env,stdout=out,stderr=subprocess.STDOUT)
    native_requests=list(server.requests)
    result={'php_requests':php_requests,'native_requests':native_requests,'request_headers_order_exact':php_requests==native_requests,'native_exit':native.returncode,'scope':'Actual owned HTTP transport and request headers/redirect/timeout/body bound. No production network.'}
    pathlib.Path('/tmp/vaak-profile-preview-http-wire-review.json').write_text(json.dumps(result,indent=2))
    if native.returncode or not result['request_headers_order_exact']:raise RuntimeError('native HTTP transport comparison failed; original logs retained')
    print(f"Actual PHP/native HTTP14 controls and {len(native_requests)} request headers/order pass")
finally:
    server.shutdown();server.server_close();thread.join(timeout=2)
