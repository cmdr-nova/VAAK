#!/usr/bin/env python3
"""Actual Library/Search current-own zero string complete-card probe."""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/library-warm-context-independent.py')],text=True)
marker=' println!("INDEPENDENT LIBRARY WARM'
pos=s.index(marker)
s=s[:pos]+r''' db.batch_execute("UPDATE masto_statuses SET content_text='0',spoiler_text='',sensitive=0 WHERE local_id=9;").await?;
 for view in ["bookmarks","search"] {let input=if view=="bookmarks"{serde_json::json!({"status":source.clone(),"_review_canonical_own_id":true,"_review_return_view":"bookmarks"})}else{serde_json::json!({"status":source.clone(),"_review_canonical_own_id":true})};pair(db,1,&input,true,&[]).await?;}
'''+s[pos:]
print(s)
