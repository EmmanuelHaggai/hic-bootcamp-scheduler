<?php
$path=tempnam(sys_get_temp_dir(),'hic-test-');putenv('HIC_DB='.$path);require __DIR__.'/../lib/app.php';
function check($condition,$label){if(!$condition)throw new RuntimeException('FAIL: '.$label);echo "PASS: $label\n";}
try{
$r=sessions();check(count($r)===21,'all 21 agenda entries imported');check(count(array_filter($r,fn($s)=>$s['day']==='2026-11-17'))===5,'provisional November 17 agenda');
$p=plan($r,5,15,'shift');check($p[5]['start']===$r[5]['start']+900,'15-minute delay shifts following session');check($p[6]['start']===$r[6]['start']+900,'explicit shift moves lunch');check($p[4]['original_end']===$r[4]['original_end'],'original timetable retained');
try{plan($r,5,15,'protect');check(false,'protected break should reject');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'protected break'),'protected lunch rejects overlap');}
try{plan($r,9,15,'protect');check(false,'closing must reject');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'closing'),'protected closing rejects overtime');}
command('START','test',5,'protect',0);check(active()['id']===5,'organizer starts session');$startedEnd=active()['end'];check(active()['end']-active()['actual_start']===3600,'starting now preserves the full session duration');
try{command('START','test',6);check(false,'second active session');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'Complete'),'only one session may be active');}
try{command('EXTEND 5','test',5,'shift',0);check(false,'stale revision');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'changed'),'stale changes rejected');}
command('PAUSE','test',5);check(active()['status']==='paused','pause state recorded');command('RESUME','test',5);command('EXTEND 15','test',5,'shift');check(active()['end']===$startedEnd+900,'approved extension saved');
command('DONE','test',5);check(active()===null,'completion requires explicit DONE');$row=db()->query('SELECT * FROM sessions WHERE id=5')->fetch();check($row['actual_end']!==null,'actual completion retained');check((int)db()->query('SELECT count(*) FROM audit')->fetchColumn()===5,'successful mutations audited');
$beforeCount=(int)db()->query('SELECT count(*) FROM notifications')->fetchColumn();queueMessage('dedup-test','test only; never delivered');queueMessage('dedup-test','test only; never delivered');check((int)db()->query('SELECT count(*) FROM notifications')->fetchColumn()===$beforeCount+count(config()['recipients']),'repeat scheduler alerts deduplicated per recipient');
command('START','test',6);
queueMessage('session:6:revision:1:ten','obsolete test alert');
command('SWITCH','test',8,'protect',(int)db()->query('SELECT revision FROM sessions WHERE id=8')->fetchColumn());
check(active()['id']===8,'switch starts selected session atomically');
$old=db()->query('SELECT * FROM sessions WHERE id=6')->fetch();check($old['status']==='skipped'&&$old['actual_end']!==null,'switch records previous active session as skipped');
check((int)db()->query("SELECT count(*) FROM sessions WHERE status IN ('active','paused')")->fetchColumn()===1,'switch leaves exactly one active session');
check((int)db()->query("SELECT count(*) FROM notifications WHERE dedup LIKE 'session:6:%' AND status='pending'")->fetchColumn()===0,'switch cancels obsolete reminders');
try{command('SWITCH','test',5);check(false,'switching to completed session');}catch(RuntimeException $e){check(active()['id']===8,'invalid switch leaves active session untouched');}
command('SKIP','test',8);check(active()===null,'active session can be skipped directly');
command('START','test',1);
$beforeSwap=sessions();command('SWAP 8','test');$afterSwap=sessions();
$byId=fn($items,$id)=>array_values(array_filter($items,fn($row)=>$row['id']===$id))[0];
check(active()['start']===$byId($beforeSwap,8)['start'],'swap places active session in the chosen time slot');
check($byId($afterSwap,8)['start']===$byId($beforeSwap,1)['start'],'swap moves other session to the exchanged slot');
check($byId($afterSwap,7)['start']===$byId($beforeSwap,7)['start'],'swap preserves lunch');
check($byId($afterSwap,1)['original_start']===$byId($beforeSwap,1)['original_start'],'swap preserves source timetable');
$a=active();$now=time();db()->prepare('UPDATE sessions SET start=?,end=? WHERE id=?')->execute([$now-1800,$now+1800,$a['id']]);
$end=active()['end'];command('LATE 15','test');check(active()['actual_start']===active()['start']+900,'reported late start recorded relative to revised slot');check(active()['end']===$end,'late start does not silently alter scheduled end');
$beforeAudit=(int)db()->query('SELECT count(*) FROM audit')->fetchColumn();publishNotice('Tea is ready.','test');check((int)db()->query('SELECT count(*) FROM audit')->fetchColumn()===$beforeAudit+1,'notice sending is audited and queued');
$end=active()['end'];command('BREAK 15','test');$tea=activeBreak();check($tea['end']-$tea['start']===900,'tea break lasts exactly 15 minutes');check(active()['status']==='paused','tea break pauses the active session');check(active()['end']===$end,'tea break preserves scheduled end');
try{command('BREAK 15','test');check(false,'duplicate break');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'already running'),'duplicate breaks rejected');}
command('RESUME','test');check(activeBreak()===null&&active()['status']==='active','resume closes the break and restores session tracking');
$first=db()->query('SELECT * FROM sessions WHERE id=10')->fetch();editSessionTimes(10,'08:05','12:00',$first['revision'],'test');$edited=db()->query('SELECT * FROM sessions WHERE id=10')->fetch();check(date('H:i',$edited['start'])==='08:05'&&$edited['original_start']===$first['original_start'],'time editing saves revised times and preserves originals');
try{editSessionTimes(10,'08:00','12:00',$first['revision'],'test');check(false,'stale time edit');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'changed'),'stale time edits rejected');}
try{editSessionTimes(10,'08:00','13:00',$edited['revision'],'test');check(false,'overlapping times');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'overlap'),'overlapping times rejected');}
try{editSessionTimes(10,'08:30','08:00',$edited['revision'],'test');check(false,'negative duration');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'later'),'invalid end-before-start rejected');}
publishNotice('Public notification test','test',true);$notices=publicNotices();$n=$notices[0];check($n['message']==='Public notification test','new notices visible in public feed');editNotice($n['id'],'Updated notification',false,0,'test');$n=publicNotices()[0];check($n['message']==='Updated notification'&&$n['revision']===1,'notice edits visible in public feed');
check((int)db()->query("SELECT count(*) FROM notifications WHERE status='pending' AND dedup LIKE 'notice:".$n['id'].":%' ")->fetchColumn()===0,'editing cancels outdated queued notice SMS');
try{editNotice($n['id'],'Stale edit',false,0,'test');check(false,'stale notice');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'changed'),'stale notice edits rejected');}
editNotice($n['id'],'',true,1,'test');check(!in_array($n['id'],array_column(publicNotices(),'id'),true),'removed notices disappear from public feed');
$fixture=$r;$anchor=$fixture[1];$anchor['status']='active';$anchor['start']=strtotime('2026-11-16 12:28');$anchor['end']=$anchor['start']+3600;$fixture[1]=$anchor;$fixture[5]['status']='skipped';$fixture[8]['status']='completed';
$shifted=previewAutoPush($fixture,$anchor,'shift',true,$anchor['end']);foreach($shifted as $i=>$item){check(($item['end']-$item['start'])===($fixture[$i]['end']-$fixture[$i]['start']),'auto-push preserves duration '.$item['id']);if(in_array($fixture[$i]['status'],['skipped','completed']))check($item===$fixture[$i],'auto-push leaves closed item '.$item['id'].' unchanged');}
check($shifted[0]['start']===$anchor['end'],'explicit earlier-pending policy queues unfinished session after current');
try{previewAutoPush($fixture,$anchor,'protect',false,$anchor['end']);check(false,'protected auto-push conflict');}catch(RuntimeException $e){check(true,'protected auto-push holds on break conflict');}
setAutoPush(true,'shift',true,'isolated test');check(autoPushOptions()['enabled']===true,'auto-push setting persists in test database');setAutoPush(false,'protect',false,'isolated test');check(autoPushOptions()['enabled']===false,'auto-push can be disabled');
echo "All checks passed.\n";
}finally{foreach([$path,$path.'-wal',$path.'-shm'] as $file)if(is_file($file))unlink($file);}
