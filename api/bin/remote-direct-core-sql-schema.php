<?php
declare(strict_types=1);
/** Fixture schema only; no application helper, policy, or result substitutes. */
function direct_core_sql_schema(PDO $db, string $width = 'INTEGER'): array
{
    if (!in_array($width, ['INTEGER', 'BIGINT'], true)) throw new InvalidArgumentException('integer width');
    $definitions = [
        'ap_users' => 'id INT,actor_id TEXT,actor_key TEXT,username TEXT,disabled_at TEXT,is_admin INT',
        'actor_profile' => 'actor_key TEXT,name TEXT,summary TEXT,attachment_json TEXT,icon_url TEXT,image_url TEXT,manually_approves INT,discoverable INT,indexable INT,reply_policy TEXT,quote_policy TEXT,updated_at TEXT',
        'events' => 'id INT,object_id TEXT,actor_id TEXT,type TEXT,action_taken TEXT,visibility TEXT,summary TEXT,quote_object TEXT,created_at TEXT',
        'masto_statuses' => 'local_id INT,note_id TEXT,published TEXT,content_text TEXT,spoiler_text TEXT,sensitive INT,visibility TEXT',
        'masto_favourites' => 'owner_user_id INT,status_id TEXT,object_id TEXT,created_at TEXT',
        'masto_bookmarks' => 'owner_user_id INT,status_id TEXT,object_id TEXT,created_at TEXT',
        'masto_reblogs' => 'owner_user_id INT,status_id TEXT,object_id TEXT,created_at TEXT',
        'masto_pins' => 'status_local_id INT,owner_user_id INT',
        'quote_authorizations' => 'id TEXT,created_at TEXT,quoted_note_id TEXT,quoting_object_id TEXT,requester_actor TEXT,deleted_at TEXT',
        'bsky_crossposts' => 'note_id TEXT,bsky_uri TEXT,created_at TEXT,owner_user_id INT',
        'bsky_posts' => 'bsky_uri TEXT,like_count INT,repost_count INT,quote_count INT',
        'ap_asks' => 'id INT,ask_id TEXT,question TEXT,asker_actor TEXT,asked_actor TEXT,owner_user_id INT,answered INT,answer_note_id TEXT,ap_object TEXT,created_at TEXT,updated_at TEXT',
        'remote_actors' => 'actor_id TEXT,username TEXT,display_name TEXT,host TEXT,icon_source_url TEXT,image_source_url TEXT,updated_at TEXT,summary TEXT,profile_json TEXT',
        'remote_media_cache' => 'id INT,actor_id TEXT,kind TEXT,source_url TEXT,s3_key TEXT,public_url TEXT,content_type TEXT,byte_size INT,fetched_at TEXT,last_used_at TEXT',
    ];
    foreach ($definitions as $table => $columns) {
        $columns = str_replace(' INT', ' '.$width, $columns);
        $db->exec('CREATE TABLE IF NOT EXISTS '.$table.'('.$columns.')');
        $existing = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='$table'")->fetchAll(PDO::FETCH_COLUMN)
            : array_column($db->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach (explode(',', $columns) as $column) {
            [$name] = explode(' ', $column);
            if (!in_array($name, $existing, true)) $db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column);
        }
    }
    return $definitions;
}
