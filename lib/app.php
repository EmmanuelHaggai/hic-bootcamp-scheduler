<?php
declare(strict_types=1);
date_default_timezone_set('Africa/Nairobi');
require_once __DIR__.'/integrations.php';
function config(): array { static $c; return $c ??= (is_file(__DIR__.'/../config.php') ? require __DIR__.'/../config.php' : require __DIR__.'/../config.example.php'); }
function db(): PDO {
    static $db;
    if ($db) return $db;
    $db = new PDO('sqlite:'.(getenv('HIC_DB') ?: __DIR__.'/../var/bootcamp.sqlite'), null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; CREATE TABLE IF NOT EXISTS live_topics(session_id INTEGER PRIMARY KEY,topic TEXT); CREATE TABLE IF NOT EXISTS sessions(id INTEGER PRIMARY KEY, day TEXT, title TEXT, facilitator TEXT, original_start INTEGER, original_end INTEGER, start INTEGER, end INTEGER, status TEXT DEFAULT "scheduled", actual_start INTEGER, actual_end INTEGER, paused_at INTEGER, protected INTEGER DEFAULT 0, revision INTEGER DEFAULT 0); CREATE TABLE IF NOT EXISTS audit(id INTEGER PRIMARY KEY, at INTEGER, actor TEXT, action TEXT, before_json TEXT, after_json TEXT); CREATE TABLE IF NOT EXISTS notifications(id INTEGER PRIMARY KEY, dedup TEXT UNIQUE, recipient TEXT, message TEXT, status TEXT DEFAULT "pending", attempts INTEGER DEFAULT 0, due INTEGER, provider_id TEXT, error TEXT); CREATE TABLE IF NOT EXISTS commands(id TEXT PRIMARY KEY, at INTEGER, sender TEXT, command TEXT, result TEXT); CREATE TABLE IF NOT EXISTS login_attempts(ip TEXT, at INTEGER); CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT); CREATE TABLE IF NOT EXISTS notice_overrides(notice_id INTEGER PRIMARY KEY, message TEXT, removed INTEGER DEFAULT 0, revision INTEGER DEFAULT 0, updated_at INTEGER); CREATE TABLE IF NOT EXISTS breaks(id INTEGER PRIMARY KEY, start INTEGER, end INTEGER, session_id INTEGER, actual_end INTEGER);');
    $db->exec('BEGIN IMMEDIATE');
    try {
        if (!$db->query('SELECT count(*) FROM sessions')->fetchColumn()) {
            $q=$db->prepare('INSERT INTO sessions(day,title,facilitator,original_start,original_end,start,end,protected) VALUES(?,?,?,?,?,?,?,?)');
            foreach(require __DIR__.'/agenda.php' as [$day,$a,$b,$title,$who]) { $s=strtotime("$day $a");$e=strtotime("$day $b");$q->execute([$day,$title,$who,$s,$e,$s,$e,str_contains($title,'Break')?1:0]); }
        }
        $db->exec('COMMIT');
    } catch(Throwable $e) {$db->exec('ROLLBACK');throw $e;}
    return $db;
}
function sessions(): array {return db()->query('SELECT * FROM sessions ORDER BY start,id')->fetchAll();}
function active(): ?array {return db()->query("SELECT * FROM sessions WHERE status IN ('active','paused') LIMIT 1")->fetch() ?: null;}
function queueMessage(string $key,string $message): void {
    $q=db()->prepare('INSERT OR IGNORE INTO notifications(dedup,recipient,message,due) VALUES(?,?,?,?)');
    foreach(config()['recipients'] as $phone) $q->execute([$key.':'.$phone,$phone,$message,time()]);
}
function activeBreak(): ?array {return db()->query('SELECT * FROM breaks WHERE actual_end IS NULL ORDER BY id DESC LIMIT 1')->fetch() ?: null;}
function statusText(): string {
    $s=active(); if(!$s) return 'No session is active. Awaiting organizer START or NEXT.';
    $left=(int)ceil(($s['end']-time())/60);
    return $s['title'].' · '.$s['facilitator'].' · '.date('H:i',$s['start']).'–'.date('H:i',$s['end']).' EAT · '.($s['status']==='paused'?'Paused':($left>=0?"$left minutes remaining":abs($left).' minutes overtime; awaiting DONE'));
}
function plan(array $rows,int $id,int $minutes,string $policy): array {
    if(!in_array($policy,['protect','shift'],true) || $minutes < -240 || $minutes > 240) throw new RuntimeException('Invalid adjustment. Use -240 to 240 minutes.');
    $index=array_search($id,array_column($rows,'id')); if($index===false) throw new RuntimeException('Session not found.');
    $s=$rows[$index];$rows[$index]['end']+=$minutes*60;
    if($rows[$index]['end']<=$s['start']) throw new RuntimeException('A session must have a positive duration.');
    $cursor=$rows[$index]['end'];
    for($i=$index+1;$i<count($rows)&&$rows[$i]['day']===$s['day'];$i++) {
        if($rows[$i]['start'] >= $cursor) break;
        if($rows[$i]['status']!=='scheduled') throw new RuntimeException('Cannot move an already started session.');
        if($policy==='protect' && $rows[$i]['protected']) throw new RuntimeException('This delay reaches a protected break. Shorten the preceding session explicitly or choose shift with approval.');
        $length=$rows[$i]['end']-$rows[$i]['start'];$rows[$i]['start']=$cursor;$rows[$i]['end']=$cursor+$length;$cursor=$rows[$i]['end'];
    }
    $last=max(array_column(array_filter($rows,fn($r)=>$r['day']===$s['day']),'end'));
    $closing=max(array_column(array_filter($rows,fn($r)=>$r['day']===$s['day']),'original_end'));
    if($policy==='protect' && $last>$closing) throw new RuntimeException('This adjustment exceeds the protected closing time. Choose shift with approval or shorten a session explicitly.');
    return $rows;
}
function command(string $text,string $actor,?int $id=null,string $policy='protect',?int $revision=null): string {
    $text=strtoupper(trim($text));
    if(str_starts_with($text,'ASK ')) return aiAdvice(substr($text,4));
    if($text==='STATUS') return statusText();
    if($text==='AGENDA') return implode("\n",array_map(fn($s)=>$s['day'].' '.date('H:i',$s['start']).' '.$s['title'],sessions()));
    if($text==='REPORT') return implode("\n",array_map(fn($s)=>$s['title'].': '.$s['status'].($s['actual_end']?' · end variance '.round(($s['actual_end']-$s['original_end'])/60).' min':''),sessions()));
    $swapId=null;if(preg_match('/^SWAP (\d+)$/',$text,$m)) {$swapId=(int)$m[1];$text='SWAP';}
    if(preg_match('/^SWITCH (\d+)$/',$text,$m)) {$id=(int)$m[1];$text='SWITCH';}
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $before=sessions();$a=active();$target=null;
        foreach($before as $row) if($row['id']===($id??($a['id']??0))) $target=$row;
        if(in_array($text,['NEXT','START'],true) && !$target) foreach($before as $row) if($row['status']==='scheduled') {$target=$row;break;}
        if(!$target) throw new RuntimeException('Select a session first.');
        if($revision!==null && $target['revision']!==$revision) throw new RuntimeException('Schedule changed. Refresh and review before applying.');
        $sid=$target['id'];$now=time();
        if(preg_match('/^BREAK (\d+)$/',$text,$m)) {
            $minutes=(int)$m[1];if($minutes<1||$minutes>120)throw new RuntimeException('Choose a break of 1–120 minutes.');
            if(!$a || $a['id']!==$sid)throw new RuntimeException('Select the current session for a break.');
            if(activeBreak())throw new RuntimeException('A break is already running.');
            $db->prepare("UPDATE sessions SET status='paused',paused_at=COALESCE(paused_at,?),revision=revision+1 WHERE id=?")->execute([$now,$sid]);
            $db->prepare('INSERT INTO breaks(start,end,session_id) VALUES(?,?,?)')->execute([$now,$now+$minutes*60,$sid]);
            $db->prepare("UPDATE notifications SET status='cancelled' WHERE status='pending' AND dedup LIKE ?")->execute(['session:'.$sid.':%']);
        } elseif(preg_match('/^LATE (\d+)$/',$text,$m)) {
            $minutes=(int)$m[1];
            if(!in_array($target['status'],['active','paused'],true)||$minutes>240)throw new RuntimeException('Record 0–240 minutes late for the active session.');
            $actual=$target['start']+$minutes*60;if($actual>$now)throw new RuntimeException('Actual start cannot be in the future.');
            $db->prepare('UPDATE sessions SET actual_start=?,revision=revision+1 WHERE id=?')->execute([$actual,$sid]);
        } elseif($text==='SWAP') {
            if(!$a || $target['id']!==$a['id']) throw new RuntimeException('Select the active session to swap its time slot.');
            $other=null;foreach($before as $row)if($row['id']===$swapId)$other=$row;
            if(!$other || $other['id']===$sid || $other['day']!==$target['day'])throw new RuntimeException('Choose a different session on the same day.');
            if($target['protected'] || $other['protected'])throw new RuntimeException('Protected breaks cannot be swapped.');
            if(!in_array($other['status'],['scheduled','skipped'],true))throw new RuntimeException('Only pending or skipped sessions can be moved to the later slot.');
            $db->prepare('UPDATE sessions SET start=?,end=?,revision=revision+1 WHERE id=?')->execute([$other['start'],$other['end'],$sid]);
            $db->prepare("UPDATE sessions SET start=?,end=?,status='scheduled',actual_start=NULL,actual_end=NULL,paused_at=NULL,revision=revision+1 WHERE id=?")->execute([$target['start'],$target['end'],$other['id']]);
            foreach([$sid,$other['id']] as $changed)$db->prepare("UPDATE notifications SET status='cancelled' WHERE status='pending' AND dedup LIKE ?")->execute(['session:'.$changed.':%']);
        } elseif($text==='SWITCH') {
            if($target['status']!=='scheduled') throw new RuntimeException('Only a scheduled session can be selected for switching.');
            if($a) {
                $db->prepare("UPDATE sessions SET status='skipped',actual_end=?,paused_at=NULL,revision=revision+1 WHERE id=?")->execute([$now,$a['id']]);
                $db->prepare("UPDATE notifications SET status='cancelled' WHERE status='pending' AND dedup LIKE ?")->execute(['session:'.$a['id'].':%']);
            }
            $duration=$target['end']-$target['start'];$db->prepare("UPDATE sessions SET status='active',start=?,end=?,actual_start=?,revision=revision+1 WHERE id=?")->execute([$now,$now+$duration,$now,$sid]);
        } elseif(in_array($text,['NEXT','START'],true)) {
            if($a) throw new RuntimeException('Complete the active session before starting another.');
            if($target['status']!=='scheduled') throw new RuntimeException('Only scheduled sessions can start.');
            $duration=$target['end']-$target['start'];$db->prepare("UPDATE sessions SET status='active',start=?,end=?,actual_start=?,revision=revision+1 WHERE id=?")->execute([$now,$now+$duration,$now,$sid]);
        } elseif(in_array($text,['DONE','SKIP'],true)) {
            if($text==='DONE' && !in_array($target['status'],['active','paused'],true)) throw new RuntimeException('Session is not active.');
            if($text==='SKIP' && !in_array($target['status'],['scheduled','active','paused'],true)) throw new RuntimeException('This session is already closed.');
            if($text==='SKIP') $db->prepare("UPDATE notifications SET status='cancelled' WHERE status='pending' AND dedup LIKE ?")->execute(['session:'.$sid.':%']);
            $db->prepare('UPDATE sessions SET status=?,actual_end=?,paused_at=NULL,revision=revision+1 WHERE id=?')->execute([$text==='DONE'?'completed':'skipped',($text==='DONE'||$target['actual_start'])?$now:null,$sid]);
        } elseif($text==='PAUSE' || $text==='RESUME') {
            if($target['status']!==($text==='PAUSE'?'active':'paused')) throw new RuntimeException('Invalid pause state.');
            $db->prepare('UPDATE sessions SET status=?,paused_at=?,revision=revision+1 WHERE id=?')->execute([$text==='PAUSE'?'paused':'active',$text==='PAUSE'?$now:null,$sid]);
        } elseif(preg_match('/^EXTEND (-?\d+)$/',$text,$m)) {
            if(in_array($target['status'],['completed','skipped'],true)) throw new RuntimeException('This session is closed.');
            $after=plan($before,$sid,(int)$m[1],$policy);
            foreach($after as $i=>$row) if($row['start']!==$before[$i]['start'] || $row['end']!==$before[$i]['end']) $db->prepare('UPDATE sessions SET start=?,end=?,revision=revision+1 WHERE id=?')->execute([$row['start'],$row['end'],$row['id']]);
            $db->exec("UPDATE notifications SET status='cancelled' WHERE status='pending'");
        } else throw new RuntimeException('Supported: STATUS, AGENDA, REPORT, START, NEXT, SWITCH session-id, DONE, SKIP, PAUSE, RESUME, EXTEND minutes.');
        $runningBreak=activeBreak();if($runningBreak){$q=$db->prepare('SELECT status FROM sessions WHERE id=?');$q->execute([$runningBreak['session_id']]);if($q->fetchColumn()!=='paused')$db->prepare('UPDATE breaks SET actual_end=? WHERE id=?')->execute([$now,$runningBreak['id']]);}
        $db->prepare('INSERT INTO audit(at,actor,action,before_json,after_json) VALUES(?,?,?,?,?)')->execute([$now,$actor,$text.' / '.$policy,json_encode($before),json_encode(sessions())]);
        $changeId=$db->lastInsertId();
        if(str_starts_with($text,'BREAK ')){ $b=activeBreak();$message='Break: '.(int)substr($text,6).' minutes, until '.date('H:i',$b['end']).' EAT. Please return when the countdown ends.';$snapshot=json_encode(sessions());$db->prepare('INSERT INTO audit(at,actor,action,before_json,after_json) VALUES(?,?,?,?,?)')->execute([$now,$actor,'NOTICE: '.$message,$snapshot,$snapshot]);queueMessage('change:'.$changeId,$message);}
        elseif(str_starts_with($text,'LATE ')){queueMessage('change:'.$changeId,$target['title'].' started '.(int)substr($text,5).' minutes late, at '.date('H:i',active()['actual_start']).' EAT. Scheduled end remains '.date('H:i',$target['end']).' EAT while an organizer reviews any adjustment.');}
        elseif($text==='SWAP'){ $updated=active();queueMessage('change:'.$changeId,'Time slots swapped. Current: '.$updated['title'].' ('.date('H:i',$updated['start']).'–'.date('H:i',$updated['end']).' EAT). Next: '.$other['title'].' ('.date('H:i',$target['start']).'–'.date('H:i',$target['end']).' EAT). Lunch unchanged.');}
        else queueMessage('change:'.$changeId,(($text==='SWITCH'&&$a)?'Skipped: '.$a['title']."\nNow live: ":"Schedule update: $text · ").$target['title']."\n".statusText()."\nView the dashboard for the revised agenda.");
        $db->exec('COMMIT');return 'Saved. '.statusText();
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}

function publishNotice(string $message,string $actor,bool $sendSms=true): string {
    $message=trim($message);if($message===''||mb_strlen($message)>1000)throw new RuntimeException('Enter a notice of 1–1000 characters.');
    $db=db();$db->exec('BEGIN IMMEDIATE');try {
        $snapshot=json_encode(sessions());$db->prepare('INSERT INTO audit(at,actor,action,before_json,after_json) VALUES(?,?,?,?,?)')->execute([time(),$actor,'NOTICE: '.$message,$snapshot,$snapshot]);
        if($sendSms)queueMessage('notice:'.$db->lastInsertId(),'HIC Bootcamp notice: '.$message);$db->exec('COMMIT');return $sendSms?'Notice published on the public timer and queued for configured recipients.':'Notice published on the public timer.';
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}

function editSessionTimes(int $id,string $start,string $end,int $revision,string $actor,string $policy='reject'): string {
    return saveSessionSlot($id,$start,$end,$revision,$actor,$policy);
}
function previewSessionSlot(array $rows,int $id,int $start,int $end,string $policy): array {
    if($end<=$start)throw new RuntimeException('End time must be later than start time on the same day.');
    if(!in_array($policy,['reject','protect','shift'],true))throw new RuntimeException('Invalid timing policy.');
    $target=null;foreach($rows as &$r)if($r['id']===$id){$r['start']=$start;$r['end']=$end;$target=$r;}unset($r);
    if(!$target)throw new RuntimeException('Session not found.');
    if(in_array($target['status'],['skipped','completed'],true))return $rows;
    $cursor=$end;
    foreach($rows as &$r){
        if($r['id']===$id||$r['day']!==$target['day']||in_array($r['status'],['skipped','completed'],true))continue;
        if($policy==='reject'){
            if($start<$r['end']&&$end>$r['start'])throw new RuntimeException('These times overlap with '.$r['title'].'. Choose a shift policy to adjust pending sessions.');
            continue;
        }
        if($r['end']<=$start)continue;
        if($r['start']<$cursor){
            if($r['status']!=='scheduled')throw new RuntimeException('Cannot move another active session.');
            if($policy==='protect'&&$r['protected'])throw new RuntimeException('This change reaches a protected break.');
            $duration=$r['end']-$r['start'];$r['start']=$cursor;$r['end']=$cursor+$duration;
        }
        $cursor=$r['end'];
    }unset($r);
    if($policy==='protect'){
        $day=array_filter($rows,fn($r)=>$r['day']===$target['day']);
        if(max(array_column($day,'end'))>max(array_column($day,'original_end')))throw new RuntimeException('This change exceeds the protected closing time. Choose the explicitly approved shift policy to move it.');
    }
    return $rows;
}
function saveSessionSlot(int $id,string $start,string $end,int $revision,string $actor,string $policy='reject',bool $goLive=false,?string $topic=null): string {
    foreach([$start,$end] as $value)if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$value))throw new RuntimeException('Enter valid start and end times in EAT.');
    if($topic!==null&&mb_strlen(trim($topic))>200)throw new RuntimeException('Keep the live topic within 200 characters.');
    $db=db();$db->exec('BEGIN IMMEDIATE');try {
        $before=sessions();$rows=$before;$target=null;foreach($rows as $r)if($r['id']===$id)$target=$r;
        if(!$target||$target['revision']!==$revision)throw new RuntimeException('Schedule changed. Refresh and review before saving.');
        if($goLive&&in_array($target['status'],['completed','skipped'],true))throw new RuntimeException('Choose a pending or current session.');
        $s=strtotime($target['day'].' '.$start);$e=strtotime($target['day'].' '.$end);
        if(($goLive||in_array($target['status'],['active','paused'],true))&&$s>time())throw new RuntimeException('The actual start cannot be in the future. Save future times on a pending session first.');
        $oldActive=active();
        if($goLive)foreach($rows as &$r){
            if($r['id']===$id){$r['status']='active';$r['actual_start']=$s;$r['actual_end']=null;$r['paused_at']=null;}
            elseif(in_array($r['status'],['active','paused'],true)){
                if($s<$r['actual_start'])throw new RuntimeException('The next session cannot start before the active session began.');
                $r['status']='completed';$r['actual_end']=$s;$r['paused_at']=null;
            }
        }unset($r);
        $rows=previewSessionSlot($rows,$id,$s,$e,$policy);
        foreach($rows as $i=>$r){
            if($r['id']===$id&&in_array($r['status'],['active','paused'],true))$r['actual_start']=$s;
            $old=$before[$i];if($r===$old)continue;
            $db->prepare('UPDATE sessions SET start=?,end=?,status=?,actual_start=?,actual_end=?,paused_at=?,revision=revision+1 WHERE id=?')->execute([$r['start'],$r['end'],$r['status'],$r['actual_start'],$r['actual_end'],$r['paused_at'],$r['id']]);
            $db->prepare("UPDATE notifications SET status='cancelled' WHERE status='pending' AND dedup LIKE ?")->execute(['session:'.$r['id'].':%']);
        }
        if($goLive){if($b=activeBreak())$db->prepare('UPDATE breaks SET actual_end=? WHERE id=?')->execute([$s,$b['id']]);}
        if($topic!==null)$db->prepare('INSERT INTO live_topics(session_id,topic) VALUES(?,?) ON CONFLICT(session_id) DO UPDATE SET topic=excluded.topic')->execute([$id,trim($topic)]);
        $db->prepare('INSERT INTO audit(at,actor,action,before_json,after_json) VALUES(?,?,?,?,?)')->execute([time(),$actor,($goLive?'SET TIMES AND GO LIVE: ':'EDIT TIMES: ').$target['title'].' / '.$policy,json_encode($before),json_encode(sessions())]);
        queueMessage('change:'.$db->lastInsertId(),($goLive?'Now live: ':'Updated timing: ').($topic?:$target['title']).' · '.$start.'–'.$end.' EAT. '.($policy==='reject'?'Other session times are unchanged.':'Affected pending sessions have been shifted with durations preserved.'));
        $db->exec('COMMIT');return 'Session times saved.'.($goLive?' Live tracking started.':'').($policy==='reject'?' Other sessions have not moved.':' Affected pending sessions adjusted.');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function publicNotices(bool $includeExpired=false): array {
    $q=db()->prepare("SELECT a.id,COALESCE(o.updated_at,a.at) AS at,COALESCE(o.message,trim(substr(a.action,8))) AS message,COALESCE(o.revision,0) AS revision FROM audit a LEFT JOIN notice_overrides o ON o.notice_id=a.id WHERE a.action LIKE 'NOTICE: %' AND COALESCE(o.removed,0)=0 AND COALESCE(o.updated_at,a.at)>=CAST(? AS INTEGER) ORDER BY COALESCE(o.updated_at,a.at) DESC,a.id DESC LIMIT ".($includeExpired?'20':'3'));
    $q->execute([$includeExpired?0:time()-3600]);return $q->fetchAll();
}
function editNotice(int $id,string $message,bool $remove,int $revision,string $actor): string {
    $message=trim($message);if(!$remove&&($message===''||mb_strlen($message)>1000))throw new RuntimeException('Enter a notice of 1–1000 characters.');
    $db=db();$db->exec('BEGIN IMMEDIATE');try {
        $q=$db->prepare("SELECT a.*,o.revision AS notice_revision,o.removed FROM audit a LEFT JOIN notice_overrides o ON o.notice_id=a.id WHERE a.id=? AND a.action LIKE 'NOTICE: %'");$q->execute([$id]);$old=$q->fetch();
        if(!$old||$old['removed']||($old['notice_revision']??0)!==$revision)throw new RuntimeException('Notification changed or was removed. Refresh before trying again.');
        $db->prepare('INSERT INTO notice_overrides(notice_id,message,removed,revision,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(notice_id) DO UPDATE SET message=excluded.message,removed=excluded.removed,revision=excluded.revision,updated_at=excluded.updated_at')->execute([$id,$message,$remove?1:0,$revision+1,time()]);
        $db->prepare("UPDATE notifications SET status='cancelled' WHERE status='pending' AND dedup LIKE ?")->execute(['notice:'.$id.':%']);
        $db->prepare('INSERT INTO audit(at,actor,action,before_json,after_json) VALUES(?,?,?,?,?)')->execute([time(),$actor,($remove?'REMOVE NOTICE ':'EDIT NOTICE ').$id,json_encode($old),json_encode(['message'=>$message,'removed'=>$remove])]);
        $db->exec('COMMIT');return $remove?'Notification removed from the public timer.':'Notification updated on the public timer.';
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}

function alignLiveTiming(int $id,int $revision,string $actor): string {
    $db=db();$db->exec('BEGIN IMMEDIATE');try {
        $before=sessions();$target=null;foreach($before as $s)if($s['id']===$id)$target=$s;
        if(!$target||!in_array($target['status'],['active','paused'],true)||!$target['actual_start'])throw new RuntimeException('A confirmed active session is required.');
        if($target['revision']!==$revision)throw new RuntimeException('Schedule changed. Refresh before aligning timing.');
        if($target['start']===$target['actual_start']){$db->exec('COMMIT');return 'Live timing is already aligned.';}
        $duration=$target['end']-$target['start'];
        $db->prepare('UPDATE sessions SET start=actual_start,end=actual_start+?,revision=revision+1 WHERE id=?')->execute([$duration,$id]);
        $db->prepare("UPDATE notifications SET status='cancelled' WHERE status='pending' AND dedup LIKE ?")->execute(['session:'.$id.':%']);
        $db->prepare('INSERT INTO audit(at,actor,action,before_json,after_json) VALUES(?,?,?,?,?)')->execute([time(),$actor,'ALIGN LIVE TIMING: '.$target['title'],json_encode($before),json_encode(sessions())]);
        $db->exec('COMMIT');return 'Actual start and expected end aligned; duration preserved.';
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function liveTimingConflicts(?array $current,array $rows): array {
    if(!$current)return [];
    return array_values(array_map(fn($s)=>['title'=>$s['title'],'start'=>$s['start'],'end'=>$s['end']],array_filter($rows,fn($s)=>$s['id']!==$current['id']&&$s['day']===$current['day']&&$s['status']==='scheduled'&&$s['start']<$current['end']&&$s['end']>$current['start'])));
}

function autoPushOptions(): array {
    $q=db()->query("SELECT value FROM settings WHERE key='auto_push'");$raw=$q->fetchColumn();return $raw?json_decode($raw,true):['enabled'=>false,'policy'=>'protect','include_earlier'=>false];
}
function previewAutoPush(array $rows,array $anchor,string $policy,bool $includeEarlier,int $cursor): array {
    if(!in_array($policy,['protect','shift'],true))throw new RuntimeException('Invalid auto-push policy.');
    $result=$rows;$cursor=max($cursor,$anchor['end']);
    foreach($result as &$row){
        if($row['day']!==$anchor['day']||$row['id']===$anchor['id']||$row['status']!=='scheduled')continue;
        if(!$includeEarlier&&$row['end']<=$anchor['start'])continue;
        $duration=$row['end']-$row['start'];$start=max($row['start'],$cursor);
        if($policy==='protect'&&$row['protected']&&$start!==$row['start'])throw new RuntimeException('Auto-push would move '.$row['title'].'. Approve moving breaks or change the preceding sessions.');
        $row['start']=$start;$row['end']=$start+$duration;$cursor=$row['end'];
    }unset($row);
    if($policy==='protect'){
        $day=array_filter($rows,fn($r)=>$r['day']===$anchor['day']);$end=max(array_column($day,'original_end'));
        foreach($result as $r)if($r['day']===$anchor['day']&&$r['status']==='scheduled'&&$r['end']>$end)throw new RuntimeException('Auto-push would move the protected closing time.');
    }
    return $result;
}
function applyAutoPushRows(array $before,array $after): int {
    $count=0;foreach($after as $i=>$r)if($r['start']!==$before[$i]['start']||$r['end']!==$before[$i]['end']){
        db()->prepare('UPDATE sessions SET start=?,end=?,revision=revision+1 WHERE id=?')->execute([$r['start'],$r['end'],$r['id']]);
        db()->prepare("UPDATE notifications SET status='cancelled' WHERE status='pending' AND dedup LIKE ?")->execute(['session:'.$r['id'].':%']);$count++;
    }return $count;
}
function setAutoPush(bool $enabled,string $policy,bool $includeEarlier,string $actor): string {
    $db=db();$db->exec('BEGIN IMMEDIATE');try {
        $before=sessions();$a=active();if($enabled&&$a){$after=previewAutoPush($before,$a,$policy,$includeEarlier,max(time(),$a['end']));applyAutoPushRows($before,$after);}
        $options=['enabled'=>$enabled,'policy'=>$policy,'include_earlier'=>$includeEarlier];$db->prepare("INSERT INTO settings(key,value) VALUES('auto_push',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([json_encode($options)]);
        $db->prepare('INSERT INTO audit(at,actor,action,before_json,after_json) VALUES(?,?,?,?,?)')->execute([time(),$actor,$enabled?'ENABLE AUTO PUSH '.json_encode($options):'DISABLE AUTO PUSH',json_encode($before),json_encode(sessions())]);
        if($enabled)queueMessage('change:'.$db->lastInsertId(),'Automatic schedule shifting enabled. Pending sessions keep their durations; skipped and completed sessions stay unchanged. View the revised agenda.');
        $db->exec('COMMIT');return $enabled?'Auto pushing enabled.':'Auto pushing disabled. Current timings are retained.';
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function tickAutoPush(): void {
    $o=autoPushOptions();if(!$o['enabled'])return;$db=db();$db->exec('BEGIN IMMEDIATE');try{
        $a=active();if($a&&!activeBreak()){
            $before=sessions();$after=previewAutoPush($before,$a,$o['policy'],$o['include_earlier'],max(time(),$a['end']));
            if(applyAutoPushRows($before,$after))$db->prepare('INSERT INTO audit(at,actor,action,before_json,after_json) VALUES(?,?,?,?,?)')->execute([time(),'scheduler','AUTO PUSH',json_encode($before),json_encode(sessions())]);
        }$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');error_log('HIC auto-push held: '.$e->getMessage());}
}

function liveTopic(int $sessionId): string {
    $q=db()->prepare('SELECT topic FROM live_topics WHERE session_id=?');$q->execute([$sessionId]);return (string)($q->fetchColumn()?:'');
}
function setLiveTopic(int $sessionId,string $topic,string $actor): string {
    $topic=trim($topic);if(mb_strlen($topic)>200)throw new RuntimeException('Keep the current topic within 200 characters.');
    $db=db();$db->exec('BEGIN IMMEDIATE');try {
        $a=active();if(!$a||$a['id']!==$sessionId)throw new RuntimeException('The active session changed. Refresh before updating the topic.');
        $old=liveTopic($sessionId);$db->prepare('INSERT INTO live_topics(session_id,topic) VALUES(?,?) ON CONFLICT(session_id) DO UPDATE SET topic=excluded.topic')->execute([$sessionId,$topic]);
        $db->prepare('INSERT INTO audit(at,actor,action,before_json,after_json) VALUES(?,?,?,?,?)')->execute([time(),$actor,'CURRENT TOPIC',json_encode(['session_id'=>$sessionId,'topic'=>$old]),json_encode(['session_id'=>$sessionId,'topic'=>$topic])]);
        $db->exec('COMMIT');return 'Current topic updated on both live displays.';
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}

// One departure target for both public countdowns; never advances to another day.
function fieldDepartureAt(): int {return (new DateTimeImmutable('2026-11-17 08:00:00',new DateTimeZone('Africa/Nairobi')))->getTimestamp();}
