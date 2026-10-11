#!/usr/bin/env python3
"""Emit isolated real-module Search HTTP server; no synthetic native HTML."""
import pathlib, subprocess
root=pathlib.Path(__file__).resolve().parents[2]
source=subprocess.check_output(['python3',str(root/'api/bin/library-facts-postgres-fixture.py'),'--emit-rust-harness'],text=True)
source=source[:source.index('#[tokio::main]')]
bridge=(root/'rust/vaak-worker/src/search_bridge.rs').read_text().replace('//!','//')
line=next(line for line in source.splitlines() if line.endswith('mod search_bridge;'))
bridge+='''
pub(crate) async fn independent_serve()->anyhow::Result<()> {
 let url=std::env::var("SEARCH_ACCEPT_DB")?;
 let app=axum::Router::new().route("/internal/search/render",axum::routing::post(move |headers:HeaderMap,bytes:Bytes| {let url=url.clone();async move {render_request(&url,Some("independent-search-acceptance-token-0001"),headers,bytes).await}})).layer(axum::extract::DefaultBodyLimit::max(MAX_BYTES));
 let listener=tokio::net::TcpListener::bind(std::env::var("SEARCH_ACCEPT_BIND")?).await?;
 println!("SEARCH ACCEPT SERVER READY");axum::serve(listener,app).await?;Ok(())
}
'''
source=source.replace(line,'mod search_bridge {\n'+bridge+'\n}')
print(source+'\n#[tokio::main] async fn main()->anyhow::Result<()> {search_bridge::independent_serve().await}\n')
