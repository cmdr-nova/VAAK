#!/usr/bin/env python3
"""Actual caller fault differentials against independent loopback response fixture.
Requires existing isolated actual worker endpoints for the forward positive.
"""
import argparse,importlib.util,json,os,pathlib,subprocess,threading
parser=argparse.ArgumentParser()
parser.add_argument('--modes',default='forward,unauthorized,unavailable,malformed-json,wrong-ok-type,missing-sections,invalid-utf8-reply,late-invalid-status,oversize,redirect')
args=parser.parse_args()
modes=args.modes.split(',')
assert all(mode in {'forward','unauthorized','unavailable','malformed-json','wrong-ok-type','missing-sections','invalid-utf8-reply','late-invalid-status','oversize','redirect'} for mode in modes)
root=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('search_transport',root/'api/bin/search-native-response-fixture.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
servers=[]
for i,width in enumerate(['INTEGER','BIGINT']):
    server=module.SearchTransportServer(('127.0.0.1',0),'forward',f'http://127.0.0.1:{18874+i}/internal/search/render')
    threading.Thread(target=server.serve_forever,daemon=True).start();servers.append(server)
env={**os.environ,'LIBRARY_FIXTURE_SOCKET':'/tmp/vaak-library-facts-pg-socket','SEARCH_ACCEPT_CASES':'mixed_type_all,status_quote_accepted,complete_media_quote_follower'}
for server,width in zip(servers,['INTEGER','BIGINT']):env['SEARCH_ACCEPT_URL_'+width]=f'http://127.0.0.1:{server.server_port}/internal/search/render'
export=[]
try:
    for mode in modes:
        for server in servers:server.mode=mode;server.records.clear()
        target='/tmp/vaak-search-native-fault-'+mode+'.json'
        result=subprocess.run([str(root/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so',str(root/'api/bin/search-native-callers-acceptance.php'),target],env=env,text=True,capture_output=True)
        assert result.returncode==0,(mode,result.stdout,result.stderr)
        review=subprocess.run(['python3',str(root/'api/bin/search-native-acceptance-review.py'),'--require-pass'],text=True,capture_output=True)
        assert review.returncode==0,(mode,review.stdout,review.stderr)
        parsed=json.loads(review.stdout)
        pathlib.Path('/tmp/vaak-search-native-fault-'+mode+'-pairs.jsonl').write_text(pathlib.Path('/tmp/vaak-search-acceptance-pairs.jsonl').read_text())
        records=[record for server in servers for record in server.records]
        assert records,'real cURL must reach fault/forward server'
        assert all(record['path']=='/internal/search/render' for record in records)
        assert all(record['headers']['X-VAAK-Search-Token']=='independent-search-acceptance-token-0001' for record in records)
        if mode=='forward':assert any(record['response_status']==200 for record in records),'positive actual Rust response required'
        if mode=='redirect':assert len(records)==len([r for r in records if r['path']=='/internal/search/render']),'redirect must not be followed'
        export.append({'mode':mode,'pairs':parsed['pairs'],'parsed_matches':parsed['parsed_matches'],'requests':len(records),'response_statuses':sorted(set(record['response_status'] for record in records))})
        print(mode+': PASS '+str(parsed['pairs'])+' actual caller pairs; '+str(len(records))+' genuine cURL requests',flush=True)
finally:
    for server in servers:server.shutdown();server.server_close()
pathlib.Path('/tmp/vaak-search-native-transport-acceptance.json').write_text(json.dumps(export,indent=2))
