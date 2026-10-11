#!/usr/bin/env python3
"""Actual PHP notification bridge with bounded loopback HTTP; no database or remote IO."""
import argparse, hashlib, http.server, json, os, pathlib, subprocess, tempfile, threading
ROOT = pathlib.Path(__file__).resolve().parents[2]
def function_source(path):
    text = path.read_text()
    begin = text.index('function ap_masto_notifications_axum_fetch(')
    return text[begin:text.index('\n/**', begin)] if '\n/**' in text[begin:] else text[begin:]
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', type=pathlib.Path, default=ROOT/'api/ap-masto-entities.php')
    parser.add_argument('--output', type=pathlib.Path)
    args = parser.parse_args()
    source = function_source(args.source)
    requests = []
    state = {'code': 200, 'body': '[]'}
    class Handler(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            requests.append(self.path)
            self.send_response(state['code']); self.end_headers(); self.wfile.write(state['body'].encode())
        def log_message(self, *_): pass
    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
    prelude = '''<?php
$homeCalls = 0; $warnings = [];
set_error_handler(function($number, $message) use (&$warnings) {$warnings[] = $message; return true;});
function ap_masto_timeline_home_axum_fetch($limit, $owner) {global $homeCalls; ++$homeCalls; return [['id'=>'home-status','created_at'=>'2026-10-11T00:00:00Z']];}
function ap_db_masto_owner_user_id() {return 7;}
'''
    trailer = '''
$result = ap_masto_notifications_axum_fetch(12, '12345', '54321', ['quote']);
echo json_encode(['result'=>$result,'home_calls'=>$homeCalls,'warnings'=>$warnings,'source'=>$GLOBALS['ap_notif_list_source']??null,'cache_hit'=>$GLOBALS['ap_notif_list_cache_hit']??null]);
'''
    cases = [('empty',200,'[]',[]),('nonempty',200,'[{"id":"100","type":"quote","account":{"id":"9"}}]',[{'id':'100','type':'quote','account':{'id':'9'}}]),('object',200,'{"items":[]}',None),('invalid-json',200,'{',None),('miss',404,'[]',None),('server-error',500,'[]',None)]
    receipts=[]
    try:
        with tempfile.TemporaryDirectory(prefix='vaak-notifications-bridge-') as tmp:
            script = pathlib.Path(tmp)/'probe.php'; script.write_text(prelude+source+trailer)
            for name, code, body, expected in cases:
                state.update(code=code,body=body); requests.clear()
                env=dict(os.environ,VAAK_SHADOW_HTTP=f'http://127.0.0.1:{server.server_port}',VAAK_NOTIF_AXUM_PRIMARY='1')
                completed=subprocess.run([env.get('VAAK_TEST_PHP_BINARY','php'),'-n',str(script)],env=env,capture_output=True,text=True,timeout=3)
                actual=json.loads(completed.stdout)
                checks={'result':actual['result']==expected,'no_home_request':actual['home_calls']==0,'no_warnings':actual['warnings']==[],'one_notification_request':len(requests)==1 and requests[0].startswith('/api/v1/notifications?') and 'max_id=12345' in requests[0] and 'since_id=54321' in requests[0] and 'types=quote' in requests[0], 'clean_process':completed.returncode==0 and completed.stderr==''}
                if expected is not None: checks['cache_metadata']=actual['source']=='axum-shadow' and actual['cache_hit'] is True
                receipts.append({'case':name,'checks':checks,'actual':actual,'requests':list(requests)})
    finally:
        server.shutdown(); server.server_close(); thread.join()
    report={'source_sha256':hashlib.sha256(source.encode()).hexdigest(),'scope':'Actual extracted PHP function; real bounded loopback HTTP; Home stub only records unintended invocation; no live latency claim.','passed':all(all(r['checks'].values()) for r in receipts),'cases':receipts}
    encoded=json.dumps(report,indent=2)+'\n'
    if args.output: args.output.write_text(encoded)
    print(encoded)
    return 0 if report['passed'] else 1
if __name__=='__main__': raise SystemExit(main())
