#!/usr/bin/env python3
"""Native IPC and current-SQL rejection inputs derived from immutable actual PHP positives.
No expected HTML/source/table snapshots are edited; late mutations are explicit transport controls.
"""
import json,copy,pathlib,sys
ref=json.loads(pathlib.Path(sys.argv[1]).read_text());out=[]
for base in ref['captures']:
 if base['case']!='featured_cold_positive' or base['shell']:continue
 controls=[]
 for mode in ['failure','malformed','oversized']:controls.append((mode,{'helper_fault':mode},None,0))
 if base['owner']==0:controls.append(('timeout',{'helper_fault':'timeout'},None,0))
 for mode in ['actor','before','table']:controls.append(('receipt_'+mode,{'receipt_fault':mode},None,3))
 for name,sql in [('source','DELETE FROM ap_featured_accounts WHERE id=802'),('earlier_card',"INSERT INTO actor_profile(actor_key,name) VALUES('other','Changed during later helper')"),('target_settings',"UPDATE actor_profile SET auto_unblur_sensitive='1' WHERE actor_key='fixture'"),('root_policy',"INSERT INTO ap_blocks(id,scope,value,kind) VALUES(91001,'actor','https://mkultra.monster/users/fixture','block')")]:controls.append((name,{'mutation_sql':sql},None,3))
 if base['owner']==2:controls += [('viewer_disabled',{'mutation_sql':"UPDATE ap_users SET disabled_at='withdrawn' WHERE id=2"},None,3),('viewer_role',{'mutation_sql':'UPDATE ap_users SET is_admin=1 WHERE id=2'},None,3)]
 controls.append(('receipt_concurrent',{'after_receipt_sql':"UPDATE remote_media_cache SET public_url='https://wrong.test/concurrent.webp'"},None,3))
 controls.append(('denied_before',{},"INSERT INTO ap_blocks(id,scope,value,kind) VALUES(91001,'actor','https://mkultra.monster/users/fixture','block')",0))
 if base['owner']==2:controls.append(('disabled_before',{},"UPDATE ap_users SET disabled_at='withdrawn' WHERE id=2",0))
 for name,config,before,calls in controls:
  c=copy.deepcopy(base);c['negative_control']=name;c['declared_media_transport'].update(config);c['expected_helper_calls']=calls
  if before:c['negative_before_sql']=before
  if name=='denied_before':c['expected_status']=200
  out.append(c)
keys={(c['width'],c['owner'],c['negative_control'])for c in out};assert len(keys)==len(out)==56
pathlib.Path(sys.argv[2]).write_text(json.dumps({'captures':out,'scope':'native IPC/current-SQL rejection controls derived from unchanged genuine positive current source snapshots'},indent=2))
