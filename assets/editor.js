(function (wp) {
    'use strict';
    const el = wp.element.createElement;
    const { useState, useEffect } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, SelectControl, ComboboxControl, FormTokenField, TextControl, RangeControl, ToggleControl, Notice, Disabled } = wp.components;
    const ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;
    const EVENT_BLOCKS = ['crom/evenement','crom/evenement-parties','crom/evenement-jeux','crom/evenement-helloasso','crom/evenement-texte'];
    const FIELD = (type, value) => ({ type, default: value });
    const EVENT_SOURCE = { source: FIELD('string','prochain'), evenementId: FIELD('number',0), serie: FIELD('string','') };
    const BLOCKS = [
        {name:'crom/cover',title:'CROM — Bannière',icon:'cover-image',description:'Une bannière de jeu, le défilement des visuels des jeux (comme sur CROM) ou les parties d’un événement.',
            attributes:{mode:FIELD('string','jeu'),jeuId:FIELD('number',0),titre:FIELD('string',''),texte:FIELD('string',''),autoplay:FIELD('boolean',true),delay:FIELD('number',6),hauteur:FIELD('number',400),typeJeu:FIELD('string',''),genres:FIELD('array',[]),presentation:FIELD('string','plein'),limite:FIELD('number',6),...EVENT_SOURCE}},
        {name:'crom/jeu',title:'CROM — Jeu',icon:'games',description:'Un jeu choisi ou le premier, deuxième ou troisième du classement.',
            attributes:{mode:FIELD('string','choisi'),jeuId:FIELD('number',0),rang:FIELD('number',1)}},
        {name:'crom/jeu-miniature',title:'CROM — Jeu miniature',icon:'games',description:'Carte horizontale de 300 px, avec l’image du jeu à gauche ou à droite.',
            attributes:{mode:FIELD('string','choisi'),jeuId:FIELD('number',0),rang:FIELD('number',1),imageCote:FIELD('string','gauche'),typeJeu:FIELD('string',''),genres:FIELD('array',[]),autoplay:FIELD('boolean',true),delay:FIELD('number',6)}},
        {name:'crom/parties-compactes',title:'CROM — Parties compactes',icon:'calendar-alt',description:'Petites cartes horizontales comme sur l’accueil CROM, avec les détails en modal.',
            attributes:{mode:FIELD('string','prochaines'),lien:FIELD('string',''),limite:FIELD('number',6),ouvertes:FIELD('boolean',false),evenement:FIELD('string',''),evenementId:FIELD('number',0),titre:FIELD('string','')}},
        {name:'crom/parties',title:'CROM — Parties',icon:'calendar-alt',description:'Parties à venir, ce week-end, une sélection par lien ou les parties d’un événement.',
            attributes:{mode:FIELD('string','prochaines'),lien:FIELD('string',''),presentation:FIELD('string','carte'),hauteurCarte:FIELD('number',320),limite:FIELD('number',6),ouvertes:FIELD('boolean',false),evenement:FIELD('string',''),evenementId:FIELD('number',0),titre:FIELD('string','')}},
        {name:'crom/evenement',title:'CROM — Événement',icon:'megaphone',description:'Événements à venir, événement en cours ou choisi, en liste ou en cartes.',attributes:{...EVENT_SOURCE,description:FIELD('boolean',true),presentation:FIELD('string','carte'),limite:FIELD('number',6),titre:FIELD('string','')}},
        {name:'crom/evenements-liste',supports:{inserter:false},title:'CROM — Liste des événements',icon:'calendar',description:'Tous les événements en cours et à venir.',attributes:{limite:FIELD('number',6),presentation:FIELD('string','carte'),titre:FIELD('string','')}},
        {name:'crom/evenement-parties',title:'CROM — Parties d’un événement',icon:'calendar-alt',description:'Parties liées au prochain événement ou à un événement choisi.',attributes:{...EVENT_SOURCE,presentation:FIELD('string','carte'),limite:FIELD('number',12),ouvertes:FIELD('boolean',false),hauteurCarte:FIELD('number',320),titre:FIELD('string','')}},
        {name:'crom/evenement-jeux',title:'CROM — Jeux d’un événement',icon:'games',description:'Jeux proposés dans les parties d’un événement.',attributes:{...EVENT_SOURCE,limite:FIELD('number',12),titre:FIELD('string',''),imageCote:FIELD('string','gauche')}},
        {name:'crom/evenement-helloasso',title:'CROM — Bouton HelloAsso',icon:'tickets-alt',description:'Bouton vers la page HelloAsso d’un événement payant.',attributes:{...EVENT_SOURCE,libelle:FIELD('string','Réserver sur HelloAsso')}},
        {name:'crom/evenement-texte',title:'CROM — Texte public d’événement',icon:'text',description:'Description publique de l’événement en cours, sinon du prochain, ou d’un événement choisi.',attributes:{...EVENT_SOURCE,titre:FIELD('boolean',false)}}];
    function Edit(props) {
        const a = props.attributes, set = props.setAttributes;
        function selectPreview(event) {
            event.preventDefault();
            wp.data.dispatch('core/block-editor').selectBlock(props.clientId);
        }
        const [games, setGames] = useState([]), [events, setEvents] = useState([]), [error, setError] = useState('');
        const isCover = props.name === 'crom/cover', isGame = ['crom/jeu', 'crom/jeu-miniature'].includes(props.name);
        const isMiniature = props.name === 'crom/jeu-miniature', isCompact = props.name === 'crom/parties-compactes';
        const eventBlock = EVENT_BLOCKS.includes(props.name), isSessionsBlock = ['crom/parties','crom/parties-compactes'].includes(props.name);
        const needsEvents = eventBlock || isSessionsBlock || isCover;
        useEffect(function () {
            let active = true;
            if (isCover || isGame) {
                wp.apiFetch({ path: '/crom-link/v1/jeux' }).then(function (data) { if (active) setGames(data); }).catch(function () { if (active) setError('Catalogue indisponible. Vérifiez Réglages → Blocs CROM.'); });
            }
            if (needsEvents) {
                wp.apiFetch({ path: '/crom-link/v1/evenements' }).then(function (data) { if (active) setEvents(data); }).catch(function () { if (active) setError('Événements indisponibles. Vérifiez Réglages → CROM.'); });
            }
            return function () { active = false; };
        }, []);
        const controls = [];
        function control(Component, key, options) { controls.push(el(Component, Object.assign({ key }, options))); }
        const eventOptions = events.map(e => ({ value: String(e.id), label: e.nom + (e.date_label ? ' — ' + e.date_label : '') }));
        function eventPicker(sourceKey, withSessions, allModes = false) {
            control(SelectControl, 'source', {label:'Événement', value:a[sourceKey] || (sourceKey==='source'?'prochain':''), options:(sourceKey==='evenement'?[{label:'Toutes les parties',value:''}]:[]).concat([{label:withSessions?'Événement avec parties':'En cours, sinon prochain événement',value:'prochain'},{label:'Un événement choisi',value:'selection'},...(allModes?[{label:'Tous les événements à venir',value:'futurs'},{label:'Événement en cours uniquement',value:'en_cours'}]:[])]), onChange:v=>set({[sourceKey]:v})});
            if (sourceKey === 'source') control(TextControl, 'serie', {label:'Série d’événements (optionnel)', value:a.serie, help:'Nom récurrent, par exemple « Week-end FSV » : le prochain événement dont le nom le contient est affiché automatiquement.', onChange:serie=>set({serie})});
            if (a[sourceKey] === 'selection') control(ComboboxControl, 'event', {label:'Événement choisi', value:String(a.evenementId || ''), options:eventOptions, onChange:id=>set({evenementId:Number(id || 0)})});
        }
        const coverSessions = isCover && ['parties','evenement'].includes(a.mode);
        if (isCover || isGame) {
            control(SelectControl, 'mode', {label: 'Afficher', value:a.mode, options:isCover ? [{label:'Bannière d’un jeu',value:'jeu'},{label:'Défilement de toutes les bannières',value:'toutes'},{label:'Prochaines parties (visuels des jeux)',value:'parties'},{label:'Parties d’un événement',value:'evenement'}] : [{label:'Un jeu choisi',value:'choisi'},{label:'Un jeu du classement',value:'classement'},...(isMiniature?[{label:'Défilement des jeux filtrés',value:'toutes'}]:[])], onChange:mode=>set({mode})});
            if (a.mode === 'classement') control(SelectControl, 'rank', {label:'Place au classement (parties déjà jouées)',value:a.rang,options:[{label:'1er',value:1},{label:'2e',value:2},{label:'3e',value:3}],onChange:rang=>set({rang:Number(rang)})});
            else if (a.mode !== 'toutes' && !coverSessions) control(ComboboxControl, 'game', {label:'Jeu', value:String(a.jeuId || ''),options:games.map(g=>({value:String(g.id),label:g.nom})),onChange:jeuId=>set({jeuId:Number(jeuId || 0)})});
            if(isMiniature) control(SelectControl,'imageSide',{label:'Position de l’image',value:a.imageCote,options:[{label:'À gauche',value:'gauche'},{label:'À droite',value:'droite'}],onChange:imageCote=>set({imageCote})});
            if (isCover) {
                control(SelectControl,'presentation',{label:'Présentation',value:a.presentation,options:[{label:'Image pleine largeur (bannières)',value:'plein'},{label:'Galerie CROM (texte et visuel du jeu)',value:'galerie'}],help:'La galerie reprend la bannière de l’accueil CROM et accepte aussi les jeux sans bannière.',onChange:presentation=>set({presentation})});
                if (a.mode === 'evenement') eventPicker('source', true);
                if (coverSessions) control(RangeControl,'limit',{label:'Nombre maximum de diapositives',value:a.limite,min:1,max:12,onChange:limite=>set({limite})});
                control(TextControl,'title',{label:'Titre (vide : aucun titre)',value:a.titre,onChange:titre=>set({titre})});
                control(TextControl,'text',{label:'Texte supplémentaire',value:a.texte,onChange:texte=>set({texte})});
                control(RangeControl,'height',{label:'Hauteur de la bannière',value:a.hauteur,min:200,max:800,step:20,onChange:hauteur=>set({hauteur})});
            }
            if(coverSessions) {
                control(ToggleControl,'autoplay',{label:'Défilement automatique',checked:a.autoplay,onChange:autoplay=>set({autoplay})});
                if(a.autoplay) control(RangeControl,'delay',{label:'Durée d’affichage (secondes)',value:a.delay,min:3,max:20,onChange:delay=>set({delay})});
            }
            if((isCover || isMiniature) && a.mode==='toutes') {
                const genres=Array.from(new Map(games.flatMap(g=>g.genres || []).map(g=>[g.id,g])).values()).sort((a,b)=>a.nom.localeCompare(b.nom,'fr'));
                control(SelectControl,'type',{label:'Type de jeu',value:a.typeJeu,options:[{label:'Tous les types',value:''},{label:'Jeux de rôle',value:'JDR'},{label:'Jeux de société',value:'JEU_DE_SOCIETE'}],onChange:typeJeu=>set({typeJeu})});
                control(FormTokenField,'genres',{label:'Genres',value:(a.genres || []).map(id=>genres.find(g=>g.id===id)?.nom).filter(Boolean),suggestions:genres.map(g=>g.nom),help:'Aucun genre sélectionné : tous les genres. Sinon, au moins un des genres choisis.',onChange:tokens=>set({genres:tokens.map(name=>genres.find(g=>g.nom===name)?.id).filter(Boolean)}),__experimentalValidateInput:name=>genres.some(g=>g.nom===name)});
                control(ToggleControl,'autoplay',{label:'Défilement automatique',checked:a.autoplay,onChange:autoplay=>set({autoplay})});
                if(a.autoplay) control(RangeControl,'delay',{label:'Durée d’affichage (secondes)',value:a.delay,min:3,max:isMiniature?60:20,onChange:delay=>set({delay})});
            }
        } else if (eventBlock || props.name === 'crom/evenements-liste') {
            const n = props.name, listOnly = n === 'crom/evenements-liste', eventDisplay = listOnly || n === 'crom/evenement';
            if (eventBlock) eventPicker('source', n === 'crom/evenement-parties' || n === 'crom/evenement-jeux', n === 'crom/evenement');
            if (n === 'crom/evenement' && a.presentation !== 'liste') control(ToggleControl,'description',{label:'Afficher un extrait de la description',checked:a.description,onChange:description=>set({description})});
            if (n === 'crom/evenement-texte') control(ToggleControl,'titleToggle',{label:'Afficher le nom de l’événement',checked:a.titre,onChange:titre=>set({titre})});
            if (n === 'crom/evenement-helloasso') control(TextControl,'label',{label:'Texte du bouton',value:a.libelle,help:'Le bouton n’apparaît que pour un événement payant.',onChange:libelle=>set({libelle})});
            if (eventDisplay || n === 'crom/evenement-jeux' || n === 'crom/evenement-parties') control(TextControl,'blockTitle',{label:'Titre (vide : aucun titre)',value:a.titre,onChange:titre=>set({titre})});
            if (eventDisplay) control(SelectControl,'layout',{label:'Présentation',value:a.presentation,options:[{label:'Cartes',value:'carte'},{label:'Liste',value:'liste'}],onChange:presentation=>set({presentation})});
            if (n === 'crom/evenement-jeux') control(SelectControl,'imageSide',{label:'Position de l’image',value:a.imageCote,options:[{label:'À gauche',value:'gauche'},{label:'À droite',value:'droite'}],onChange:imageCote=>set({imageCote})});
            if (n === 'crom/evenement-parties') {
                control(SelectControl,'layout',{label:'Présentation',value:a.presentation,options:[{label:'Cartes comme sur l’accueil',value:'carte'},{label:'Grandes cartes',value:'grande'},{label:'Cartes compactes',value:'compacte'},{label:'Tableau',value:'table'}],onChange:presentation=>set({presentation})});
                control(ToggleControl,'open',{label:'Uniquement les parties ouvertes avec des places',checked:a.ouvertes,onChange:ouvertes=>set({ouvertes})});
            }
            if (listOnly || (n === 'crom/evenement' && a.source === 'futurs') || n === 'crom/evenement-jeux' || n === 'crom/evenement-parties') control(RangeControl,'limit',{label:'Nombre maximum',value:a.limite,min:1,max:50,onChange:limite=>set({limite})});
        } else {
            control(SelectControl,'mode',{label:'Parties à afficher',value:a.mode,options:[{label:'Prochaines ouvertes à l’inscription',value:'prochaines'},{label:'Ce week-end (samedi et dimanche)',value:'weekend'},{label:'Une partie ou campagne par son lien',value:'lien'}],onChange:mode=>set({mode})});
            if(a.mode==='lien') control(TextControl,'link',{label:'Lien de la partie ou campagne CROM',value:a.lien,help:'Collez le lien /partie?id=… ou /activite?id=… ; l’identifiant est détecté automatiquement.',onChange:lien=>set({lien})});
            eventPicker('evenement', true);
            if(!isCompact) control(SelectControl,'layout',{label:'Présentation',value:a.presentation,options:[{label:'Cartes comme sur l’accueil',value:'carte'},{label:'Grandes cartes',value:'grande'},{label:'Tableau',value:'table'}],onChange:presentation=>set({presentation})});
            if(a.presentation==='grande') control(SelectControl,'cardHeight',{label:'Hauteur des grandes cartes',value:String(a.hauteurCarte),options:[{label:'Compacte — 280 px',value:'280'},{label:'Standard — 320 px',value:'320'},{label:'Grande — 400 px',value:'400'},{label:'Très grande — 480 px',value:'480'}],help:'L’image est cadrée dans la carte. Le texte long reste accessible par défilement.',onChange:hauteurCarte=>set({hauteurCarte:Number(hauteurCarte)})});
            control(RangeControl,'limit',{label:'Nombre maximum de parties',value:a.limite,min:1,max:50,onChange:limite=>set({limite})});
            if(a.mode!=='prochaines') control(ToggleControl,'open',{label:'Uniquement les parties ouvertes avec des places',checked:a.ouvertes,onChange:ouvertes=>set({ouvertes})});
        }
        return el('div', useBlockProps({className:isMiniature?'crom-editor-miniature':isCompact?'crom-editor-compact':''}),
            el(InspectorControls,{},el(PanelBody,{title:'Réglages CROM',initialOpen:true},...controls)),
            error ? el(Notice,{status:'warning',isDismissible:false},error) : null,
            // Keep server-rendered markup and styles, but make the entire preview inert.
            // Events still bubble to useBlockProps so clicking selects the block.
            el('div', {className:'crom-editor-preview', onClickCapture:selectPreview, onAuxClickCapture:selectPreview},
                el(Disabled, {}, el(ServerSideRender,{block:props.name,attributes:a,httpMethod:'GET'}))
            )
        );
    }
    // Préserver l’édition des anciennes instances sans proposer de nouveaux doublons.
    const event = BLOCKS.find(block => block.name === 'crom/evenement');
    ['crom/evenement-banniere','crom/evenement-en-cours'].forEach(name => {
        BLOCKS.push({...event, name, supports:{inserter:false}});
        EVENT_BLOCKS.push(name);
    });
    BLOCKS.forEach(function (block) {
        wp.blocks.registerBlockType(block.name,{supports:block.supports,attributes:block.attributes,apiVersion:3,title:block.title,description:block.description,icon:block.icon,category:'crom',edit:Edit,save:()=>null});
    });
})(window.wp);
