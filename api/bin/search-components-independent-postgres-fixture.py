#!/usr/bin/env python3
"""Additional actual-PDO adversarial component controls, no runtime edits."""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/search-components-postgres-fixture.py')],text=True)
s=s.replace('INSERT INTO masto_account_actors VALUES($1,$2)','INSERT INTO masto_account_actors(account_id,actor_id) VALUES($1,$2) ON CONFLICT(account_id) DO NOTHING')
s=s.replace('CREATE TABLE masto_account_actors(account_id TEXT,actor_id TEXT);','CREATE TABLE masto_account_actors(account_id TEXT UNIQUE,actor_id TEXT,updated_at TEXT);CREATE TABLE direct_messages(id INTEGER,peer_actor_id TEXT,direction TEXT,read_at TEXT,owner_user_id INTEGER,deleted_at TEXT);')
s=s.replace("INSERT INTO masto_account_actors VALUES('900000001'","INSERT INTO masto_account_actors(account_id,actor_id) VALUES('900000001'")
s=s.replace('search_components_{}','search_components_review_{}').replace('search-components-php-postgres.php','search-components-independent-php-postgres.php')
needle='  let inputs:Vec<serde_json::Value>=cases.iter()'
extra=r'''
  // Case-sensitive presentation follows and case-folded numeric CRC are separate rules.
  cases.push((1,mutate(serde_json::json!({"uri":"https://remote.test/users/bob","url":"https://remote.test/@bob"}))));
  cases.push((1,mutate(serde_json::json!({"uri":"https://nocache.test/users/alice","url":"https://nocache.test/@alice"}))));
  let bob_id=php(&schema,1,&serde_json::json!({"actor":"https://remote.test/@bob"}),"id").await?["id"].as_str().unwrap().to_owned();
  cases.push((1,mutate(serde_json::json!({"id":bob_id,"uri":"https://identity.test/users/not-followed","url":"https://identity.test/@not-followed"}))));
  // Mapped numeric fallback must consider current dynamic canonical aliases.
  db.batch_execute("INSERT INTO masto_account_actors(account_id,actor_id) VALUES('900000003','https://remote.test/ap/users/123456');").await?;
  cases.push((1,mutate(serde_json::json!({"id":"900000003"}))));
'''
assert needle in s;s=s.replace(needle,extra+needle)
needle='  db.batch_execute("UPDATE ap_users SET disabled_at='
extra=r'''

  // Actual canonical profile-key lookup order outranks cache timestamps.
  db.batch_execute("DELETE FROM bsky_actor_profiles WHERE did='did:plc:friend';INSERT INTO bsky_actor_profiles VALUES('https://bsky.app/profile/did:plc:friend','did:plc:friend','{\"handle\":\"ordered.test\"}','2026-10-10'),('https://bsky.app/profile/did%3Aplc%3Afriend','did:plc:friend','{\"handle\":\"newer.test\"}','2026-10-11');").await?;
  let ordered=mutate(serde_json::json!({"uri":"https://bsky.app/profile/ordered.test","url":"https://bsky.app/profile/ordered.test"}));
  let canonical=php(&schema,1,&ordered,"account").await?;
  assert!(canonical["html"].as_str().unwrap().contains("You follow them"),"actual PHP prefers earlier exact plain URL key over newer encoded-key row");
  let ordered_token=search_components::ComponentContext::load(&db,Some(1),&[ordered.clone()]).await?.resolve_account(&ordered)?;
  assert_eq!(search_render::render_account(&ordered_token),canonical["html"].as_str().unwrap(),"Bsky exact profile-key lookup precedence");

  for (actor_key,handle,date) in [("did:plc:friend","exact.test","2026-10-09"),("unrelated-public-profile-key","latest.test","2026-10-12")] {
   if actor_key=="unrelated-public-profile-key" {db.batch_execute("DELETE FROM bsky_actor_profiles WHERE did='did:plc:friend';").await?;}
   let profile=serde_json::json!({"handle":handle}).to_string();db.execute("INSERT INTO bsky_actor_profiles VALUES($1,'did:plc:friend',$2,$3)",&[&actor_key,&profile,&date]).await?;
   let input=mutate(serde_json::json!({"uri":format!("https://bsky.app/profile/{handle}"),"url":format!("https://bsky.app/profile/{handle}")}));
   let token=search_components::ComponentContext::load(&db,Some(1),&[input.clone()]).await?.resolve_account(&input)?;
   assert_eq!(search_render::render_account(&token),php(&schema,1,&input,"account").await?["html"].as_str().unwrap(),"exact DID priority and latest-by-DID fallback");
  }
  db.batch_execute("INSERT INTO bsky_actor_profiles VALUES('did:plc:friend','did:plc:friend','invalid-json','2026-10-01');").await?;
  assert!(php(&schema,1,&serde_json::json!({"actor":"did:plc:friend"}),"profile").await?.is_null(),"canonical selected malformed JSON must not fall through to later valid rows");
  let malformed_handle=mutate(serde_json::json!({"uri":"https://bsky.app/profile/latest.test","url":"https://bsky.app/profile/latest.test"}));
  assert!(search_components::ComponentContext::load(&db,Some(1),&[malformed_handle.clone()]).await?.resolve_account(&malformed_handle).is_err(),"malformed chosen key cannot invent handle relationship");
  let did_input=mutate(serde_json::json!({"uri":"https://bsky.app/profile/did:plc:friend","url":"https://bsky.app/profile/did:plc:friend"}));
  let did_token=search_components::ComponentContext::load(&db,Some(1),&[did_input.clone()]).await?.resolve_account(&did_input)?;
  assert_eq!(search_render::render_account(&did_token),php(&schema,1,&did_input,"account").await?["html"].as_str().unwrap(),"malformed handle profile must retain durable DID following");
  // Immutable token inputs and batches are bound; malformed/cold branches cannot guess false.
  let bound=search_components::ComponentContext::load(&db,Some(2),&[base.clone()]).await?;
  let mut spoof=base.clone();spoof["display_name"]=serde_json::json!("Unbound");assert!(bound.resolve_account(&spoof).is_err());
  let guest_numeric=mutate(serde_json::json!({"id":"900000001"}));assert!(search_components::ComponentContext::load(&db,None,&[guest_numeric.clone()]).await?.resolve_account(&guest_numeric).is_err());
  assert!(search_components::ComponentContext::load(&db,Some(1),&vec![base.clone();101]).await.is_err());
  assert!(search_components::ComponentContext::load(&db,Some(1),&[serde_json::json!(null)]).await.is_err());
  let tag=serde_json::json!({"name":"rust_tag","following":true});
  let after_tag=search_components::ComponentContext::load(&db,Some(1),&[]).await?.resolve_tag(&tag)?;
  assert_eq!(search_render::render_tag(&after_tag),php(&schema,1,&tag,"tag").await?["html"].as_str().unwrap(),"withdrawn current tag follow overrides cached true");
  db.batch_execute("DELETE FROM bsky_graph_sync WHERE owner_user_id=1;").await?;
  let disconnected=mutate(serde_json::json!({"uri":"https://bsky.app/profile/friend.test","url":"https://bsky.app/profile/friend.test"}));
  assert!(search_components::ComponentContext::load(&db,Some(1),&[disconnected.clone()]).await?.resolve_account(&disconnected).is_err(),"removed graph cannot reuse stale connected handle proof");
'''
assert needle in s;s=s.replace(needle,extra+needle)
print(s)
