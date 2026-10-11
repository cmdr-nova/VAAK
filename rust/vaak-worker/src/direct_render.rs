//! Complete cached direct cards only; no HTTP route or cutover is registered.
use anyhow::Result;
pub(crate) fn render(status:&crate::direct_context::AdmittedDirectStatus)->Result<String>{crate::library::card::render_direct_admitted(status)}
