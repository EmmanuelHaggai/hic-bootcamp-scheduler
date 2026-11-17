<?php
// CLI only. Run every minute. The relay must honor Idempotency-Key.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/lib/app.php';
$lock=fopen(__DIR__.'/var/scheduler.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))exit;
$db=db();$now=time();tickAutoPush();
$break=activeBreak();if($break&&$now>=$break['end'])queueMessage('break-end:'.$break['id'],'The '.(int)(($break['end']-$break['start'])/60).'-minute break is over. Please return to the room. Organizer: use Resume to restart the paused session.');
foreach(sessions() as $s){
    if(in_array($s['status'],['completed','skipped','paused'],true))continue;
    $base='session:'.$s['id'].':revision:'.$s['revision'];
    $events=[['upcoming',$s['start']-900,'Upcoming in 15 minutes: '],['start',$s['start'],'Scheduled session start: ']];
    // Warnings refer only to confirmed active sessions, never imply completion.
    if($s['status']==='active'){
        $events[]=['ten',$s['end']-600,'10 minutes remaining: '];
        $events[]=['end',$s['end'],'Scheduled end reached; please confirm DONE: '];
        $over=(int)floor(($now-$s['end'])/300);
        if($over>=1 && $over<=12)$events[]=['overtime:'.$over,$s['end']+$over*300,($over*5).' minutes overtime; awaiting DONE: '];
    }
    foreach($events as [$type,$due,$prefix])if($now>=$due && $now<$due+90){
        queueMessage($base.':'.$type,$prefix.$s['title'].' · '.$s['facilitator'].' · '.date('H:i',$s['start']).'–'.date('H:i',$s['end']).' EAT');
    }
}
foreach(['2026-11-16','2026-11-17','2026-11-18'] as $day){
    $closing=max(array_column(array_filter(sessions(),fn($r)=>$r['day']===$day),'end'));
    if($now>=$closing && $now<$closing+90)queueMessage('report:'.$day,'Daily timing report '.$day."\n".command('REPORT','scheduler'));
}
$c=config();
if(!empty($c['sms_enabled'])){
    $q=$db->prepare("SELECT * FROM notifications WHERE status='pending' AND due<=? ORDER BY id LIMIT 30");$q->execute([$now]);foreach($q->fetchAll() as $n)deliverNotification($n);echo "SMS scheduler completed.\n";exit;
}
if(!$c['relay_url']){echo "Reminders queued; delivery disabled (no relay).\n";exit;}
if(!str_starts_with($c['relay_url'],'https://'))throw new RuntimeException('Relay URL must use HTTPS.');
$q=$db->prepare("SELECT * FROM notifications WHERE status='pending' AND due<=? AND attempts<5 ORDER BY id LIMIT 30");$q->execute([$now]);
foreach($q->fetchAll() as $n){
    // A previous crash leaves 'sending' for manual reconciliation; never blindly resend an unknown outcome.
    $db->prepare("UPDATE notifications SET status='sending',attempts=attempts+1 WHERE id=? AND status='pending'")->execute([$n['id']]);
    $ch=curl_init($c['relay_url']);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['to'=>$n['recipient'],'message'=>$n['message'],'reference'=>(string)$n['id']]),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$c['relay_token'],'Idempotency-Key: hic-'.$n['id']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $response=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);$body=json_decode((string)$response,true);
    if($code>=200&&$code<300&&!empty($body['id'])){
        $db->prepare("UPDATE notifications SET status=CASE WHEN status='sending' THEN 'accepted' ELSE status END,provider_id=? WHERE id=?")->execute([(string)$body['id'],$n['id']]);
    }else{
        $retry=($code===429||$code>=500||$code===0)&&$n['attempts']<4;
        $db->prepare("UPDATE notifications SET status=?,due=?,error=? WHERE id=? AND status='sending'")->execute([$retry?'pending':'failed',$now+min(3600,60*2**($n['attempts']+1)),substr($err?:'Relay HTTP '.$code.'; expected JSON id',0,200),$n['id']]);
    }
}
echo "Scheduler completed.\n";
