(()=>{
 const meta=document.querySelector('meta[name=hic-surface]');if(!meta)return;
 const surface=meta.content,revisionMeta=document.querySelector('meta[name=hic-revision]');
 let revision=revisionMeta.content,busy=false,stopped=false;
 const controller=new AbortController(),rootSelector=surface==='timer'?'.display':'#public-content';
 const assetUrl=value=>{const url=new URL(value,location.href);if(url.origin!==location.origin||!url.pathname.startsWith('/assets/'))throw Error('Invalid display asset');return url.href;};
 const loadScript=src=>new Promise((resolve,reject)=>{const script=document.createElement('script');script.src=assetUrl(src);script.onload=()=>{script.remove();resolve();};script.onerror=()=>{script.remove();reject(Error('Display script unavailable'));};document.head.append(script);});
 function loadStyle(href){return new Promise((resolve,reject)=>{const link=document.createElement('link');link.rel='stylesheet';link.href=assetUrl(href);link.dataset.hicStyle=surface;link.onload=()=>resolve(link);link.onerror=()=>{link.remove();reject(Error('Display stylesheet unavailable'));};document.head.append(link);});}
 async function update(manifest){
  const url=new URL(location.href);url.searchParams.set('_display_update',manifest.revision);
  const response=await fetch(url,{cache:'no-store',signal:AbortSignal.timeout(10000)});if(!response.ok)throw Error('Display markup unavailable');
  const documentCopy=new DOMParser().parseFromString(await response.text(),'text/html');
  if(documentCopy.querySelector('meta[name=hic-revision]')?.content!==manifest.revision)throw Error('Deployment changed during update');
  const freshRoot=documentCopy.querySelector(rootSelector),oldRoot=document.querySelector(rootSelector);if(!freshRoot||!oldRoot)throw Error('Display root missing');
  freshRoot.querySelectorAll('script').forEach(node=>node.remove());
  const oldStyles=[...document.querySelectorAll('link[rel=stylesheet]')].filter(link=>new URL(link.href).pathname===new URL(assetUrl(manifest.css)).pathname);
  const newStyle=await loadStyle(manifest.css),scroll={x:scrollX,y:scrollY},oldMount=window.HicViewMounts?.[surface],oldCountdown=window.HicCountdown;
  window.HicViews?.[surface]?.dispose();if(window.HicViews)delete window.HicViews[surface];oldRoot.replaceWith(freshRoot);
  const bootstrapId=surface==='timer'?'initial-state':'public-data',bootstrap=documentCopy.getElementById(bootstrapId),oldBootstrap=document.getElementById(bootstrapId);
  if(bootstrap&&oldBootstrap)oldBootstrap.textContent=bootstrap.textContent;
  try{
   for(const src of manifest.scripts)await loadScript(src);
   if(!window.HicViews?.[surface])throw Error('Display failed to mount');
   oldStyles.forEach(link=>link.remove());revision=manifest.revision;revisionMeta.content=revision;
   window.scrollTo(scroll.x,scroll.y);document.dispatchEvent(new CustomEvent('hic:display-updated',{detail:{surface,revision}}));
  }catch(error){
   window.HicViews?.[surface]?.dispose();freshRoot.replaceWith(oldRoot);newStyle.remove();
   window.HicCountdown=oldCountdown;if(oldMount){window.HicViewMounts[surface]=oldMount;window.HicViews[surface]=oldMount();}
   throw error;
  }
 }
 async function check(){if(busy||stopped||document.hidden)return;busy=true;try{const response=await fetch('/api/display-version?surface='+surface,{cache:'no-store',signal:AbortSignal.timeout(8000)});if(!response.ok)throw Error('Version check unavailable');const manifest=await response.json();if(manifest.surface===surface&&manifest.revision!==revision)await update(manifest);}catch{/* Keep the working display and retry on the next check. */}finally{busy=false;}}
 const interval=setInterval(check,5000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)check();},{signal:controller.signal});
 window.HicLiveUpdates?.stop();window.HicLiveUpdates={check,stop(){stopped=true;clearInterval(interval);controller.abort();}};check();
})();
