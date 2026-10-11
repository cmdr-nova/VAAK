#!/usr/bin/env python3
"""Generate a disposable actual-module Rust Library admission verifier.

No application environment, live DB, or network is loaded. --emit-rust-harness
includes the complete existing Rust module graph (no policy/identity stubs).
--php-reference executes actual PHP durable audience SQL in isolated SQLite
using the same fixture and paired expected allow/deny cases. Rendering, CSRF,
mutation side effects and controller cutover are outside this fixture's scope.
"""
import json
import pathlib
import re
import subprocess
import sys

def jd(value, **kwargs):
    return json.dumps(value, ensure_ascii=False)

ROOT = pathlib.Path(__file__).resolve().parents[2]
SRC = ROOT / 'rust/vaak-worker/src'
VIEWER = 'https://mkultra.monster/users/viewer'
LOCAL = 'https://mkultra.monster/users/local'
REMOTE = 'https://remote.test/users/author'

def q(v):
    if v is None: return 'NULL'
    if isinstance(v, int): return str(v)
    return "'" + str(v).replace("'", "''") + "'"

def status(name, uri=None, actor=REMOTE, **extra):
    value = dict(id=name, uri=uri or 'https://remote.test/'+name,
                 content='ordinary allowed text', spoiler_text='', visibility='public',
                 account=dict(uri=actor), favourited=True, bookmarked=True, reblogged=True)
    value.update(extra)
    return value

def cases():
    out = []
    def add(label, value, allowed=True, owner=201, audience=True):
        out.append(dict(label=label,status=value,allowed=allowed,owner=owner,audience=audience))
    for name in ['public','unlisted','private','direct','local']:
        actor = LOCAL if name=='local' else REMOTE
        add(name,status(name,actor=actor))
    add('other owner public control',status('public'),owner=202)
    add('other owner private rejected',status('private'),False,202)
    add('other owner direct rejected',status('direct'),False,202)
    add('revoked remote follower',status('revoked'),False)
    add('accepted local follower',status('accepted-local',LOCAL+'/notes/accepted'),True)
    add('pending local follower rejected',status('pending-local',LOCAL+'/notes/pending'),False)
    add('explicit local direct recipient',status('local-direct',LOCAL+'/notes/direct'),True)
    add('local direct other owner',status('local-direct',LOCAL+'/notes/direct'),False,202)
    add('own private local note',status('own',VIEWER+'/notes/own',VIEWER))
    add('deleted local note',status('deleted',LOCAL+'/notes/deleted'),False)
    add('unknown private cache',status('unknown-private',actor='https://unfollowed.test/users/a',visibility='private'),False)
    add('public cache without durable row',status('uncached-public'))
    for field,wrapper in [('quote',None),('quote','quoted_status'),('quote','status'),('reblog',None),('vaak_quote_preview',None)]:
        for child,allow in [('public',True),('revoked',False)]:
            nested=status(child)
            add(field+' '+str(wrapper)+' '+child,status('public',**{field: {wrapper:nested} if wrapper else nested}),allow)
    add('flattened blocked object host',status('public',vaak_quote_preview={'url':'https://sub.blocked.test/n/1','content':'safe'}),False,audience=False)
    add('user domain blocked subdomain',status('subdomain',actor='https://sub.blocked.test/users/a'),False,audience=False)
    add('domain suffix allow control',status('suffix',actor='https://notblocked.test/users/a'),audience=False)
    add('instance domain blocked',status('instance',actor='https://sub.instance.test/users/a'),False,audience=False)
    add('actor block alias',status('alias',actor='https://aliases.test/@hidden'),False,audience=False)
    add('personal mute',status('mute',actor='https://muted.test/users/a'),False,audience=False)
    add('other owner mute control',status('mute',actor='https://muted.test/users/a'),owner=202,audience=False)
    add('muted html decoded phrase',status('words',content='<p>HIDDEN &amp;   PHRASE</p>'),False,audience=False)
    add('muted CW phrase',status('words',spoiler_text='hidden & phrase'),False,audience=False)
    add('safe CW control',status('safe-cw',sensitive=True,spoiler_text='safe CW'),audience=False)
    add('Unicode muted CW phrase',status('unicode-cw',spoiler_text='CAFÉ &amp; tea'),False,audience=False)
    add('HTML5 named entity muted phrase',status('named-entity',content='CAF&Eacute; &amp; tea'),False,audience=False)
    add('numeric entity muted phrase',status('numeric-entity',content='CAF&#xC9; &amp; tea'),False,audience=False)
    add('native Bsky blocked DID',status('bsky-block','at://did:plc:hidden/app.bsky.feed.post/1','https://bsky.app/profile/did:plc:hidden'),False,audience=False)
    add('Bsky encoded DID alias',status('bsky-block','at://did:plc:hidden/app.bsky.feed.post/1','https://bsky.app/profile/did%3Aplc%3Ahidden'),False,audience=False)
    add('Bsky safe DID control',status('bsky-safe','at://did:plc:safe/app.bsky.feed.post/1','https://bsky.app/profile/did:plc:safe'),audience=False)
    add('Bsky other owner control',status('bsky-block','at://did:plc:hidden/app.bsky.feed.post/1','https://bsky.app/profile/did:plc:hidden'),owner=202,audience=False)
    add('Bsky flattened late quote DID',status('public',vaak_quote_preview={'uri':'at://did:plc:hidden/app.bsky.feed.post/1','url':'https://bsky.app/profile/untrusted.handle/post/1','content':'safe'}),False,audience=False)
    for did,allow in [('did:plc:hidden',False),('did:plc:safe',True)]:
        raw=dict(id='raw-'+did,uri='at://'+did+'/app.bsky.feed.post/1',author={'did':did,'handle':'test.handle'},record={'text':'ordinary text'})
        add('raw PostView '+did,raw,allow,audience=False)
        parent=dict(id='raw-embed',uri='at://did:plc:safe/app.bsky.feed.post/p',author={'did':'did:plc:safe'},record={'text':'safe'},embed={'record':raw})
        add('raw nested PostView '+did,parent,allow,audience=False)
    add('raw PostView muted record text',dict(id='raw-words',uri='at://did:plc:safe/app.bsky.feed.post/w',author={'did':'did:plc:safe'},record={'text':'hidden & phrase'}),False,audience=False)
    add('raw PostView remote blockedBy',dict(id='raw-remote-block',uri='at://did:plc:safe/app.bsky.feed.post/b',author={'did':'did:plc:safe','viewer':{'blockedBy':True}},record={'text':'safe'}),False,audience=False)
    add('raw PostView stale owner blocking and mute flags',dict(id='raw-stale-owner',uri='at://did:plc:safe/app.bsky.feed.post/s',author={'did':'did:plc:safe','viewer':{'blocking':'at://old/block','muted':True}},viewer={'blocked':True},record={'text':'safe'}),audience=False)
    add('raw PostView sensitive label preserves CW',dict(id='raw-sensitive',uri='at://did:plc:safe/app.bsky.feed.post/c',author={'did':'did:plc:safe'},labels=[{'val':'porn'}],record={'text':'safe'}),audience=False)
    for sid,allow in [('rss:11',True),('rss:0011',True),('rss:12',False),('rss:99',False),('rss:bad',False),('rss:+11',False),('rss:0',False)]:
        add('owner RSS '+sid,status(sid,'https://article.test/'+sid,source='rss'),allow,audience=False)
    add('malformed RSS ID cannot use cached item metadata',status('rss:bad','https://article.test/forged',source='rss',vaak_rss_item_id=11),False,audience=False)
    add('foreign public AP RSS mirror control',status('mirror','https://remote.test/rss-mirror'),audience=False)
    add('current local identity',status('identity',actor=VIEWER),audience=False)
    add('current linked Bsky identity',status('linked','at://did:plc:viewer/app.bsky.feed.post/1','https://bsky.app/profile/viewer.bsky.test'),audience=False)
    for depth in [14,15,16,17]:
        tree={}
        for _ in range(depth): tree={'child':tree}
        add('paired native policy depth '+str(depth),status('depth-'+str(depth),bsky_post=tree),depth<=16,audience=False)
    tree={}
    for _ in range(18): tree={'child':tree}
    add('paired unrelated deep metadata',status('metadata-deep',metadata=tree),audience=False)
    add('paired unrelated metadata blockedBy',status('metadata-blocked',metadata={'viewer':{'blockedBy':True}}),audience=False)
    add('paired wrapper reason author',status('wrapper-reason',bsky_post={'post':{'author':{'did':'did:plc:safe'},'record':{'text':'safe'}},'reason':{'by':{'did':'did:plc:hidden'}}}),False,audience=False)
    return out

def setup(width):
    sql = f'''
CREATE TABLE ap_users(id {width}, actor_id TEXT, actor_key TEXT, disabled_at TEXT);
CREATE TABLE actor_profile(actor_key TEXT,name TEXT,icon_url TEXT,automated {width},discoverable {width},indexable {width},auto_unblur_sensitive {width});
CREATE TABLE ap_mutes(owner_user_id {width},actor_id TEXT);
CREATE TABLE ap_user_blocks(id {width},owner_user_id {width},scope TEXT,value TEXT);
CREATE TABLE ap_blocks(scope TEXT,value TEXT,kind TEXT NOT NULL DEFAULT 'block');
CREATE TABLE ap_muted_words(owner_user_id {width},phrase TEXT);
CREATE TABLE bsky_hide_dids(owner_user_id {width},did TEXT);
CREATE TABLE ap_deprioritized_actors(owner_user_id {width},actor_id TEXT);
CREATE TABLE bsky_sessions(owner_user_id {width},did TEXT,handle TEXT);
CREATE TABLE events(id {width},object_id TEXT,type TEXT,action_taken TEXT,visibility TEXT,actor_id TEXT);
CREATE TABLE outbox_notes(id TEXT,visibility TEXT,to_json TEXT,cc_json TEXT);
CREATE TABLE mentions(owner_user_id {width},deleted_at TEXT,activity_type TEXT,object_id TEXT,id {width});
CREATE TABLE followers(owner_actor_id TEXT,actor_id TEXT);
CREATE TABLE following(owner_actor_id TEXT,actor_id TEXT);
CREATE TABLE rss_feeds(id {width},owner_user_id {width},title TEXT,favicon_url TEXT,site_url TEXT,feed_url TEXT);
CREATE TABLE rss_items(id {width},feed_id {width},title TEXT,url TEXT,content TEXT);
'''
    for table in ['masto_favourites','masto_bookmarks','masto_reblogs']:
        sql += f'CREATE TABLE {table}(owner_user_id {width},status_id TEXT,object_id TEXT);'
    def insert(table,rows):
        return ''.join('INSERT INTO '+table+' VALUES('+','.join(q(v) for v in row)+');' for row in rows)
    sql += insert('ap_users',[[201,VIEWER,'viewer',None],[202,VIEWER+'2','viewer2',None],[203,VIEWER+'3','viewer3','2026-10-10']])
    sql += insert('actor_profile',[['viewer','Current viewer','https://local.test/avatar.webp',0,1,1,1],['viewer2','Other viewer','https://local.test/other.webp',0,1,1,0]])
    sql += insert('ap_mutes',[[201,'https://muted.test/users/a']])
    sql += insert('ap_user_blocks',[[1,201,'domain','blocked.test'],[2,201,'actor','https://aliases.test/users/hidden']])
    sql += insert('ap_blocks',[['domain','instance.test','block']])
    sql += insert('ap_muted_words',[[201,'hidden & phrase'],[201,'café & tea']])
    sql += insert('bsky_hide_dids',[[201,'did:plc:hidden']])
    sql += insert('bsky_sessions',[[201,'did:plc:viewer','viewer.bsky.test'],[202,'did:plc:other','other.bsky.test']])
    sql += insert('following',[[VIEWER,REMOTE],[VIEWER,LOCAL]])
    # Accepted and pending local authors are deliberately separate.
    accepted=LOCAL+'-accepted'
    sql += insert('followers',[[accepted,VIEWER]])
    ev=[]
    for i,name in enumerate(['public','unlisted','private','direct','local','revoked']):
        ev.append([i+1,'https://remote.test/'+name,'Create','log','private' if name=='revoked' else name,LOCAL if name=='local' else ('https://revoked.test/users/a' if name=='revoked' else REMOTE)])
    sql += insert('events',ev)
    sql += insert('mentions',[[201,None,'Create','https://remote.test/direct',1]])
    sql += insert('outbox_notes',[[LOCAL+'/notes/accepted','private','[]','[]'],[LOCAL+'/notes/pending','private','[]','[]'],[LOCAL+'/notes/direct','direct',jd([VIEWER]),'[]'],[VIEWER+'/notes/own','private','[]','[]']])
    # PHP/Rust infer a local note's author from its URI. Accepted uses its own URI.
    sql += "UPDATE outbox_notes SET id="+q(accepted+'/notes/accepted')+" WHERE id="+q(LOCAL+'/notes/accepted')+';'
    sql += insert('rss_feeds',[[1,201,'Owner feed','https://feed.test/icon','https://feed.test','https://feed.test/rss'],[2,202,'Foreign feed','','','']])
    sql += insert('rss_items',[[11,1,'Fresh owned article','https://fresh.test/article','fresh body'],[12,2,'Foreign article','https://foreign.test/article','foreign body']])
    sql += insert('masto_bookmarks',[[201,'public','https://remote.test/public'],[202,'unlisted','https://remote.test/unlisted']])
    sql += insert('masto_favourites',[[201,'public','https://remote.test/public']])
    return sql

CASES=cases()
for c in CASES:
    if c['label']=='accepted local follower': c['status']['uri']=LOCAL+'-accepted/notes/accepted'

def alias_cases():
    host='https://alias-control.test'
    pairs=[('/users/alice','/@alice'),('/@alice','/users/alice'),
           ('/@alice','/@%61lice'),('/@%61lice','/@alice'),
           ('/users/alice','/users/%61lice'),('/users/%61lice','/users/alice'),
           ('/users/%61lice','/@alice'),('/@alice','/users/%61lice'),
           ('/@café','/@caf%C3%A9'),('/@caf%C3%A9','/@café'),
           ('/users/123','/@123'),('/@123','/users/123'),
           ('/users/123','/users/123'),('/@123','/@123'),
           ('/@a+b','/@a%2Bb'),('/@a%2Bb','/@a+b'),
           ('/users/Alice','/users/alice'),('/@Alice','/@alice'),
           ('/users/allowed','/users/other'),
           ('/users/%31','/users/1'),('/users/1','/users/%31'),
           ('/@%31','/@1'),('/@1','/@%31'),('/users/%31','/@1'),
           ('/@alice%2F','/@alice'),('/@alice%3F','/@alice'),
           ('/@alice%23','/@alice'),('/@alice%25','/@alice'),
           ('/@alice%FF','/@alice%FE'),('/@alice%FF','/@alice�'),
           ('/@alice%2F','/@alice%2F'),('/@alice%252F','/@alice%2F')]
    values=[]
    for kind in ['personal_block','personal_mute','server_block','server_mute']:
        for stored,query in pairs:
            for owner in [201,202]:
                values.append(dict(kind=kind,scope='actor',stored=host+stored,query=host+query,owner=owner))
        for query in ['https://sub.alias-domain.test/users/a','https://notalias-domain.test/users/a']:
            for owner in [201,202]:
                values.append(dict(kind=kind,scope='domain',stored='alias-domain.test',query=query,owner=owner))
    # Domain mutes are server-only; VAAK personal mutes target an actor IRI.
    return [v for v in values if not(v['kind']=='personal_mute' and v['scope']=='domain')]

def php_alias_results():
    code=r'''
require $argv[1].'/api/bin/migration-reference-functions.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$GLOBALS['db']=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['db']->exec($input['sql']);
$GLOBALS['db']->exec("ALTER TABLE ap_mutes ADD COLUMN created_at TEXT; ALTER TABLE ap_user_blocks ADD COLUMN created_at TEXT; ALTER TABLE ap_blocks ADD COLUMN created_at TEXT;");
function ap_db():PDO{return $GLOBALS['db'];}
reference_load($argv[1].'/api/ap-db.php',['ap_moderation_path_segment_forms','ap_moderation_syntactic_refs','ap_actor_moderation_aliases','ap_actor_moderation_refs_match','ap_mutes_list','ap_mutes_set_cached','ap_is_muted_actor','ap_user_blocks_list','ap_user_blocks_list_cached','ap_blocks_actor_id_set','ap_user_is_blocked','ap_block_list','ap_block_list_cached','ap_is_blocked_host','ap_server_blocked_actors_set','ap_is_blocked_actor','ap_is_globally_muted_actor']);
function reset_policy():void{ap_mutes_set_cached(201,true);ap_mutes_set_cached(202,true);ap_user_blocks_list_cached(201,true);ap_user_blocks_list_cached(202,true);$GLOBALS['ap_relset_memo']=[];ap_block_list_cached(true);ap_server_blocked_actors_set(true);}
$out=[]; foreach($input['cases'] as $c){
ap_db()->exec('DELETE FROM ap_mutes; DELETE FROM ap_user_blocks; DELETE FROM ap_blocks;');
switch($c['kind']){
case 'personal_block':$q=ap_db()->prepare('INSERT INTO ap_user_blocks(id,owner_user_id,scope,value) VALUES(1,201,?,?)');$q->execute([$c['scope'],$c['stored']]);break;
case 'personal_mute':$q=ap_db()->prepare('INSERT INTO ap_mutes(owner_user_id,actor_id) VALUES(201,?)');$q->execute([$c['stored']]);break;
default:$q=ap_db()->prepare('INSERT INTO ap_blocks(scope,value,kind) VALUES(?,?,?)');$q->execute([$c['scope'],$c['stored'],$c['kind']==='server_mute'?'mute':'block']);}
reset_policy();$host=parse_url($c['query'],PHP_URL_HOST);
$hidden=ap_is_blocked_actor($c['query'])||ap_is_globally_muted_actor($c['query'])||ap_user_is_blocked($c['query'],$host,$c['owner'])||ap_is_muted_actor($c['query'],$c['owner']);
// Actual unmute/unblock cache invalidation transport after warming all helpers.
ap_db()->exec('DELETE FROM ap_mutes; DELETE FROM ap_user_blocks; DELETE FROM ap_blocks;');reset_policy();
$removed=ap_is_blocked_actor($c['query'])||ap_is_globally_muted_actor($c['query'])||ap_user_is_blocked($c['query'],$host,$c['owner'])||ap_is_muted_actor($c['query'],$c['owner']);
if($removed)throw new RuntimeException('actual PHP removal remained hidden');
$c['hidden']=$hidden;$c['after_remove_hidden']=$removed;$out[]=$c;
}
if(!in_array(true,array_column($out,'hidden'),true)||!in_array(false,array_column($out,'hidden'),true))throw new RuntimeException('Missing paired alias allow/deny controls');
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''
    result=subprocess.run([str(ROOT/'api/bin/php-policy-test'),'-r',code,str(ROOT)],input=jd({'sql':setup('INTEGER'),'cases':alias_cases()}),text=True,capture_output=True,check=True)
    if result.stderr.strip(): raise RuntimeError('PHP reference emitted diagnostics: '+result.stderr)
    return json.loads(result.stdout)

if '--php-policy-reference' in sys.argv:
    code=r'''
ob_start();require $argv[1].'/api/bin/library-privacy-admission-smoke.php';ob_end_clean();
$GLOBALS['db_failure']=false;$db->exec("DELETE FROM bsky_hide_dids; INSERT INTO bsky_hide_dids VALUES(201,'did:plc:hidden');");
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
foreach($input as $c){$got=admin_library_status_allowed($c['status'],201);if($got!==$c['allowed'])throw new RuntimeException($c['label'].' actual PHP paired policy mismatch '.json_encode($got));}
echo 'library-admission-php-native-policy: PASS ('.count($input).' actual PHP depth/wrapper/unrelated metadata controls)'.PHP_EOL;
'''
    subprocess.run([str(ROOT/'api/bin/php-policy-test'),'-r',code,str(ROOT)],input=jd([c for c in CASES if c['label'].startswith('paired ')]),text=True,check=True)
    sys.exit()

if '--php-moderation-reference' in sys.argv:
    results=php_alias_results()
    print(jd(results))
    sys.exit()

if '--php-reference' in sys.argv:
    code=r'''
require $argv[1].'/api/bin/migration-reference-functions.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$GLOBALS['db']=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['db']->exec($input['sql']);
function ap_db(): PDO {return $GLOBALS['db'];}
reference_load($argv[1].'/api/ap-db.php',['ap_db_owner_actor_id_for_user_id']);
reference_load($argv[1].'/api/ap-visibility.php',['ap_visibility_status_audience_allowed']);
if (!extension_loaded('mbstring')) throw new RuntimeException('Real mbstring required for Unicode word parity.');
function ap_muted_words_phrases_cached(int $owner): array { $q=ap_db()->prepare('SELECT phrase FROM ap_muted_words WHERE owner_user_id=?');$q->execute([$owner]);return $q->fetchAll(PDO::FETCH_COLUMN); }
reference_load($argv[1].'/api/ap-db.php',['ap_muted_words_match','ap_muted_words_normalize_haystack']);
$n=0;foreach($input['cases'] as $c){if(!$c['audience'])continue;$got=ap_visibility_status_audience_allowed($c['status'],$c['owner']);if($got!==$c['allowed'])throw new RuntimeException($c['label'].' actual PHP audience mismatch '.json_encode($got));$n++;}
$words=0;foreach($input['cases'] as $c){if(!in_array($c['label'],['muted html decoded phrase','muted CW phrase','safe CW control','Unicode muted CW phrase','HTML5 named entity muted phrase','numeric entity muted phrase']))continue;$got=ap_muted_words_match($c['owner'],$c['status']['content'],$c['status']['spoiler_text'])===null;if($got!==$c['allowed'])throw new RuntimeException($c['label'].' actual PHP word policy mismatch');$words++;}
echo "library-admission-php-audience: PASS ($n actual PHP durable SQL cases and $words actual Unicode/entity word cases with allow/deny controls)\n";
'''
    subprocess.run([str(ROOT/'api/bin/php-policy-test'),'-r',code,str(ROOT)],input=jd({'sql':setup('INTEGER'),'cases':CASES}),text=True,check=True)
    sys.exit()

if '--emit-rust-harness' not in sys.argv:
    sys.exit('Use --php-reference or --emit-rust-harness. Run emitted Rust against a disposable PostgreSQL database only.')
for name in re.findall(r'^mod (\w+);', (SRC/'main.rs').read_text(),re.M):
    path=SRC/(name+'.rs')
    if not path.exists(): path=SRC/name/'mod.rs'
    print('#[path='+jd(str(path))+'] mod '+name+';')
print('use serde_json::Value; #[tokio::main] async fn main()->anyhow::Result<()> {')
print('let url=std::env::var("LIBRARY_FIXTURE_DB")?; let db=db::connect(&url).await?; let mut failures=Vec::new(); let mut checks=0;')
ALIASES=php_alias_results()
for width in ['INTEGER','BIGINT']:
    print('db.batch_execute('+jd(setup(width))+').await?;')
    for c in CASES:
        label=width+' '+c['label']; data=jd(c['status'],ensure_ascii=False)
        print('{ let v:Value=serde_json::from_str('+jd(data)+')?;')
        print('match library::context::admit(&db,'+str(c['owner'])+',vec![v]).await {')
        print('Ok(p)=>{let got=!p.statuses().is_empty(); checks+=1; if got!='+str(c['allowed']).lower()+'{failures.push(format!("{} expected '+str(c['allowed']).lower()+' got {}",'+jd(label)+',got));}')
        print('if got {assert_eq!(p.owner_id(),'+str(c['owner'])+'); assert_eq!(p.auto_unblur_sensitive(),'+str(c['owner']==201).lower()+');')
        if c['status']['id']=='public' and 'quote' not in c['status'] and 'reblog' not in c['status'] and 'vaak_quote_preview' not in c['status']:
            print('assert_eq!(p.statuses()[0]["bookmarked"],'+str(c['owner']==201).lower()+'); assert_eq!(p.statuses()[0]["favourited"],'+str(c['owner']==201).lower()+'); assert_eq!(p.statuses()[0]["reblogged"],false);')
        if c['status']['id']=='rss:11': print('assert_eq!(p.rss_row(11).unwrap()["title"],"Fresh owned article"); assert_eq!(p.rss_row(11).unwrap()["url"],"https://fresh.test/article");')
        if c['status']['id'] in ['identity','linked']:
            print('assert_eq!(p.statuses()[0]["account"]["avatar"],"https://local.test/avatar.webp"); assert_eq!(p.statuses()[0]["account"]["display_name"],"Current viewer");')
        print('}},Err(e)=>failures.push(format!("{} policy runtime error: {:#}",'+jd(label)+',e)),} }')
    alias_inputs=[]
    for c in ALIASES:
        label=width+' actual PHP '+c['kind']+' '+c['scope']+' stored='+c['stored']+' query='+c['query']+' owner='+str(c['owner'])
        sql='DELETE FROM ap_mutes; DELETE FROM ap_user_blocks; DELETE FROM ap_blocks;'
        if c['kind']=='personal_block': sql+='INSERT INTO ap_user_blocks VALUES(1,201,'+q(c['scope'])+','+q(c['stored'])+');'
        elif c['kind']=='personal_mute': sql+='INSERT INTO ap_mutes VALUES(201,'+q(c['stored'])+');'
        else: sql+='INSERT INTO ap_blocks VALUES('+q(c['scope'])+','+q(c['stored'])+','+q('mute' if c['kind']=='server_mute' else 'block')+');'
        value=status('alias-policy-control',actor=c['query'])
        alias_inputs.append(dict(label=label,sql=sql,value=value,owner=c['owner'],hidden=c['hidden']))
    print('let alias_cases:Vec<Value>=serde_json::from_str('+jd(jd(alias_inputs))+')?;')
    print('for c in alias_cases {db.batch_execute(c["sql"].as_str().unwrap()).await?; let owner=c["owner"].as_i64().unwrap(); let v=c["value"].clone(); let p=library::context::admit(&db,owner,vec![v.clone()]).await?; let hidden=p.statuses().is_empty(); checks+=1; if hidden!=c["hidden"].as_bool().unwrap(){failures.push(format!("{} expected hidden {} got {}",c["label"].as_str().unwrap(),c["hidden"],hidden));} db.batch_execute("DELETE FROM ap_mutes; DELETE FROM ap_user_blocks; DELETE FROM ap_blocks;").await?;let p=library::context::admit(&db,owner,vec![v]).await?;assert_eq!(p.statuses().len(),1);checks+=1;}')
    # Same cached source rechecked after the owner changes privacy/settings.
    print('let saved:Value=serde_json::from_str('+jd(jd(status('private')))+')?;')
    print('db.batch_execute("DELETE FROM following; UPDATE actor_profile SET auto_unblur_sensitive=0 WHERE actor_key=\'viewer\'; DELETE FROM masto_bookmarks;").await?;')
    print('assert!(library::context::admit(&db,201,vec![saved]).await?.statuses().is_empty()); checks+=1;')
    print('let p=library::context::admit(&db,201,vec![serde_json::json!({"id":"public","uri":"https://remote.test/public","account":{"uri":"https://remote.test/users/author"},"bookmarked":true})]).await?; assert_eq!(p.statuses().len(),1);assert!(!p.auto_unblur_sensitive());assert_eq!(p.statuses()[0]["bookmarked"],false);checks+=1;')
    print('db.batch_execute("INSERT INTO ap_mutes VALUES (201,\'https://remote.test/users/author\')").await?;')
    print('assert!(library::context::admit(&db,201,vec![serde_json::json!({"id":"public","uri":"https://remote.test/public","account":{"uri":"https://remote.test/users/author"}})]).await?.statuses().is_empty());checks+=1;')
    print('for owner in [0,203,999] { assert!(library::context::admit(&db,owner,vec![serde_json::json!({"id":"public","uri":"https://remote.test/public"})]).await.is_err());checks+=1;}')
    print('db.batch_execute("ALTER TABLE bsky_hide_dids RENAME TO absent_bsky_hide_dids").await?; assert!(library::context::admit(&db,201,vec![serde_json::json!({"id":"public","uri":"https://remote.test/public"})]).await.is_err()); checks+=1; db.batch_execute("ALTER TABLE absent_bsky_hide_dids RENAME TO bsky_hide_dids").await?;')
    print('db.batch_execute("DROP TABLE ap_users,actor_profile,ap_mutes,ap_user_blocks,ap_blocks,ap_muted_words,bsky_hide_dids,ap_deprioritized_actors,bsky_sessions,events,outbox_notes,mentions,followers,following,rss_feeds,rss_items,masto_favourites,masto_bookmarks,masto_reblogs").await?;')
print('for failure in &failures {eprintln!("FAIL: {failure}");} anyhow::ensure!(failures.is_empty(),"{} admission failures; {} completed controls",failures.len(),checks); println!("library-admission-native: PASS ({checks} actual-module cases, INTEGER/BIGINT, owner/settings/audiences/recursive moderation/RSS/interaction state; renderer/controller excluded)"); Ok(()) }')
