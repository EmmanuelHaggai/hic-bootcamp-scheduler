window.HicViews=window.HicViews||{};window.HicViewMounts=window.HicViewMounts||{};window.HicSnapshots=window.HicSnapshots||{};
window.HicViewMounts.timer=function(){
 if(location.pathname==='/timer.php')history.replaceState(null,'','/timer'+location.search+location.hash);
 window.HicViews.timer?.dispose();let disposed=false;const lifecycle=new AbortController(),intervals=[];
 const setInterval=(fn,ms)=>{const id=window.setInterval(fn,ms);intervals.push(id);return id;};
 const listen=(target,type,handler)=>target.addEventListener(type,handler,{signal:lifecycle.signal});
let state=(window.HicSnapshots?.timer??JSON.parse(document.getElementById('initial-state').textContent));
let baseline=performance.now(),serverTime=state.server_time,lastSync=performance.now(),wakeLock=null;
const $=id=>document.getElementById(id);
const formatTime=t=>new Date(t*1000).toLocaleTimeString('en-GB',{timeZone:'Africa/Nairobi',hour:'2-digit',minute:'2-digit'});
const now=()=>serverTime+(performance.now()-baseline)/1000;
let noticeSignature='';
function renderNotices(){
 const notices=(state.notices??[]).filter(n=>n.at>=now()-3600).slice(0,3),signature=JSON.stringify(notices);
 if(signature===noticeSignature)return;noticeSignature=signature;
 $('announcements').hidden=notices.length===0;$('notice-list').replaceChildren();
 for(const [index,notice] of notices.entries()){
  const item=document.createElement('div');item.className='public-notice'+(index===0?' latest':'');
  const message=document.createElement('p');message.textContent=notice.message;
  const time=document.createElement('time');time.dateTime=new Date(notice.at*1000).toISOString();time.textContent=formatTime(notice.at)+' EAT';item.append(message,time);$('notice-list').append(item);
 }
}
function renderDeparture(liveNow){
 document.body.classList.add('departure-mode');
 const seconds=HicCountdown.remaining(state.departure,liveNow),visit=state.field_visit;
 document.body.classList.remove('paused','overtime');document.body.classList.toggle('warning',seconds>0&&seconds<=600);
 $('clock').textContent=new Date(liveNow*1000).toLocaleTimeString('en-GB',{timeZone:'Africa/Nairobi'})+' EAT';
 $('state-label').textContent='DAY 2 · FIELD DEPARTURE';$('title').textContent='Field Visit Departure';
 $('facilitator').textContent='IFSL projects, farms and water sources';$('countdown').textContent=HicCountdown.text(seconds);
 $('countdown-label').textContent=seconds?'until departure · be ready to leave at 8:00 AM EAT':'Departure time reached. Follow organizer updates.';
 $('scheduled').textContent='Tuesday, 17 November · 8:00 AM EAT';$('elapsed').textContent='Africa/Nairobi · UTC+3';
 document.querySelector('.progress').hidden=true;$('timing-review').hidden=true;
 $('next-title').textContent=visit?.title??'Field visits in Makueni';
 $('next-time').textContent=visit?formatTime(visit.start)+' – '+formatTime(visit.end)+' EAT':'8:00 AM departure';
}
function render(){
 renderNotices();
 if(state.display==='departure'){renderDeparture(now());if(performance.now()-lastSync>15000)$('sync').textContent='Connection lost · retrying';return;}
 document.body.classList.remove('departure-mode');document.querySelector('.progress').hidden=false;
 const current=state.current,next=state.next,liveNow=now();
 $('clock').textContent=new Date(liveNow*1000).toLocaleTimeString('en-GB',{timeZone:'Africa/Nairobi'})+' EAT';
 const paused=current?.status==='paused',reference=paused?(current.paused_at??liveNow):liveNow;
 const seconds=current?Math.ceil(current.end-reference):0,overtime=!!current&&seconds<0;
 document.body.classList.toggle('paused',paused);document.body.classList.toggle('overtime',overtime&&!paused);document.body.classList.toggle('warning',!!current&&!paused&&!overtime&&seconds<=600);
 $('state-label').textContent=state.break_active?(overtime?'BREAK ENDED':Math.round((current.end-current.start)/60)+'-MINUTE BREAK'):current?(paused?'SESSION PAUSED':overtime?'SESSION OVERTIME':'LIVE · CURRENT SESSION'):'AWAITING ORGANIZER';
 $('title').textContent=current?.title??'Ready for the next session';
 $('facilitator').textContent=current?((current.topic??current.agenda_title)?(current.topic??current.agenda_title)+' · ':'')+current.facilitator:'The organizer will start the next session from live control.';
 if(current){const abs=Math.abs(seconds),hours=Math.floor(abs/3600),minutes=Math.floor(abs%3600/60),secs=abs%60;$('countdown').textContent=(overtime?'+':'')+(hours?hours+':'+String(minutes).padStart(2,'0'):String(minutes).padStart(2,'0'))+':'+String(secs).padStart(2,'0');}else $('countdown').textContent='--:--';
 $('countdown-label').textContent=state.break_active?(overtime?'Break time is over · please return to the room':'remaining for the break · please return when the timer ends'):!current?'Waiting for a confirmed start':paused?'Paused · scheduled end stays fixed':overtime?'Overtime · awaiting organizer completion':'remaining in this session';
 $('scheduled').textContent=current?(current.actual_start?'Started '+formatTime(current.actual_start)+' EAT · expected end '+formatTime(current.end)+' EAT':'Scheduled '+formatTime(current.start)+' – '+formatTime(current.end)+' EAT'):'All times in East Africa Time';
 const elapsed=current?Math.max(0,Math.floor((reference-(current.actual_start??current.start))/60)):0;
 $('elapsed').textContent=current?elapsed+' min elapsed · '+Math.round((current.end-current.start)/60)+' min session':'';
 $('timing-review').hidden=!(state.timing_conflicts?.length)&&true;
 const start=current?(current.actual_start??current.start):0,percent=current?Math.min(100,Math.max(0,100*(reference-start)/Math.max(1,current.end-start))):0;
 $('progress').style.width=percent+'%';document.querySelector('.progress').setAttribute('aria-valuenow',String(Math.round(percent)));
 $('next-title').textContent=state.break_active&&next?.status==='paused'?'Return to: '+next.title:next?.title??'No further session queued';$('next-time').textContent=next?new Date(next.start*1000).toLocaleDateString('en-GB',{timeZone:'Africa/Nairobi',weekday:'short',day:'numeric',month:'short'})+' · '+formatTime(next.start)+' – '+formatTime(next.end)+' EAT':'';
 if(performance.now()-lastSync>15000)$('sync').textContent='Connection lost · retrying';
}
async function sync(){try{const response=await fetch('/api/timer'+(state.display==='departure'?'?display=departure':''),{cache:'no-store',signal:AbortSignal.timeout(8000)});if(!response.ok)throw Error('Unable to sync');const fresh=await response.json();if(disposed)return;if(!Number.isFinite(fresh.server_time))throw Error('Invalid state');state=fresh;window.HicSnapshots.timer=state;baseline=performance.now();serverTime=fresh.server_time;lastSync=performance.now();$('sync').textContent='● Live · updates every 5s';render();}catch{if(disposed)return;$('sync').textContent='Reconnecting · last known agenda';}}
async function keepAwake(){try{if('wakeLock'in navigator&&!wakeLock&&document.visibilityState==='visible'){wakeLock=await navigator.wakeLock.request('screen');wakeLock.addEventListener('release',()=>{wakeLock=null;});}}catch{/* Display remains usable when screen wake lock is unsupported. */}}
async function fullscreen(){try{if(document.fullscreenElement)await document.exitFullscreen();else if(document.documentElement.requestFullscreen)await document.documentElement.requestFullscreen();else $('fullscreen-help').textContent='Use your browser’s fullscreen or presentation mode.';await keepAwake();}catch{$('fullscreen-help').textContent='Fullscreen was unavailable. Try your browser’s fullscreen mode.';}}
listen($('fullscreen'),'click',fullscreen);
listen(document,'keydown',event=>{if(event.key.toLowerCase()==='f'&&!event.ctrlKey&&!event.metaKey&&!event.altKey){event.preventDefault();fullscreen();}});
listen(document,'fullscreenchange',()=>{const full=!!document.fullscreenElement;$('fullscreen').setAttribute('aria-label',full?'Exit fullscreen':'Enter fullscreen');$('fullscreen').querySelector('span').textContent=full?'Exit fullscreen':'Fullscreen';});
listen(document,'visibilitychange',()=>{if(!document.hidden){sync();keepAwake();}});
render();sync();setInterval(render,250);setInterval(sync,5000);

if(document.fullscreenElement){$('fullscreen').setAttribute('aria-label','Exit fullscreen');$('fullscreen').querySelector('span').textContent='Exit fullscreen';keepAwake();}

return {dispose(){if(disposed)return;disposed=true;state.server_time=now();window.HicSnapshots.timer=state;lifecycle.abort();for(const id of intervals)window.clearInterval(id);if(wakeLock)wakeLock.release().catch(()=>{});}};
};
window.HicViews.timer=window.HicViewMounts.timer();
