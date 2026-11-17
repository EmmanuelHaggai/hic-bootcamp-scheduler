<?php
require __DIR__.'/lib/app.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
function respond(int $code,array $data): never {http_response_code($code);echo json_encode($data);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')respond(405,['error'=>'POST required']);
$raw=file_get_contents('php://input',false,null,0,65537);if(strlen($raw)>65536)respond(413,['error'=>'Payload too large']);
$secret=config()['webhook_secret'];$signature=$_SERVER['HTTP_X_HIC_SIGNATURE']??'';$timestamp=$_SERVER['HTTP_X_HIC_TIMESTAMP']??'';
if(!$secret||!ctype_digit($timestamp)||abs(time()-(int)$timestamp)>300||!hash_equals(hash_hmac('sha256',$timestamp.'.'.$raw,$secret),$signature))respond(401,['error'=>'Invalid signature']);
$body=json_decode($raw,true);if(!is_array($body))respond(400,['error'=>'Invalid JSON']);
if(($body['type']??'')==='delivery'){
    if(!in_array($body['status']??'',['delivered','failed'],true))respond(422,['error'=>'Invalid delivery status']);
    if(empty($body['reference']) || empty($body['id'])) respond(422,['error'=>'Missing delivery reference']);
    $q=db()->prepare("UPDATE notifications SET status=?,provider_id=COALESCE(provider_id,?) WHERE id=? AND (provider_id IS NULL OR provider_id=?) AND status IN ('sending','accepted')");
    $q->execute([$body['status'],(string)$body['id'],(int)$body['reference'],(string)$body['id']]);
    respond(200,['ok'=>true]);
}
$id=(string)($body['id']??'');$sender=(string)($body['from']??'');$text=strtoupper(trim((string)($body['text']??'')));
if(!preg_match('/^[A-Za-z0-9:_-]{1,150}$/',$id)||!preg_match('/^\+[1-9][0-9]{7,14}$/',$sender)||strlen($text)>100)respond(422,['error'=>'Invalid command envelope']);
$readonly=in_array($text,['STATUS','AGENDA','REPORT'],true);
if(!$readonly&&!in_array($sender,config()['organizers'],true))respond(403,['error'=>'Organizer permission required']);
// Claim provider message ID before any mutation. Retries cannot execute a command twice.
$q=db()->prepare('INSERT OR IGNORE INTO commands(id,at,sender,command,result) VALUES(?,?,?,?,?)');$q->execute([$id,time(),$sender,$text,'Processing; do not resend with a new ID.']);
if(!$q->rowCount()){$q=db()->prepare('SELECT sender,result FROM commands WHERE id=?');$q->execute([$id]);$old=$q->fetch();if($old['sender']!==$sender)respond(409,['error'=>'ID conflict']);respond(200,['reply'=>$old['result'],'duplicate'=>true]);}
try{$result=command($text,$sender);}catch(Throwable $e){$result=$e->getMessage();}
db()->prepare('UPDATE commands SET result=? WHERE id=?')->execute([$result,$id]);respond(200,['reply'=>$result]);
