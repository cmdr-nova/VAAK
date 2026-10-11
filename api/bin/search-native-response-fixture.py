#!/usr/bin/env python3
"""Loopback transport fault fixture for the real PHP Search cURL bridge.

It records actual incoming request bytes/headers. It never manufactures native
presentation HTML. --upstream forwards bytes unchanged to an isolated actual
Rust worker; other modes return declared malformed/error transport responses.
"""
import argparse, base64, json, pathlib, urllib.error, urllib.request
from urllib.parse import urlsplit
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

class SearchTransportServer(ThreadingHTTPServer):
    def __init__(self, address, mode='malformed-json', upstream=None, log=None):
        if upstream:
            parsed=urlsplit(upstream)
            if (parsed.scheme != 'http' or parsed.hostname not in ('127.0.0.1','::1')
                or parsed.path != '/internal/search/render' or parsed.username
                or parsed.password or parsed.query or parsed.fragment):
                raise ValueError('upstream must be isolated loopback Search endpoint')
        self.mode, self.upstream, self.log = mode, upstream, log
        self.records = []
        super().__init__(address, SearchTransportHandler)

class SearchTransportHandler(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_GET(self):
        self.server.records.append({'path':self.path,'method':'GET','response_status':405})
        self.send_response(405);self.end_headers()
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        record = {'path': self.path, 'headers': dict(self.headers),
                  'body_base64': base64.b64encode(body).decode()}
        self.server.records.append(record)
        mode = self.server.mode
        status, response = 200, b''
        if mode == 'forward':
            if not self.server.upstream: raise RuntimeError('forward requires upstream')
            request = urllib.request.Request(self.server.upstream, data=body,
                headers={k:v for k,v in self.headers.items() if k.lower() not in ('host','content-length','connection')})
            try:
                with urllib.request.urlopen(request, timeout=4) as result:
                    status, response = result.status, result.read()
            except urllib.error.HTTPError as error:
                status, response = error.code, error.read()
        elif mode == 'unauthorized': status, response = 401, b'{"ok":false}'
        elif mode == 'unavailable': status, response = 503, b'{"ok":false}'
        elif mode == 'malformed-json': response = b'{'
        elif mode == 'wrong-ok-type': response = b'{"ok":"true","sections":{}}'
        elif mode == 'missing-sections': response = b'{"ok":true}'
        elif mode == 'invalid-utf8-reply': response = b'{"ok":true,"bad":"\xff"}'
        elif mode == 'late-invalid-status':
            incoming=json.loads(body)
            counts={section:len(incoming[section]) for section in ('accounts','hashtags','statuses')}
            response=json.dumps({'ok':True,'source_counts':counts,
                'accounts':['FORBIDDEN NATIVE ACCOUNT PARTIAL']*counts['accounts'],
                'hashtags':['FORBIDDEN NATIVE TAG PARTIAL']*counts['hashtags'],
                'statuses':[{'source_index':counts['statuses']+1,'html':'FORBIDDEN NATIVE STATUS PARTIAL'}]}).encode()
        elif mode == 'oversize': response = b' ' * (8*1024*1024+1)
        elif mode == 'redirect': status, response = 302, b''
        else: raise RuntimeError('unknown declared transport fault '+mode)
        record['response_status']=status
        record['response_bytes']=len(response)
        if mode=='forward': record['response_base64']=base64.b64encode(response).decode()
        if self.server.log:
            with pathlib.Path(self.server.log).open('a') as f: f.write(json.dumps(record)+'\n')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(response)))
        if mode == 'redirect': self.send_header('Location', '/redirect-must-not-be-followed')
        self.end_headers()
        try: self.wfile.write(response)
        except (BrokenPipeError, ConnectionResetError): pass

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--port',type=int,default=18873)
    parser.add_argument('--mode',default='malformed-json',choices=['forward','unauthorized','unavailable','malformed-json','wrong-ok-type','missing-sections','invalid-utf8-reply','late-invalid-status','oversize','redirect'])
    parser.add_argument('--upstream');parser.add_argument('--log',default='/tmp/vaak-search-response-fixture.jsonl')
    args=parser.parse_args()
    server=SearchTransportServer(('127.0.0.1',args.port),args.mode,args.upstream,args.log)
    print('Search transport fixture on loopback port '+str(server.server_port),flush=True)
    server.serve_forever()
if __name__ == '__main__': main()
