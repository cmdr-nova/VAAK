#!/usr/bin/env python3
"""Real PHP cURL caller differentials against declared protocol-v1 faults."""
import argparse, importlib.util, json, os, pathlib, subprocess, threading
from http.server import ThreadingHTTPServer
root=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('faults',root/'api/bin/preview-state-response-fixture.py')
faults=importlib.util.module_from_spec(spec);spec.loader.exec_module(faults)
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--modes',default=','.join(faults.MODES));args=parser.parse_args()
modes=args.modes.split(',');assert set(modes)<=set(faults.MODES)
server=ThreadingHTTPServer(('127.0.0.1',0),faults.Handler)
server.mode=modes[0];server.record=None
threading.Thread(target=server.serve_forever,daemon=True).start()
env={**os.environ,'ROOT_NATIVE_ACCEPT':'1','ROOT_FAULT_COHORT':'1','LIBRARY_FIXTURE_SOCKET':'/tmp/vaak-library-facts-pg-socket',
     'DIRECT_PRODUCTION_WARM_SOURCES':'/tmp/vaak-remote-direct-card-reference.json',
     'ROOT_PREVIEW_REPLAY_BASELINE':'/tmp/vaak-root-preview-callers-production-components-reference.json',
     'VAAK_LIBRARY_NATIVE_RENDER':'0','VAAK_SEARCH_NATIVE_RENDER':'0',
     'VAAK_LIBRARY_NATIVE_SECRET':'independent-preview-fixture-token-0001',
     'VAAK_SEARCH_NATIVE_SECRET':'independent-preview-fixture-token-0001'}
for width in ('INTEGER','BIGINT'):
    env['ROOT_LIBRARY_URL_'+width]=f'http://127.0.0.1:{server.server_port}/internal/library/render'
    env['ROOT_SEARCH_URL_'+width]=f'http://127.0.0.1:{server.server_port}/internal/search/render'
summary=[]
try:
    for mode in modes:
        server.mode=mode
        record=pathlib.Path('/tmp/vaak-root-preview-protocol-'+mode+'-requests.jsonl')
        record.write_text('');server.record=str(record)
        target='/tmp/vaak-root-preview-protocol-'+mode+'-actual.json'
        command=[str(root/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so',str(root/'api/bin/root-preview-callers-production-reference.php'),target]
        result=subprocess.run(command,env=env,text=True,capture_output=True)
        assert result.returncode==0,(mode,result.stdout,result.stderr)
        report='/tmp/vaak-root-preview-protocol-'+mode+'-review.json'
        review=subprocess.run(['python3',str(root/'api/bin/root-preview-callers-native-review.py'),target,'--reference',env['ROOT_PREVIEW_REPLAY_BASELINE'],'--allow-subset','--report',report],text=True,capture_output=True)
        assert review.returncode==0,(mode,review.stdout,review.stderr)
        requests=[json.loads(line) for line in record.read_text().splitlines()]
        assert requests,'real caller cURL never reached declared fault server'
        for request in requests:
            body=request['request'];assert body['protocol_version']==1
            assert type(body['status_preview_budget']) is int
            assert request['path'] in ('/internal/library/render','/internal/search/render')
        parsed=json.loads(pathlib.Path(report).read_text())
        summary.append({'mode':mode,'requests':len(requests),'captures':parsed['captures'],'dom_matches':parsed['whole_caller_dom_matches'],'state_matches':parsed['state_and_recorded_effect_matches'],'failures':parsed['failures']})
        print(f'{mode}: PASS {len(requests)} genuine cURL requests; {parsed["captures"]} whole caller/state pairs',flush=True)
finally:
    server.shutdown();server.server_close()
pathlib.Path('/tmp/vaak-root-preview-protocol-fault-acceptance.json').write_text(json.dumps(summary,indent=2))
