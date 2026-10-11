#!/usr/bin/env python3
"""Run actual Search presentation code with independent PHP cases in a temporary crate.
Only test capture path/count and manifest-root transport are changed in copied sources.
No runtime admission/painter substitutions; production files are read only.
"""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[2]
work=pathlib.Path(tempfile.mkdtemp(prefix='vaak-search-render-review-'))
worker=work/'vaak-worker';shutil.copytree(root/'rust/vaak-worker/src',worker/'src')
shutil.copytree(root/'rust/vaak-worker/fixtures',worker/'fixtures')
shutil.copytree(root/'rust/vaak-types',work/'vaak-types',ignore=shutil.ignore_patterns('target'))
shutil.copy(root/'rust/vaak-worker/Cargo.toml',worker/'Cargo.toml');shutil.copy(root/'rust/Cargo.lock',work/'Cargo.lock')
(work/'Cargo.toml').write_text('[workspace]\nresolver="2"\nmembers=["vaak-worker","vaak-types"]\n')
for p in (worker/'src').glob('*.rs'):
 s=p.read_text().replace('env!("CARGO_MANIFEST_DIR")', '"'+str(root/'rust/vaak-worker')+'"')
 p.write_text(s)
p=worker/'src/search_render.rs';s=p.read_text().replace('search-render-reference-capture.php','search-render-independent-capture.php').replace('assert_eq!(checked,14,','assert_eq!(checked,12,');p.write_text(s)
p=worker/'src/library_context.rs';s=p.read_text()+r'''
#[cfg(test)] mod independent_search_constructor_review {
 use super::*;
 #[test] fn library_constructor_cannot_be_widened_by_search_presentation() {
  for view in ["search","remote_profile","home","","bookmarks&admin=1"] {
   assert!(TrustedSession::from_php_session(1,"csrf".into(),view.into(),false).is_err(),"{view}");
  }
  for view in ["bookmarks","favourites"] {
   assert!(TrustedSession::from_php_session(1,"csrf".into(),view.into(),false).is_ok(),"{view}");
  }
 }
}
''';p.write_text(s)
p=worker/'src/library_card.rs';s=p.read_text();needle='let rendered=render(status,&facts);';assert s.count(needle)==1
s=s.replace(needle,r'''
            for view in ["remote_profile","home","","search&admin=1"] {
                let mut wrong=facts.clone();wrong.return_view=view.into();
                assert!(render(status,&wrong).is_err(),"invalid presentation view {view}");
            }
            let rendered=render(status,&facts);
''');p.write_text(s)
env=os.environ.copy();env['LD_LIBRARY_PATH']='/usr/lib/rustlib/x86_64-unknown-linux-gnu/lib';env['CARGO_TARGET_DIR']='/tmp/vaak-search-render-review-target'
for test in ['search_render::tests::actual_php_account_hashtag_and_summary_components_match','independent_search_constructor_review','library::card::tests::complete_native_cards_match_actual_php_header_body_and_actions']:
 subprocess.run(['cargo','test','--offline','--manifest-path',str(work/'Cargo.toml'),test,'--','--nocapture'],cwd=root,env=env,check=True)
print('PASS: 12 independent actual-PHP component cases, canonical mandatory cards, invalid-view rendering guards, and Library constructor separation. No admission or promotion claim.')
shutil.rmtree(work)
