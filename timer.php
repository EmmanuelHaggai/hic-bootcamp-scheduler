<?php
require __DIR__.'/lib/app.php';
require_once __DIR__.'/lib/display.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'none'; frame-ancestors 'none'");
function displaySession(array $s): array {
    $display=array_intersect_key($s,array_flip(['id','title','facilitator','start','end','status','actual_start','paused_at','revision']));
    if($s['id']>0&&in_array($s['status'],['active','paused'],true)&&($topic=liveTopic($s['id']))){$display['agenda_title']=$display['title'];$display['title']=$topic;}
    if(str_starts_with($s['title'],'KRCS/ICHA')){$display['agenda_title']=$s['title'];$display['topic']=$s['id']>0?liveTopic($s['id']):'';$display['title']='KRCS';}
    return $display;
}
function timerState(bool $departureMode=false): array {
    $rows=sessions();$now=microtime(true);$current=null;$next=null;
    foreach($rows as $r) if(in_array($r['status'],['active','paused'],true)){$current=$r;break;}
    foreach($rows as $r) if($r['status']==='scheduled' && ($current ? $r['start']>$current['start'] : $r['end']>$now)){$next=$r;break;}
    $conflicts=liveTimingConflicts($current,$rows);
    $break=activeBreak();if($break){$next=$current??$next;$current=['id'=>-$break['id'],'title'=>'Break','facilitator'=>'Please take a '.(int)(($break['end']-$break['start'])/60).'-minute break','start'=>$break['start'],'end'=>$break['end'],'status'=>'active','actual_start'=>$break['start'],'paused_at'=>null,'revision'=>0];}
    $notices=publicNotices();
    $visit=null;foreach($rows as $s)if($s['day']==='2026-11-17'&&str_starts_with($s['title'],'Field Visits:')){$visit=displaySession($s);break;}
    return ['display'=>$departureMode?'departure':'session','departure'=>fieldDepartureAt(),'field_visit'=>$visit,'timing_conflicts'=>$conflicts,'break_active'=>(bool)$break,'notices'=>$notices,'server_time'=>$now,'current'=>$current?displaySession($current):null,'next'=>$next?displaySession($next):null];
}
if(isset($_GET['state'])) {
    header('Content-Type: application/json; charset=utf-8');
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');echo json_encode(['error'=>'Read-only display']);exit;}
    echo json_encode(timerState(($_GET['display']??'')==='departure'),JSON_THROW_ON_ERROR);exit;
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="hic-surface" content="timer"><meta name="hic-revision" content="<?=displayManifest('timer')['revision']?>"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#102922"><title>HIC Bootcamp · Public Timer</title><link rel="stylesheet" href="/assets/timer.css?v=<?=filemtime(__DIR__.'/assets/timer.css')?>"><script src="/assets/countdown.js?v=<?=filemtime(__DIR__.'/assets/countdown.js')?>" defer></script><script src="/assets/timer.js?v=<?=filemtime(__DIR__.'/assets/timer.js')?>" defer></script><script src="/assets/live-updates.js?v=<?=filemtime(__DIR__.'/assets/live-updates.js')?>" defer></script></head>
<body><div class="display" id="display"><header><a href="/" class="brand"><span>+</span> HIC <small>INNOVATION BOOTCAMP</small></a><div class="tools"><span id="clock">EAT · Africa/Nairobi</span><button id="fullscreen" type="button" aria-label="Enter fullscreen">⛶ <span>Fullscreen</span></button></div></header>
<section class="announcements" id="announcements" aria-label="Bootcamp notifications" hidden><div class="notice-heading">◉ &nbsp; BOOTCAMP NOTIFICATIONS</div><div id="notice-list" aria-live="polite" aria-atomic="true"></div></section><main><div class="session-state"><span class="indicator"></span><span id="state-label">CONNECTING TO LIVE AGENDA</span></div><h1 id="title">Bootcamp live timer</h1><p id="facilitator">Makueni · 16–18 November 2026</p><div class="countdown" id="countdown" role="timer" aria-live="off">--:--</div><div id="countdown-label">Loading the current session…</div><div class="progress" role="progressbar" aria-label="Session elapsed" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div id="progress"></div></div><div id="timing-review" class="timing-review" hidden>Later agenda times need review to fit this session’s full duration.</div><div class="timing"><span id="scheduled">Scheduled times in East Africa Time</span><span id="elapsed"></span></div></main>
<footer><div class="next"><span class="eyebrow">UP NEXT</span><strong id="next-title">Waiting for the agenda</strong><span id="next-time"></span></div><div class="connection"><span id="sync" role="status">Connecting…</span><small>Live agenda · EAT (UTC+3)</small></div></footer><p id="fullscreen-help" role="status">Press F or use Fullscreen. Press Esc to exit.</p></div>
<noscript><p class="noscript">Enable JavaScript to show the live countdown and schedule updates.</p></noscript>
<script type="application/json" id="initial-state"><?=json_encode(timerState(($_GET['mode']??'departure')!=='session'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_THROW_ON_ERROR)?></script>
</body></html>
