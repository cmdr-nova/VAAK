#!/usr/bin/env python3
"""Declared malformed protocol-v1 replies to real local PHP bridges.

This fixture never certifies native HTML. Marker HTML is deliberately returned
only in fault responses, to prove that validation prevents partial output.
"""
import argparse
import base64
import json
import pathlib
import socket
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

MARKER = 'FORBIDDEN PREVIEW PROTOCOL PARTIAL OUTPUT'
MODES = ('extra-top', 'missing-state', 'wrong-version', 'wrong-state-type',
         'extra-state', 'wrong-initial', 'wrong-arithmetic', 'excess-attempts',
         'negative-attempts', 'boolean-attempts', 'wrong-source-count',
         'wrong-source-index', 'duplicate-source-index', 'late-invalid-html',
         'oversize', 'malformed-json', 'http-503', 'closed-connection')


def fault_reply(incoming, mode):
    initial = incoming['status_preview_budget']
    statuses = incoming['statuses']
    state = {'initial': initial, 'remaining': initial, 'attempts': 0}
    cards = [{'source_index': i, 'html': MARKER} for i in range(len(statuses))]
    library = 'library' in incoming.get('_fixture_path', '')
    if library:
        reply = {'protocol_version': 1, 'ok': True, 'source_count': len(statuses),
                 'status_preview_state': state, 'cards': cards}
    else:
        counts = {k: len(incoming[k]) for k in ('accounts', 'hashtags', 'statuses')}
        reply = {'protocol_version': 1, 'ok': True, 'source_counts': counts,
                 'status_preview_state': state, 'statuses': cards,
                 'accounts': [MARKER] * counts['accounts'],
                 'hashtags': [MARKER] * counts['hashtags']}
    if mode == 'extra-top': reply['unknown'] = 1
    elif mode == 'missing-state': del reply['status_preview_state']
    elif mode == 'wrong-version': reply['protocol_version'] = 2
    elif mode == 'wrong-state-type': reply['status_preview_state'] = []
    elif mode == 'extra-state': state['unknown'] = 1
    elif mode == 'wrong-initial': state['initial'] += 1
    elif mode == 'wrong-arithmetic': state.update(attempts=0, remaining=initial-1)
    elif mode == 'excess-attempts': state.update(attempts=len(statuses)+1, remaining=initial-len(statuses)-1)
    elif mode == 'negative-attempts': state['attempts'] = -1
    elif mode == 'boolean-attempts': state['attempts'] = True
    elif mode == 'wrong-source-count':
        if library: reply['source_count'] += 1
        else: reply['source_counts']['statuses'] += 1
    elif mode in ('wrong-source-index', 'duplicate-source-index', 'late-invalid-html'):
        if not cards: cards.append({'source_index': 0, 'html': MARKER})
        if mode == 'wrong-source-index': cards[-1]['source_index'] = len(statuses)
        elif mode == 'duplicate-source-index': cards.append(dict(cards[0]))
        else:
            cards[-1]['html'] = None
            if initial>0 and statuses:state.update(attempts=1,remaining=initial-1)
    elif mode == 'http-503':
        if initial>0 and statuses:state.update(attempts=1,remaining=initial-1)
    elif mode == 'oversize': return b' ' * (8 * 1024 * 1024 + 1)
    elif mode == 'malformed-json': return b'{'
    else: raise ValueError(mode)
    return json.dumps(reply, ensure_ascii=False).encode()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_POST(self):
        raw = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        request = json.loads(raw)
        request['_fixture_path'] = self.path
        status = 200
        if self.server.mode == 'forward':
            if self.path not in ('/internal/library/render','/internal/search/render'):
                self.send_response(404);self.end_headers();return
            forwarded = urllib.request.Request(self.server.upstream+self.path,data=raw,
                headers={key:value for key,value in self.headers.items() if key.lower() not in ('host','content-length','connection')})
            try:
                with urllib.request.urlopen(forwarded,timeout=4) as result:
                    status,reply=result.status,result.read()
            except urllib.error.HTTPError as result:
                status,reply=result.code,result.read()
        elif self.server.mode=='closed-connection':status=None;reply=b''
        else:
            reply = fault_reply(request, self.server.mode)
            if self.server.mode=='http-503':status=503
        if self.server.record:
            with pathlib.Path(self.server.record).open('a') as output:
                output.write(json.dumps({'path': self.path, 'request': request,'headers':dict(self.headers),
                                         'request_base64':base64.b64encode(raw).decode(),
                                         'mode': self.server.mode,
                                         'response_status':status,
                                         'response_base64':base64.b64encode(reply).decode() if self.server.mode in ('forward','late-invalid-html','http-503') else None,
                                         'response_bytes': len(reply)}) + '\n')
        if status is None:
            self.close_connection=True
            self.connection.shutdown(socket.SHUT_RDWR)
            self.connection.close()
            return
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(reply)))
        self.end_headers()
        try: self.wfile.write(reply)
        except (BrokenPipeError, ConnectionResetError): pass


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--port', type=int, default=18879)
    parser.add_argument('--mode', choices=MODES, required=True)
    parser.add_argument('--record')
    args = parser.parse_args()
    server = ThreadingHTTPServer(('127.0.0.1', args.port), Handler)
    server.mode, server.record = args.mode, args.record
    print(f'Declared preview protocol fault server: {args.port} {args.mode}', flush=True)
    server.serve_forever()


if __name__ == '__main__': main()
