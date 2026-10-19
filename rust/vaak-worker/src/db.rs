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
