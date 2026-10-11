#!/usr/bin/env python3
"""Publish final protocol/transport evidence without overwriting prior diagnostics."""
import base64, hashlib, json, pathlib
old=json.loads(pathlib.Path('/tmp/vaak-root-preview-protocol-first16-acceptance.json').read_text())
latest=json.loads(pathlib.Path('/tmp/vaak-root-preview-protocol-fault-acceptance.json').read_text())
merged={row['mode']:row for row in old};merged.update({row['mode']:row for row in latest});assert len(merged)==18
records=[]
for mode,summary in merged.items():
    paths={kind:pathlib.Path('/tmp/vaak-root-preview-protocol-'+mode+'-'+suffix) for kind,suffix in [('capture','actual.json'),('review','review.json'),('transport','requests.jsonl')]}
    review=json.loads(paths['review'].read_text());assert review['captures']==80 and review['whole_caller_dom_matches']==80 and review['state_and_recorded_effect_matches']==80 and not review['failures']
    transports=[json.loads(line) for line in paths['transport'].read_text().splitlines()];assert len(transports)==80
    evidence={}
    if mode=='late-invalid-html':
        consumed=prefix=0
        for trace in transports:
            reply=json.loads(base64.b64decode(trace['response_base64']));state=reply['status_preview_state'];cards=reply.get('cards',reply.get('statuses'))
            assert cards[-1]['html'] is None
            if trace['request']['status_preview_budget']>0 and trace['request']['statuses']:
                assert state['attempts']==1 and state['remaining']==state['initial']-1;consumed+=1
                if len(cards)>1:
                    assert cards[0]['html']=='FORBIDDEN PREVIEW PROTOCOL PARTIAL OUTPUT';prefix+=1
        assert consumed==64 and prefix==32;evidence={'actual_valid_consumed_states_rejected':consumed,'actual_two_source_marker_prefix_then_null_rejected':prefix}
    if mode=='http-503':assert all(t['response_status']==503 for t in transports)
    if mode=='closed-connection':assert all(t['response_status'] is None and t['response_bytes']==0 for t in transports)
    records.append({**summary,**evidence,'artifacts':{kind:{'path':str(path),'sha256':hashlib.sha256(path.read_bytes()).hexdigest()} for kind,path in paths.items()}})
report={'protocol_modes':16,'transport_modes':2,'selected_caller_pairs':1440,'whole_dom_matches':1440,'raw_state_and_recorded_effect_matches':1440,'failures':[],'prior16_summary':'/tmp/vaak-root-preview-protocol-first16-acceptance.json','late_invalid_supersession':'valid consumed-state response bytes now recorded; earlier untouched-state and unrecorded consumed-state runs retained separately','scope':'real PHP cURL consumers; reduced immutable80 per mode across both PostgreSQL widths, Library/Search full/append, five raw states and ordered two-source failures; HTTP503 and closed connection measured; no timeout claim','modes':records}
pathlib.Path('/tmp/vaak-root-preview-protocol-final18-manifest.json').write_text(json.dumps(report,indent=2));print(json.dumps({k:v for k,v in report.items() if k!='modes'},indent=2))
