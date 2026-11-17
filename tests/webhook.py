import urllib.request,urllib.error,json,hmac,hashlib,time,os
base='http://127.0.0.1:8776/hic-bootcamp/webhook.php'
# Use the same fixture secret and organizer in the isolated server configuration.
config={'webhook_secret': os.environ['HIC_TEST_WEBHOOK_SECRET']}
organizer=os.environ.get('HIC_TEST_ORGANIZER', '+254700000002')
def call(body,sign=True,offset=0):
 raw=json.dumps(body).encode();stamp=str(int(time.time())+offset)
 headers={'Content-Type':'application/json','X-HIC-Timestamp':stamp,'X-HIC-Signature':hmac.new(config['webhook_secret'].encode(),stamp.encode()+b'.'+raw,hashlib.sha256).hexdigest() if sign else 'invalid'}
 try:
  with urllib.request.urlopen(urllib.request.Request(base,raw,headers)) as r:return r.status,json.load(r)
 except urllib.error.HTTPError as e:return e.code,json.load(e)
def check(test,name):
 assert test,name
 print('PASS: '+name)
code,res=call({'id':'readonly-1','from':'+254700000001','text':'STATUS'});check(code==200 and 'reply' in res,'signed participant STATUS')
code,res=call({'id':'denied-1','from':'+254700000001','text':'EXTEND 15'});check(code==403,'participant mutation denied')
code,res=call({'id':'bad-1','from':organizer,'text':'NEXT'},False);check(code==401,'invalid signature denied')
code,res=call({'id':'old-1','from':organizer,'text':'NEXT'},offset=-600);check(code==401,'stale signed request denied')
body={'id':'duplicate-start-1','from':organizer,'text':'NEXT'};code,res=call(body);check(code==200 and res['reply'].startswith('Saved'),'authorized organizer NEXT');code,res=call(body);check(code==200 and res.get('duplicate')==True,'duplicate command does not execute twice')
import sqlite3
conn=sqlite3.connect('file:'+os.environ['HIC_TEST_DB']+'?mode=rw', uri=True)
cursor=conn.execute("INSERT OR IGNORE INTO notifications(dedup,recipient,message,status,provider_id,due) VALUES(?,?,?,?,?,?)",('receipt-test',organizer,'test only','accepted','provider-fixture',0));conn.commit()
ref=conn.execute("SELECT id FROM notifications WHERE dedup=?",('receipt-test',)).fetchone()[0]
code,res=call({'type':'delivery','reference':ref,'id':'provider-fixture','status':'delivered'});check=code==200 and res.get('ok');assert check;print('PASS: signed delivery callback accepted')
status=conn.execute("SELECT status FROM notifications WHERE dedup=?",('receipt-test',)).fetchone()[0];assert status=='delivered';print('PASS: accepted advances to delivered');conn.close()
