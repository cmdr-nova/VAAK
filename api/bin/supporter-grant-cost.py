#!/usr/bin/env python3
"""Current actual grant-query plans and repeated PHP cached-profile caller cost."""
import pathlib,json,subprocess,os,hashlib
R=pathlib.Path(__file__).resolve().parents[2];O=R/'rust/target/parity-evidence/supporter-native-authority';S=O/'indexed-replay/source'
env=dict(os.environ,LD_PRELOAD=str(O/'loopback-only.so'),VAAK_FIXTURE_NET_DENIAL_LOG=str(O/'denials.log'))
php=[str(S/'api/bin/php-supporter-isolated'),'-d','extension=/tmp/vaak-php-mbstring-test/usr/lib/php/20230831/mbstring.so','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so']
def db(sql=None,query=None,schema=None):
 p=subprocess.run(php+[str(O/'control.php')],input=json.dumps({k:v for k,v in dict(sql=sql,query=query,schema=schema).items() if v is not None}),text=True,capture_output=True,env=env,check=True);assert not p.stderr,p.stderr;return json.loads(p.stdout) if p.stdout else None
results=[]
try:
 for width in ['INTEGER','BIGINT']:
  schema='supporter_cost_'+width.lower();db(sql='CREATE SCHEMA '+schema)
  db(sql='CREATE TABLE ap_users(id '+width+" PRIMARY KEY,actor_key TEXT UNIQUE,disabled_at TEXT);INSERT INTO ap_users VALUES(1,'fixture',NULL),(2,'viewer',NULL);CREATE TABLE ap_supporter_codes(code_hash TEXT PRIMARY KEY,source_ref TEXT UNIQUE NOT NULL,email_hash TEXT NOT NULL,receipt_hash TEXT NOT NULL,created_at TEXT NOT NULL,expires_at TEXT,used_by BIGINT,used_at TEXT);CREATE INDEX idx_supporter_used_by ON ap_supporter_codes(used_by) WHERE used_at IS NOT NULL;INSERT INTO ap_supporter_codes SELECT i::text,i::text,'fixture-email','fixture-receipt','now',NULL,i+1000,'now' FROM generate_series(1,50000) i;INSERT INTO ap_supporter_codes VALUES('subject','subject','email','receipt','now',NULL,1,'now'),('viewer','viewer','email','receipt','now',NULL,2,'now');ANALYZE ap_supporter_codes;ANALYZE ap_users",schema=schema)
  digest="SELECT jsonb_build_object('count',COUNT(*),'digest',md5(COALESCE(string_agg(j::text,'' ORDER BY j::text),''))) FROM (SELECT to_jsonb(t) j FROM ap_supporter_codes t) q WHERE (ARRAY['a']::text[] IS NOT NULL AND ARRAY['1','2']::text[] IS NOT NULL AND 'fixture'::text IS NOT NULL) AND (j->>'used_by'=ANY(ARRAY['1','2']) AND j->>'used_at' IS NOT NULL)"
  candidate=digest.replace('FROM ap_supporter_codes t',"FROM ap_supporter_codes t WHERE used_by=ANY(ARRAY['1','2']::text[]::bigint[]) AND used_at IS NOT NULL")
  before=db(query=digest,schema=schema);after=db(query=candidate,schema=schema);assert before==after
  plans={k:db(query='EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) '+v,schema=schema) for k,v in [('before',digest),('candidate',candidate)]}
  vectors=[]
  for name,sql in [('reserved',"INSERT INTO ap_supporter_codes VALUES('reserved','reserved','email','receipt','now',NULL,1,NULL)"),('null-owner',"INSERT INTO ap_supporter_codes VALUES('null','null','email','receipt','now',NULL,NULL,'now')"),('duplicate-account',"INSERT INTO ap_supporter_codes VALUES('another','another','email','receipt','now',NULL,1,'now')"),('empty-used-time',"INSERT INTO ap_supporter_codes VALUES('empty','empty','email','receipt','now',NULL,2,'')"),('receipt-edit',"UPDATE ap_supporter_codes SET receipt_hash='changed' WHERE code_hash='subject'"),('withdraw',"UPDATE ap_supporter_codes SET used_by=NULL,used_at=NULL WHERE code_hash='subject'"),('integer-used-by','ALTER TABLE ap_supporter_codes ALTER COLUMN used_by TYPE INTEGER')]:
   db(sql=sql,schema=schema);a=db(query=digest,schema=schema);b=db(query=candidate,schema=schema);assert a==b,name;vectors.append(dict(name=name,digest=a))
  # Restore actual production grant-column inventory before caller timing.
  db(sql='ALTER TABLE ap_supporter_codes ALTER COLUMN used_by TYPE BIGINT;ANALYZE ap_supporter_codes',schema=schema)
  costscript=O/'php-caller-cost.php';costscript.write_text('''<?php
require __DIR__.'/indexed-replay/source/api/bin/migration-reference-functions.php';
class CounterPDO extends PDO {
 public int $queries=0; public int $grant=0;
 public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {$this->queries++;if(str_contains($query,'ap_supporter_codes'))$this->grant++;return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args);}
 public function prepare(string $query,array $options=[]):PDOStatement|false {$this->queries++;if(str_contains($query,'ap_supporter_codes'))$this->grant++;return parent::prepare($query,$options);}
}
$d=new CounterPDO('pgsql:host=/tmp/vaak-library-facts-pg-socket dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$d->exec('SET search_path='.$argv[1]);
function ap_db():PDO{return $GLOBALS['d'];}
function ap_redis_json_get(string $key):mixed{return ['actor_key'=>'fixture','name'=>'Cached subject','summary'=>'cached','profile_badges'=>['supporter','cat','dog','coffee','tea','bookworm','gamer']];}
reference_load(__DIR__.'/indexed-replay/source/api/ap-db.php',['ap_profile_get','ap_profile_authorized_badges','ap_profile_supporter_awarded','ap_profile_normalize_badges','ap_profile_badge_catalog']);
$rows=[];
foreach(['awarded','withdrawn','missing_table'] as $case){if($case==='withdrawn')$d->exec('UPDATE ap_supporter_codes SET used_by=NULL,used_at=NULL WHERE used_by=1');if($case==='missing_table')$d->exec('DROP TABLE ap_supporter_codes');$d->queries=0;$d->grant=0;$start=hrtime(true);$badges=[];for($i=0;$i<40;$i++){$p=ap_profile_get('fixture');$badges[]=$p['profile_badges'];}echo ''; $rows[]=['case'=>$case,'calls'=>40,'queries'=>$d->queries,'grant_queries'=>$d->grant,'ms'=>(hrtime(true)-$start)/1e6,'badges'=>$badges];}
echo json_encode($rows);
''')
  p=subprocess.run(php+[str(costscript),schema],env=env,capture_output=True,text=True,check=True);assert not p.stderr,p.stderr;rows=json.loads(p.stdout)
  for row in rows:
   assert row['queries']==(40 if row['case']=='missing_table' else 80)
   for badges in row['badges']:assert len(badges)==(7 if row['case']=='awarded' else 6) and ('supporter' in badges)==(row['case']=='awarded')
  results.append(dict(width=width,row_count=50002,before_sql=digest,candidate_sql=candidate,digest=before,plans=plans,digest_vectors=vectors,php_repeated_current_calls=rows,scope='Declared 50k actual production reward DDL and both account ID widths; additional INTEGER used_by variation. Actual SQL with scalar substitutions matching captured prepared SQL. Real current PHP warm getter declarations; cache transport declared, zero warnings. Wall times fixture-only, not production latency.'))
  db(sql='DROP SCHEMA '+schema+' CASCADE')
 (O/'cost.json').write_text(json.dumps(results,indent=2));print('grant-cost: PASS both widths; exact digest, actual plans, actual PHP40 warm caller cost')
finally:
 for width in ['integer','bigint']:db(sql='DROP SCHEMA IF EXISTS supporter_cost_'+width+' CASCADE')
