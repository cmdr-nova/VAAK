#!/usr/bin/env python3
"""Actual isolated Library bridge module, no production bootstrap/network."""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/library-facts-postgres-fixture.py'),'--emit-rust-harness'],text=True)
s=s[:s.index('#[tokio::main]')]
bridge=(root/'rust/vaak-worker/src/library_bridge.rs').read_text().replace('//!','//').replace('tracing::debug!("Library whole-batch PHP fallback");','eprintln!("REVIEW LIBRARY fallback: {_error:#}");')
line=next(line for line in s.splitlines() if 'mod bridge;' in line)
s=s.replace(line,'pub(crate) mod bridge {\n'+bridge+'\n}')
print(s+r'''
#[tokio::main] async fn main()->anyhow::Result<()> {
 let cfg=std::sync::Arc::new(config::Config::load()?);
 let app=axum::Router::new().route("/internal/library/render",axum::routing::post(move |headers:axum::http::HeaderMap,bytes:axum::body::Bytes|{let cfg=cfg.clone();async move{library::bridge::render(&cfg,headers,bytes).await}}));
 let listener=tokio::net::TcpListener::bind(std::env::var("LIBRARY_ACCEPT_BIND")?).await?;
 axum::serve(listener,app).await?;Ok(())
}
''')
