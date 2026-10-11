#!/usr/bin/env python3
"""Independent adversarial extension of actual same-DB PHP/Rust ranked proof."""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/search-ranked-postgres-fixture.py')],text=True)
marker='  for (owner,query,tag,limit) in '
at=s.index(marker)
extra=r'''
  db.batch_execute("INSERT INTO ap_users VALUES(101,'underscored_1','https://mkultra.monster/users/underscored_1'),(102,'underscoredx1','https://mkultra.monster/users/underscoredx1');INSERT INTO actor_profile VALUES('underscored_1',0),('underscoredx1',1);INSERT INTO masto_statuses VALUES(101),(102);INSERT INTO events VALUES(60,'https://remote.test/users/a'),(61,'https://remote.test/users/a'),(62,'https://remote.test/users/a');INSERT INTO bsky_posts VALUES('at://did:plc:c/app.bsky.feed.post/60');
  INSERT INTO ap_search_docs VALUES('status',101,'https://mkultra.monster/users/underscored_1/notes/101','2026-10-10T10:00:01Z','independent'),('status',102,'https://mkultra.monster/users/underscoredx1/notes/102','2026-10-10T10:00:02Z','independent'),('event',60,'https://remote.test/unicode/60','2026-10-10T10:00:03Z','ix ix ix σος étude 漢字１２ #a htag_a'),('event',61,'https://remote.test/unicode/61','2026-10-10T10:00:04Z','ix alpha #rustXtag htag_rust_tag'),('event',62,'https://remote.test/unicode/62','2026-10-10T10:00:05Z','ix alpha #rust_tag htag_rust_tag'),('bsky_post',60,'at://did:plc:c/app.bsky.feed.post/60','2026-10-10T10:00:06Z','ix ix #rust_tag htag_rust_tag');").await?;
  for (owner,query,tag,limit) in [(1,"independent","",40),(101,"independent","",40),(1,"İX","",40),(1,"ΣΟΣ","",40),(1,"ÉTUDE","",40),(1,"漢字１２","",40),(1,"\u{a0}ix\u{2009}alpha","",40),(1,"\"ix\" AND alpha:*","",40),(1,"foo%bar_baz","",40),(1,"ignored","a",40),(1,"ignored","rust_tag",2),(1,"!!!","",40),(1,"ix","",0)] {
   let native=search::ranked_candidates(&db,owner,query,tag,limit).await?;
   let php=std::process::Command::new("ROOT/api/bin/php-policy-test").args(["-d","extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so","ROOT/api/bin/search-ranked-php-postgres.php",&schema,&owner.to_string(),query,tag,&limit.to_string()]).output()?;
   assert!(php.status.success(),"{}",String::from_utf8_lossy(&php.stderr));let expected:serde_json::Value=serde_json::from_slice(&php.stdout)?;
   let mut actual=serde_json::to_value(native)?;for row in actual.as_array_mut().unwrap(){row.as_object_mut().unwrap().remove("status");}assert_eq!(actual,expected,"independent same-DB Unicode/operator/tag/rank/owner {width} q{query} tag{tag}");
  }
  let stranger=search::ranked_candidates(&db,1,"independent","",40).await?;assert_eq!(stranger.len(),1);assert_eq!(stranger[0].source_pk,102,"literal underscore owner prefix");
  assert_eq!(search::ranked_candidates(&db,101,"independent","",40).await?.len(),2,"author indexing exception");
  db.batch_execute("DELETE FROM ap_search_docs WHERE source_pk IN (60,61,62,101,102);DELETE FROM bsky_posts WHERE bsky_uri='at://did:plc:c/app.bsky.feed.post/60';").await?;
  println!("INDEPENDENT SEARCH RANKED {width} PASS13 actual paired query/Unicode/operator/tag/blend/order/rank/owner controls");
'''.replace('ROOT',str(root))
print(s[:at]+extra+s[at:])
