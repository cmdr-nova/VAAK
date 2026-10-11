#!/usr/bin/env python3
"""Bind actual recorded request selections to immutable PHP caller captures."""
import argparse, base64, json, pathlib
p=argparse.ArgumentParser(description=__doc__);p.add_argument('capture');p.add_argument('--trace-prefix');p.add_argument('--report',required=True);a=p.parse_args()
rows=json.loads(pathlib.Path(a.capture).read_text())['captures'];out=[];failures=[]
for row in rows:
    t=row['native_trace'];path=pathlib.Path(t['path'])
    if a.trace_prefix:path=pathlib.Path(a.trace_prefix+'-'+row['width'].lower()+'-requests.jsonl')
    requests=[json.loads(line) for line in path.read_bytes()[t['start']:t['end']].splitlines()]
    key=[row['width'],row.get('case'),row.get('class'),row.get('variant'),row['entry'],row['initial_status_budget']]
    if len(requests)!=1:failures.append({'key':key,'request_count':len(requests)});continue
    request=requests[0];selected=request['request']['statuses'];prepared=row['source'];prepared=prepared if isinstance(prepared,list) else [prepared]
    mixed=row['entry']=='library_mixed' and len(selected)==len(prepared)+1
    recollected=(row.get('class') in ('local_missing_row','remote_create_deleted','remote_true_delete','remote_tombstone','remote_legacy_quote_undo') and row['entry']=='search_append')
    prefix=selected[:-1] if mixed else selected
    if recollected:
        if selected or row['php']['result'].get('has_more') is not False:failures.append({'key':key,'error':'withdrawn append collector did not return empty selected window'})
        prefix=prepared
    if prefix!=prepared:failures.append({'key':key,'error':'actual selected AP sources differ from immutable prepared sources'})
    if mixed and request['response_status']!=422:failures.append({'key':key,'error':'mixed unsupported RSS unexpectedly native'})
    reply=json.loads(base64.b64decode(request['response_base64']))
    out.append({'key':key,'clock':row['clock'],'prepared_source_sha256':row['source_sha256'],'prepared_ap_sources':prepared,'actual_selected_request_sources':selected,'additional_rss_provider_source':selected[-1] if mixed else None,'response_status':request['response_status'],'actual_response':reply,'trace':{'path':str(path),'start':t['start'],'end':t['end']},'scope':'actual ap_rss_item_to_masto_status(ap_rss_item_by_id(11)) selected by mixed Library caller; whole-PHP fallback' if mixed else ('actual Search append re-runs genuine ranked collector after withdrawal and selects empty window; prepared pre-withdrawal source retained separately' if recollected else 'actual selected request sources exactly match immutable prepared AP sources')})
report={'captures':len(rows),'bound_requests':len(out),'failures':failures,'records':out};pathlib.Path(a.report).write_text(json.dumps(report,ensure_ascii=False,indent=2));print(json.dumps({'captures':len(rows),'bound_requests':len(out),'failures':failures},indent=2));raise SystemExit(bool(failures))
