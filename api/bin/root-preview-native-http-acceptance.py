#!/usr/bin/env python3
"""Recorded PHP cURL -> unchanged actual Rust handler -> full caller replay."""
import base64, collections, importlib.util, json, os, pathlib, subprocess, threading
from http.server import ThreadingHTTPServer
root=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('transport',root/'api/bin/preview-state-response-fixture.py')
transport=importlib.util.module_from_spec(spec);spec.loader.exec_module(transport)
reference=os.environ.get('ROOT_NATIVE_REFERENCE','/tmp/vaak-root-preview-callers-production-components-reference.json')
env={**os.environ,'ROOT_NATIVE_ACCEPT':'1','LIBRARY_FIXTURE_SOCKET':'/tmp/vaak-library-facts-pg-socket',
     'DIRECT_PRODUCTION_WARM_SOURCES':'/tmp/vaak-remote-direct-card-reference.json',
     'ROOT_PREVIEW_REPLAY_BASELINE':reference,
     'VAAK_LIBRARY_NATIVE_RENDER':'0','VAAK_SEARCH_NATIVE_RENDER':'0',
     'VAAK_LIBRARY_NATIVE_SECRET':'independent-preview-fixture-token-0001',
     'VAAK_SEARCH_NATIVE_SECRET':'independent-preview-fixture-token-0001'}
prefix=os.environ.get('ROOT_NATIVE_OUTPUT_PREFIX','/tmp/vaak-root-preview-native-http')
fixture=os.environ.get('ROOT_NATIVE_FIXTURE','root-preview-callers-production-reference.php')
servers=[];records=[]
for width,port in (('INTEGER',18874),('BIGINT',18875)):
    record=pathlib.Path(prefix+'-'+width.lower()+'-requests.jsonl');record.write_text('');records.append(record)
    server=ThreadingHTTPServer(('127.0.0.1',0),transport.Handler)
    server.mode='forward';server.record=str(record);server.upstream=f'http://127.0.0.1:{port}'
    threading.Thread(target=server.serve_forever,daemon=True).start();servers.append(server)
    env['ROOT_LIBRARY_URL_'+width]=f'http://127.0.0.1:{server.server_port}/internal/library/render'
    env['ROOT_SEARCH_URL_'+width]=f'http://127.0.0.1:{server.server_port}/internal/search/render'
    env['ROOT_NATIVE_TRACE_'+width]=str(record)
target=prefix+'-actual.json'
try:
    result=subprocess.run([str(root/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so',str(root/'api/bin'/fixture),target],env=env,text=True,capture_output=True)
    assert result.returncode==0,(result.stdout,result.stderr)
    review=subprocess.run(['python3',str(root/'api/bin/root-preview-callers-native-review.py'),target,'--reference',reference,'--report',prefix+'-review.json'],text=True,capture_output=True)
    print(review.stdout,flush=True)
    requests=[json.loads(line) for record in records for line in record.read_text().splitlines()]
    assert requests,'actual native request provenance absent'
    counts=collections.Counter();rendered=collections.Counter();states=collections.Counter()
    for request in requests:
        section='library' if '/library/' in request['path'] else 'search';counts[(section,request['response_status'])]+=1
        if request['response_status']==200:
            reply=json.loads(base64.b64decode(request['response_base64']))
            assert reply['protocol_version']==1
            rendered[section]+=len(reply['cards' if section=='library' else 'statuses'])
            state=reply['status_preview_state'];states[(section,state['attempts'])]+=1
    provenance={'requests':len(requests),'response_counts':{str(k):v for k,v in counts.items()},'native_cards':dict(rendered),'attempt_states':{str(k):v for k,v in states.items()},'caller_review_passed':review.returncode==0,'clock_dns_scope':'declared test-only captured render clock/public DNS; real handler authorization/decode/current admission/renderer/response protocol'}
    pathlib.Path(prefix+'-provenance.json').write_text(json.dumps(provenance,indent=2));print(json.dumps(provenance,indent=2),flush=True)
    if os.environ.get('ROOT_EXPECT_ZERO_CARDS')!='1':
        assert all(rendered[section]>0 for section in ('library','search')),'all-fallback replay cannot certify native success'
    else:
        assert any(status==200 for section,status in counts),'zero-card cohort still requires genuine native 200 provenance'
    assert review.returncode==0,review.stderr
finally:
    for server in servers:server.shutdown();server.server_close()
