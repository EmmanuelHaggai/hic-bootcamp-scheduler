window.HicCountdown=Object.freeze({
 remaining(target,now){return Math.max(0,Math.ceil(target-now));},
 parts(seconds){return {hours:String(Math.floor(seconds/3600)).padStart(2,'0'),minutes:String(Math.floor(seconds%3600/60)).padStart(2,'0'),seconds:String(seconds%60).padStart(2,'0')};},
 text(seconds){const p=this.parts(seconds);return p.hours+':'+p.minutes+':'+p.seconds;}
});
