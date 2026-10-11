#!/usr/bin/env python3
"""Genuine local cURL faults and request binding; synthetic HTML is transport-only."""
import json, os, subprocess, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
root=Path(__file__).resolve().parents[2]
records=[]
mode='success'
class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_POST(self):
        body=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        records.append((self.path,dict(self.headers),body))
        counts={k:len(body[k]) for k in ['accounts','hashtags','statuses']}
        reply={'ok':True,'source_counts':counts,'accounts':['transport-account'],'hashtags':['transport-tag'],'statuses':[{'source_index':0,'html':'transport-status'}]}
        code=200
        if mode=='count': reply['source_counts']['statuses']+=1
        elif mode=='late-slot': reply['statuses'][0]['source_index']=99
        elif mode=='status': code=422
        raw=json.dumps(reply).encode()
        if mode=='invalid-json': raw=b'{'
        elif mode=='invalid-utf8': raw=b'{"ok":true,"x":"\xff"}'
        elif mode=='oversize': raw=b' '*(8*1024*1024+1)
        elif mode=='redirect': code=302
        self.send_response(code)
        if mode=='redirect': self.send_header('Location','/must-not-follow')
        self.end_headers()
        try:self.wfile.write(raw)
        except (BrokenPipeError,ConnectionResetError):pass
server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
threading.Thread(target=server.serve_forever,daemon=True).start()
env={**os.environ,'VAAK_SEARCH_NATIVE_RENDER':'1','VAAK_SEARCH_NATIVE_SECRET':'test-secret-only-'+'s'*32,'VAAK_SEARCH_NATIVE_URL':f'http://127.0.0.1:{server.server_port}/internal/search/render'}
for mode in ['success','count','late-slot','status','invalid-json','invalid-utf8','oversize','redirect']:
    before=len(records)
    result=subprocess.run([str(root/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so',str(root/'api/bin/search-native-bridge-smoke.php')],env={**env,'SEARCH_TRANSPORT_EXPECT':'success' if mode=='success' else 'fallback'},capture_output=True,text=True)
    assert result.returncode==0,(mode,result.stdout,result.stderr)
    assert len(records)==before+1,(mode,'rejected invalid source/guest/cap requests must not send')
    path,headers,body=records[-1]
    assert path=='/internal/search/render'
    assert headers['X-VAAK-Search-Token']==env['VAAK_SEARCH_NATIVE_SECRET']
    assert body['owner_id']==1 and body['csrf']=='current-canonical-session-csrf'
    assert 'return_view' not in body and 'lazy_media' not in body
    assert body['statuses'][0]['quote']['quoted_status']['content']=='Complete cached child'
    print(mode+': '+result.stdout.strip())
server.shutdown()
print('search-native-bridge genuine cURL: PASS 8 modes; original source strict-UTF8 and whole-batch validation')
