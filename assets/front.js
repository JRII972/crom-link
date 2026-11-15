(function () {
    'use strict';
    document.querySelectorAll('[data-crom-details]').forEach(function (button) {
        if (button.closest('[inert], .crom-editor-preview') || button.dataset.cromReady) return;
        button.dataset.cromReady = 'true';
        button.addEventListener('click', function () {
            const dialog = document.getElementById(button.dataset.cromDetails);
            if (dialog && !dialog.open) dialog.showModal();
        });
    });
    document.querySelectorAll('[data-crom-carousel="true"]').forEach(function (root) {
        // Also protect previews if a theme loads this script in an editor iframe.
        if (root.closest('[inert], .crom-editor-preview') || root.dataset.cromReady) return;
        root.dataset.cromReady = 'true';
        const track=root.querySelector('.carousel'), slides=Array.from(track.children);
        if(slides.length<2) return;
        const reduced=window.matchMedia('(prefers-reduced-motion: reduce)');
        let index=0, paused=false, timer;
        function position() {
            const box=track.getBoundingClientRect();
            index=slides.reduce((best,slide,i)=>Math.abs(slide.getBoundingClientRect().left-box.left)<Math.abs(slides[best].getBoundingClientRect().left-box.left)?i:best,0);
            root.querySelector('[data-crom-position]').textContent=(index+1)+' / '+slides.length;
        }
        function move(direction) {
            index=(index+direction+slides.length)%slides.length;
            track.scrollTo({left:slides[index].offsetLeft-slides[0].offsetLeft,behavior:reduced.matches?'instant':'smooth'});
        }
        function stop() { clearInterval(timer); }
        function start() {
            stop();
            if(root.dataset.autoplay==='true' && !paused && !reduced.matches && !document.hidden && !root.matches(':hover') && !root.contains(document.activeElement)) timer=setInterval(()=>move(1),Number(root.dataset.delay)*1000);
        }
        root.querySelector('[data-crom-prev]').addEventListener('click',()=>move(-1));
        root.querySelector('[data-crom-next]').addEventListener('click',()=>move(1));
        const pause=root.querySelector('[data-crom-pause]');
        if(pause) pause.addEventListener('click',function () { paused=!paused; pause.textContent=paused?'Reprendre':'Pause'; pause.setAttribute('aria-pressed',String(paused)); start(); });
        root.addEventListener('mouseenter',stop); root.addEventListener('mouseleave',start);
        root.addEventListener('focusin',stop); root.addEventListener('focusout',()=>setTimeout(start,0));
        document.addEventListener('visibilitychange',start); reduced.addEventListener('change',start);
        track.addEventListener('scroll',position,{passive:true}); window.addEventListener('resize',position);
        start();
    });
})();
