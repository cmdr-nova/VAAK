#!/usr/bin/env python3
"""Independent strict-envelope/owner tests layered on actual HTTP fixture.
Requires disposable fixture container/socket and independently built worker.
"""
import os,pathlib
root=pathlib.Path(__file__).resolve().parents[2]
source=(root/'api/bin/library-native-http-fixture.py').read_text()
source=source.replace("root=pathlib.Path(__file__).resolve().parents[2]",f'root=pathlib.Path({str(root)!r})')
source=source.replace("worker=root/'rust/target/debug/vaak-worker'","worker=pathlib.Path(os.environ['LIBRARY_REVIEW_WORKER'])")
marker=" assert request({**batch,'owner_id':999})[0]==422,'inactive/missing DB owner'"
extra=r'''
 assert request({**batch,'lazy_media':False})[0]==200
 assert request({**batch,'lazy_media':True})[0]==200
 assert request({**batch,'lazy_media':'true'})[0]==400
 assert request({**batch,'csrf':''})[0]==400
 assert request({**batch,'csrf':'x'*4097})[0]==400
 assert request({**batch,'return_view':'settings'})[0]==400
 assert request({**batch,'statuses':['untrusted-string']})[0]==400
 assert request({**batch,'bsky_bookmarks':[{'uri':'at://did:plc:a/app.bsky.feed.post/x','did':'did:plc:viewer','bookmarked':True,'is_admin':True}]})[0]==400
 assert request({**batch,'bsky_bookmarks':[{'uri':'at://did:plc:a/app.bsky.feed.post/x','did':'did:plc:viewer','bookmarked':'true'}]})[0]==400
 assert request({**batch,'bsky_bookmarks':[{'uri':'at://did:plc:a/app.bsky.feed.post/x','did':'did:plc:viewer','bookmarked':True}]*101})[0]==400
 subprocess.run(['docker','exec',container,'psql','-U','postgres','-c',"SET search_path TO library_facts_integer; INSERT INTO bsky_sessions VALUES(1,'did:plc:viewer','viewer.test'),(2,'did:plc:other','other.test');"],check=True,stdout=subprocess.DEVNULL)
 provenance={'uri':'at://did:plc:a/app.bsky.feed.post/x','did':'did:plc:viewer','bookmarked':False}
 assert request({**batch,'bsky_bookmarks':[provenance]})[0]==400,'unrepresented provenance URI denied'
 bound={**batch,'statuses':[{'post':{'uri':provenance['uri']}}]}
 assert request({**bound,'bsky_bookmarks':[provenance]})[0]==422,'represented valid display state reaches source fallback'
 assert request({**bound,'bsky_bookmarks':[provenance,provenance]})[0]==400,'duplicate URI provenance denied'
 assert request({**bound,'bsky_bookmarks':[{**provenance,'did':'did:plc:other'}]})[0]==422,'foreign session cannot bind display state'
 assert request({**bound,'owner_id':2,'bsky_bookmarks':[provenance]})[0]==422,'current owner/session isolation'
 for invalid in ['https://bsky.app/profile/a/post/x','at:///app.bsky.feed.post/x','at://did:plc:a/app.bsky.feed.post/','at://did:plc:a/app.bsky.feed.post/x/y','at://did:plc:a/app.bsky.feed.post/x?owner=2','at://did:plc:a/app.bsky.feed.post/x#suffix']:
  bad={**batch,'statuses':[{'post':{'uri':invalid}}],'bsky_bookmarks':[{**provenance,'uri':invalid}]}
  assert request(bad)[0]==400,'noncanonical exact AT target denied'
 subprocess.run(['docker','exec',container,'psql','-U','postgres','-c',"SET search_path TO library_facts_integer; UPDATE ap_users SET disabled_at='disabled' WHERE id=2;"],check=True,stdout=subprocess.DEVNULL)
 assert request({**batch,'owner_id':2})[0]==422,'disabled owner denied'
 subprocess.run(['docker','exec',container,'psql','-U','postgres','-c',"SET search_path TO library_facts_integer; UPDATE ap_users SET disabled_at=NULL WHERE id=2;"],check=True,stdout=subprocess.DEVNULL)
 print('library-independent-http: PASS (23 extra real strict/session/provenance controls; server-only display, no mutation proof)')
'''
assert marker in source
exec(compile(source.replace(marker,marker+extra),str(root/'api/bin/library-native-http-fixture.py'),'exec'))
