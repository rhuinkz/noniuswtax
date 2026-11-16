/* v0.4.45 - owner-managed local login photo gallery; no cloud APIs. */
(()=>{
  const source=document.getElementById('loginSlideSources');
  if(!source)return;
  let images;
  try{images=JSON.parse(source.textContent||'[]');}catch{return;}
  if(!Array.isArray(images)||!images.length)return;
  images=images.filter(x=>typeof x==='string'&&/^assets\/login-slides\/[a-f0-9]{32}\.jpg$/.test(x));
  if(!images.length)return;
  const first=document.querySelector('.login-photo-a');
  const second=document.querySelector('.login-photo-b');
  if(!first||!second)return;
  const reduced=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let queue=[],last=null,active=first;
  const shuffle=()=>{
    queue=images.slice();
    for(let i=queue.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[queue[i],queue[j]]=[queue[j],queue[i]];}
    if(queue.length>1&&queue[0]===last)[queue[0],queue[1]]=[queue[1],queue[0]];
  };
  const take=()=>{if(!queue.length)shuffle();const next=queue.shift();last=next;return next;};
  const preload=src=>new Promise(resolve=>{
    const img=new Image();img.onload=()=>resolve(true);img.onerror=()=>resolve(false);img.src=src;
  });
  const initial=take();first.style.backgroundImage=`url("${initial}")`;first.classList.add('visible');
  if(images.length===1||reduced)return;
  let changing=false;
  async function advance(){
    if(changing||document.hidden)return;
    changing=true;
    try{
      const url=take();
      if(!await preload(url))return;
      const next=active===first?second:first;
      next.style.backgroundImage=`url("${url}")`;
      next.classList.add('visible');active.classList.remove('visible');active=next;
    }finally{changing=false;}
  }
  setInterval(advance,6000);
})();
