# Isolated PostgreSQL wire recorder; source SQL and bound parameters unchanged.
import socketserver,struct
pg_queries=[]
pg_timings=[]
pg_hook_used=False
pg_graph_refreshes=0
class PgRecorder(socketserver.StreamRequestHandler):
 def handle(self):
  self.request.setsockopt(socket.IPPROTO_TCP,socket.TCP_NODELAY,1)
  upstream=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM)
  upstream.connect('/tmp/vaak-library-facts-pg-socket/.s.PGSQL.5432')
  statements={};state={'active':None,'bound':None,'parsed':None,'parameters':[]}
  def back():
   buffer=b''
   try:
    while True:
     data=upstream.recv(65536)
     if not data:return
     buffer+=data
     while len(buffer)>=5:
      length=struct.unpack('!I',buffer[1:5])[0]
      if len(buffer)<length+1:break
      kind=buffer[:1];body=buffer[5:length+1];buffer=buffer[length+1:]
      if kind==b't' and state['parsed'] is not None:
       count=struct.unpack('!H',body[:2])[0]
       statements[state['parsed']]['types']=[struct.unpack('!I',body[2+i*4:6+i*4])[0] for i in range(count)]
      if kind==b'Z' and state['active']:
       phase,query,started=state['active'];state['active']=None
       pg_timings.append({'phase':phase,'sql':query,'seconds':time.monotonic()-started,'parameters':state['parameters'] if phase=='E' else []})
     self.request.sendall(data)
   except OSError:pass
  threading.Thread(target=back,daemon=True).start()
  try:
   head=self.rfile.read(4)
   if len(head)!=4:return
   n=struct.unpack('!I',head)[0];upstream.sendall(head+self.rfile.read(n-4))
   while True:
    kind=self.rfile.read(1)
    if not kind:return
    head=self.rfile.read(4);n=struct.unpack('!I',head)[0];payload=self.rfile.read(n-4)
    query=None
    if kind==b'P':
     name,raw,_=payload.split(b'\0',2);query=raw.decode();statements[name]={'sql':query,'types':[]};state['parsed']=name
    elif kind==b'Q':query=payload[:-1].decode()
    elif kind==b'B':
     _,name,rest=payload.split(b'\0',2);statement=statements[name];state['bound']=statement['sql']
     formats=struct.unpack('!H',rest[:2])[0];pos=2+formats*2
     count=struct.unpack('!H',rest[pos:pos+2])[0];pos+=2;parameters=[]
     for index in range(count):
      size=struct.unpack('!i',rest[pos:pos+4])[0];pos+=4
      raw=rest[pos:pos+max(size,0)];pos+=max(size,0)
      parameters.append({'oid':statement['types'][index],'hex':raw.hex(),'null':size==-1})
     state['parameters']=parameters
    if query:
     pg_queries.append(query)
     global pg_hook_used,pg_graph_refreshes
     if query.startswith('SELECT 0::bigint AS source_index') and 'FROM followers f WHERE owner_actor_id' in query:
      pg_graph_refreshes+=1
     # The second graph-completion ledger belongs to the peer whose source
     # has already been captured. Change durable facts before its fresh guard.
     if os.environ.get('CANONICAL_LATE_WITHDRAWAL')=='1' and not pg_hook_used and pg_graph_refreshes==2:
      pg_hook_used=True
      sql("$d->exec(\"UPDATE masto_statuses SET visibility='private' WHERE local_id=2\");$d->exec(\"UPDATE outbox_notes SET visibility='private',to_json='[]',cc_json='[]' WHERE id LIKE '%/canonical2'\");")
    if kind in (b'P',b'Q'):state['active']=(kind.decode(),query,time.monotonic())
    elif kind==b'E':state['active']=('E',state['bound'],time.monotonic())
    upstream.sendall(kind+head+payload)
  except OSError:pass
  finally:upstream.close()
class PgServer(socketserver.ThreadingTCPServer):
 allow_reuse_address=True
 daemon_threads=True
pg_proxy=PgServer(('127.0.0.1',18717),PgRecorder)
threading.Thread(target=pg_proxy.serve_forever,daemon=True).start()
