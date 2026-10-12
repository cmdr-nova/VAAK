#!/usr/bin/env python3
"""Bounded owned loopback PHP SAPI checks; no production credentials or application bootstrap."""
import os, pathlib, socket, subprocess, tempfile, time, urllib.parse, urllib.request, urllib.error, json, uuid, traceback
root = pathlib.Path(__file__).resolve().parents[2]
receipt={'requests':[],'checks':[],'cleanup':{}}
receipt_path=pathlib.Path(os.environ.get('VAAK_THREAD_HTTP_RECEIPT', '/tmp/vaak-thread-http-'+str(uuid.uuid4())+'.json'))
focus = 'at://did:plc:fixture/app.bsky.feed.post/focus'
try:
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0)); port = probe.getsockname()[1]
    with tempfile.TemporaryDirectory(prefix='vaak-thread-sessions-') as session_dir, tempfile.TemporaryFile() as log:
        fixture_env=os.environ.copy(); fixture_env.pop('PHP_CLI_SERVER_WORKERS',None)
        fixture_env['VAAK_THREAD_FIXTURE_SESSION_DIR']=session_dir
        proc = subprocess.Popen([str(root/'api/bin/php-policy-test'), '-S', f'127.0.0.1:{port}', str(root/'api/bin/bsky-thread-http-fixture.php')], cwd=root, env=fixture_env, stdout=log, stderr=log, start_new_session=True)
        checks = 0
        deadline = time.monotonic() + 40
        def request(**params):
            if time.monotonic() >= deadline: raise RuntimeError('fixture deadline')
            params.setdefault('object', focus)
            url = f'http://127.0.0.1:{port}/?' + urllib.parse.urlencode(params)
            try: reply = urllib.request.urlopen(url, timeout=3)
            except urllib.error.HTTPError as exc: reply = exc
            with reply:
                body = reply.read(1024*1024 + 1).decode()
                if len(body) > 1024*1024: raise RuntimeError('fixture body exceeds cap')
                receipt['requests'].append({'params':params,'status':reply.status,'headers':dict(reply.headers),'body':body})
                return reply.status, reply.headers, body
        def check(ok, name):
            global checks
            receipt['checks'].append({'name':name,'passed':bool(ok)})
            if not ok: raise AssertionError(name)
            checks += 1
        try:
            for _ in range(25):
                try:
                    status, headers, body = request()
                    break
                except urllib.error.URLError:
                    if proc.poll() is not None: raise RuntimeError('fixture SAPI exited')
                    time.sleep(.05)
            else: raise RuntimeError('fixture SAPI startup timeout')
            check(status==200 and 'Focused post' in body, 'Open focus route')
            for shell in ['status-thread-ancestors','status-focus','status-thread-descendants']:
                check(f'id="{shell}"' in body, 'actual shell '+shell)
            check(body.count('data-need-more="1"')==2, 'both shells boot incremental loading')
            check(body.count('class="post-action-menu"')==1, 'Open focus single menu')
            for part, terms in [('focus',['Focused post']),('ancestors',['Oldest post','Parent post']),('replies',['First reply','Nested reply'])]:
                status, headers, body = request(thread=part)
                check(status==200 and all(term in body for term in terms), 'actual controller '+part)
                check(headers.get('Cache-Control')=='no-store', 'fragment no-store '+part)
                check('id="status-thread-' not in body, 'fragment does not refresh page shell '+part)
                check(body.count('class="post-action-menu"')==body.count('<article '), 'single menu per actual card '+part)
            for control in ['block','source-withdrawal','wrong-focus']:
                status, _, body = request(thread='replies',control=control)
                check(status==404 and body=='', 'controller fail closed '+control)
            status, _, body=request(thread='replies',owner='mismatch')
            check(status==404 and body=='', 'actual active session owner mismatch')
            status, headers, body=request(thread='replies',owner='guest')
            check(headers.get('X-Fixture-Owner')=='0', 'genuine guest controller owner zero')
            check(status==200 and 'First reply' in body and '<form' not in body and 'data-bsky-action=' not in body, 'guest nonempty read-only controller')
            status, _, body=request(thread='replies',owner='guest',control='server-block')
            check(status==404 and body=='', 'guest server block controller')
            print(f'bsky-thread-http-smoke PASS {checks} owned SAPI route/shell checks; extracted dispatch/module loader, no production middleware claim')
        finally:
            proc.terminate()
            try: proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                proc.kill(); proc.wait(timeout=3)
            receipt['cleanup']['child_reaped']=proc.poll() is not None
            receipt['cleanup']['returncode']=proc.returncode
            if proc.poll() is None: raise RuntimeError('fixture child not reaped')
            with socket.socket() as closed_probe:
                closed_probe.settimeout(1)
                receipt['cleanup']['port_closed']=closed_probe.connect_ex(('127.0.0.1',port))!=0
                if not receipt['cleanup']['port_closed']: raise RuntimeError('fixture port remains open')
            log.seek(0)
            receipt['server_log']=log.read(65536).decode(errors='replace'); log.seek(0)
            if proc.returncode not in (0,-15):
                print(log.read(16384).decode(errors='replace'))

    if pathlib.Path(session_dir).exists(): raise RuntimeError("owned session directory remains")
    print("owned fixture cleanup PASS: child reaped, loopback port closed, isolated session directory removed")
except BaseException as exc:
    receipt['error']={'type':type(exc).__name__,'message':str(exc),'traceback':traceback.format_exc()}
    raise
finally:
    receipt['cleanup']['session_directory_removed']='session_dir' in globals() and not pathlib.Path(session_dir).exists()
    receipt['passed']='error' not in receipt and all(x['passed'] for x in receipt['checks']) and all(receipt['cleanup'].get(k) for k in ['child_reaped','port_closed','session_directory_removed'])
    with receipt_path.open('x') as output:
        json.dump(receipt,output,indent=2);output.write('\n')
    print('Owned HTTP receipt: '+str(receipt_path))
