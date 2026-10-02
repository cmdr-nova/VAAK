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
