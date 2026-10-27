//! Postgres helpers (tokio-postgres).

use anyhow::{Context, Result};
use tokio_postgres::{Client, NoTls};

pub async fn connect(database_url: &str) -> Result<Client> {
    let (client, connection) = tokio_postgres::connect(database_url, NoTls)
        .await
        .with_context(|| format!("connect postgres ({database_url})"))?;
    tokio::spawn(async move {
        if let Err(e) = connection.await {
            tracing::error!(error = %e, "postgres connection error");
        }
    });
    Ok(client)
}

/// Idle connections kept for the loopback Search routes (collect, resolve,
/// render). Each Search page makes two or three worker calls; reusing a
/// connection saves a Postgres backend start and auth per call. Two is
/// enough for one or two pages in flight on a 4-core box and keeps the
/// idle backend memory small.
const SEARCH_IDLE_MAX: usize = 2;
static SEARCH_IDLE: std::sync::Mutex<Vec<(String, Client)>> = std::sync::Mutex::new(Vec::new());

/// A connection that goes back to the Search idle list when dropped,
/// unless it closed or the list is full. These routes run plain queries
/// only (no transactions or session SETs), so a returned connection
/// carries no state into the next request.
pub struct Reused {
    url: String,
    client: Option<Client>,
}

impl std::ops::Deref for Reused {
    type Target = Client;
    fn deref(&self) -> &Client {
        self.client.as_ref().expect("reused client present until drop")
    }
}

impl Drop for Reused {
    fn drop(&mut self) {
        let Some(client) = self.client.take() else { return };
        if client.is_closed() {
            return;
        }
        if let Ok(mut idle) = SEARCH_IDLE.lock() {
            if idle.len() < SEARCH_IDLE_MAX {
                idle.push((std::mem::take(&mut self.url), client));
            }
        }
    }
}

/// Take an idle Search connection for this database, or open a new one.
pub async fn reused(database_url: &str) -> Result<Reused> {
    let idle = SEARCH_IDLE.lock().ok().and_then(|mut idle| {
        idle.retain(|(_, client)| !client.is_closed());
        let at = idle.iter().rposition(|(url, _)| url == database_url)?;
        Some(idle.swap_remove(at).1)
    });
    let client = match idle {
        Some(client) => client,
        None => connect(database_url).await?,
    };
    Ok(Reused { url: database_url.to_owned(), client: Some(client) })
}

/// Idle connections for the complete-profile routes. Each guest profile page
/// opened a fresh backend (connect, auth, cold catalog caches) inside its
/// 1s budget. These routes run plain queries only: no transactions, session
/// SETs, LISTEN or temp tables, and their prepared-plan memos are keyed by
/// backend pid and scoped to one request. Separate from Search and
/// notifications so a profile burst cannot take their warm connections.
const PROFILE_IDLE_MAX: usize = 4;
static PROFILE_IDLE: std::sync::Mutex<Vec<(String, Client)>> = std::sync::Mutex::new(Vec::new());

pub struct ProfileConn {
    url: String,
    client: Option<Client>,
}

impl std::ops::Deref for ProfileConn {
    type Target = Client;
    fn deref(&self) -> &Client {
        self.client.as_ref().expect("profile client present until drop")
    }
}

impl Drop for ProfileConn {
    fn drop(&mut self) {
        let Some(client) = self.client.take() else { return };
        if client.is_closed() {
            return;
        }
        if let Ok(mut idle) = PROFILE_IDLE.lock() {
            if idle.len() < PROFILE_IDLE_MAX {
                idle.push((std::mem::take(&mut self.url), client));
            }
        }
    }
}

/// Take an idle complete-profile connection for this database, or open a new one.
pub async fn profile_reused(database_url: &str) -> Result<ProfileConn> {
    let idle = PROFILE_IDLE.lock().ok().and_then(|mut idle| {
        idle.retain(|(_, client)| !client.is_closed());
        let at = idle.iter().rposition(|(url, _)| url == database_url)?;
        Some(idle.swap_remove(at).1)
    });
    let client = match idle {
        Some(client) => client,
        None => connect(database_url).await?,
    };
    Ok(ProfileConn { url: database_url.to_owned(), client: Some(client) })
}

/// Idle connections for the notifications HTML fill. Separate from the Search
/// pool so a mentions page cannot take the two connections Search keeps warm.
const NOTIF_IDLE_MAX: usize = 2;
static NOTIF_IDLE: std::sync::Mutex<Vec<(String, Client)>> = std::sync::Mutex::new(Vec::new());

pub struct NotifConn {
    url: String,
    client: Option<Client>,
}

impl std::ops::Deref for NotifConn {
    type Target = Client;
    fn deref(&self) -> &Client {
        self.client.as_ref().expect("notification client present until drop")
    }
}

impl Drop for NotifConn {
    fn drop(&mut self) {
        let Some(client) = self.client.take() else { return };
        if client.is_closed() {
            return;
        }
        if let Ok(mut idle) = NOTIF_IDLE.lock() {
            if idle.len() < NOTIF_IDLE_MAX {
                idle.push((std::mem::take(&mut self.url), client));
            }
        }
    }
}

/// Take an idle notifications connection for this database, or open a new one.
/// The fill runs plain queries only, so a returned connection carries no
/// session state into the next page.
pub async fn notif_reused(database_url: &str) -> Result<NotifConn> {
    let idle = NOTIF_IDLE.lock().ok().and_then(|mut idle| {
        idle.retain(|(_, client)| !client.is_closed());
        let at = idle.iter().rposition(|(url, _)| url == database_url)?;
        Some(idle.swap_remove(at).1)
    });
    let client = match idle {
        Some(client) => client,
        None => connect(database_url).await?,
    };
    Ok(NotifConn { url: database_url.to_owned(), client: Some(client) })
}

#[cfg(test)]
mod profile_pool_tests {
    /// Runs where a disposable fixture database is configured; skipped otherwise.
    #[tokio::test]
    async fn profile_connection_is_reused_and_reads_identical_rows() {
        let Ok(url) = std::env::var("LIBRARY_FIXTURE_DB") else { return };
        let sql = "SELECT to_jsonb(t) FROM (SELECT n, md5(n::text) AS digest FROM generate_series(1, 20) n) t ORDER BY 1";
        let fresh = super::connect(&url).await.unwrap();
        let expected: Vec<serde_json::Value> = fresh.query(sql, &[]).await.unwrap().into_iter().map(|row| row.get(0)).collect();
        let first = super::profile_reused(&url).await.unwrap();
        let pid: i32 = first.query_one("SELECT pg_backend_pid()", &[]).await.unwrap().get(0);
        let rows: Vec<serde_json::Value> = first.query(sql, &[]).await.unwrap().into_iter().map(|row| row.get(0)).collect();
        assert_eq!(rows, expected);
        drop(first);
        let second = super::profile_reused(&url).await.unwrap();
        let again: i32 = second.query_one("SELECT pg_backend_pid()", &[]).await.unwrap().get(0);
        assert_eq!(again, pid, "an idle profile connection is taken before opening a new one");
        let rows: Vec<serde_json::Value> = second.query(sql, &[]).await.unwrap().into_iter().map(|row| row.get(0)).collect();
        assert_eq!(rows, expected);
    }
}
