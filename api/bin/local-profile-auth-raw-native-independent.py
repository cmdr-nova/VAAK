#!/usr/bin/env python3
"""Actual authenticated raw constructor; no presentation facts injected."""
import pathlib,subprocess,sys,json,hashlib
root=pathlib.Path(__file__).resolve().parents[2]
subprocess.check_output([sys.executable,str(root/'api/bin/local-profile-guest-raw-native-independent.py')])
base=pathlib.Path('/tmp/vaak-profile-guest-raw-independent-runtime');runtime=pathlib.Path('/tmp/vaak-profile-auth-raw-independent-runtime');(runtime/'src').mkdir(parents=True,exist_ok=True)
code=(base/'src/main.rs').read_text()
code=code.replace('let session=GuestProfileSession::from_php_guest_profile(key.into(),actor.into(),false,0)?;', 'let session=AuthenticatedProfileSession::from_php_session(context["owner"].as_i64().unwrap(),context["csrf"].as_str().unwrap().into(),key.into(),actor.into(),false,0)?.with_quote_preview_budget(0);let dns=crate::direct_context::IndependentDns{expected:None,count:std::sync::atomic::AtomicUsize::new(0)};')
code=code.replace('match admit_guest_raw(db,&session,selection).await','match admit_authenticated_raw_with_dns(db,&session,selection,&dns).await').replace('r["context"]["owner"]==0','r["context"]["owner"].as_i64().unwrap()>0').replace('PROFILE_GUEST_RAW_REFERENCE','PROFILE_AUTH_RAW_REFERENCE').replace('PROFILE_GUEST_RAW_NATIVE_OUTPUT','PROFILE_AUTH_RAW_NATIVE_OUTPUT').replace('Guest raw profile current SQL diagnostic','Authenticated raw profile current SQL diagnostic')
freeze=json.loads((base/'source-freeze.json').read_text());freeze['generated_main_sha256']=hashlib.sha256(code.encode()).hexdigest();(runtime/'source-freeze.json').write_text(json.dumps(freeze,indent=2)+'\n')
(runtime/'Cargo.toml').write_text((base/'Cargo.toml').read_text().replace('name = "local-profile-guest-raw-independent"','name = "local-profile-auth-raw-independent"'));(runtime/'src/main.rs').write_text(code);print(runtime)
