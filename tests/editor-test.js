// Offline regression tests: node tests/editor-test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const blocks = new Map();
const Disabled = function Disabled() {};
const ServerSideRender = function ServerSideRender() {};
const controls = new Proxy({ Disabled }, { get: (target, name) => target[name] || name });
let selected;
const wp = {
    data: { dispatch: () => ({ selectBlock: id => { selected = id; } }) },
    element: {
        createElement: (type, props, ...children) => ({ type, props, children }),
        useState: value => [value, () => {}],
        useEffect: () => {},
    },
    blockEditor: { InspectorControls: 'InspectorControls', useBlockProps: props => ({ 'data-block': 'selected', ...props }) },
    components: controls,
    serverSideRender: ServerSideRender,
    blocks: { registerBlockType: (name, definition) => blocks.set(name, definition) },
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/editor.js'), 'utf8'), { window: { wp } });
assert.equal(blocks.size, 13);
assert.equal([...blocks.values()].filter(block => block.supports?.inserter !== false).length, 10);
for (const name of ['crom/evenement-banniere', 'crom/evenement-en-cours', 'crom/evenements-liste']) {
    assert.equal(blocks.get(name).supports.inserter, false, `${name}: doublon proposé à l’insertion`);
}
for (const [name, definition] of blocks) {
    const attributes = Object.fromEntries(Object.entries(definition.attributes).map(([key, field]) => [key, field.default]));
    const tree = definition.edit({ name, clientId: name, attributes, setAttributes: () => {} });
    const preview = tree.children.find(child => child?.props?.className === 'crom-editor-preview');
    assert.ok(preview, `${name}: non-interactive preview missing`);
    assert.equal(preview.children[0].type, Disabled);
    assert.equal(preview.children[0].children[0].type, ServerSideRender);
    assert.equal(preview.children[0].children[0].props.block, name);
    let prevented = false;
    preview.props.onClickCapture({ preventDefault: () => { prevented = true; } });
    assert.ok(prevented);
    assert.equal(selected, name);
    assert.equal(definition.save(), null);
}
let initialized = false;
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/front.js'), 'utf8'), {
    document: { querySelectorAll: () => [{
        dataset: {}, closest: () => ({}),
        querySelector: () => { initialized = true; throw new Error('Inert preview initialized'); },
    }] },
});
assert.equal(initialized, false);
const miniature = blocks.get('crom/jeu-miniature');
const attrs = Object.fromEntries(Object.entries(miniature.attributes).map(([key, field]) => [key, field.default]));
let changed;
const tree = miniature.edit({ name:'crom/jeu-miniature', attributes:attrs, setAttributes: value => { changed = value; } });
const panel = tree.children.find(child => child?.type === 'InspectorControls').children[0];
const imageSide = panel.children.find(child => child.props.key === 'imageSide');
assert.ok(imageSide);
imageSide.props.onChange('droite');
assert.equal(changed.imageCote, 'droite');
assert.equal(tree.props.className, 'crom-editor-miniature');
const filteredTree = miniature.edit({ name:'crom/jeu-miniature', attributes:{...attrs,mode:'toutes',autoplay:true}, setAttributes:value=>{changed=value;} });
const filteredControls = filteredTree.children.find(child=>child?.type==='InspectorControls').children[0].children;
for(const key of ['genres','type','autoplay','delay'])assert.ok(filteredControls.some(child=>child.props.key===key), 'Missing filter/control: '+key);
filteredControls.find(child=>child.props.key==='delay').props.onChange(12);
assert.equal(changed.delay,12);
assert.equal(blocks.get('crom/parties-compactes').attributes.presentation, undefined);
console.log('Five dynamic previews disabled; miniature image side selectable; inert interactions skipped.');

const eventBlock = blocks.get('crom/evenement');
const eventAttributes = Object.fromEntries(Object.entries(eventBlock.attributes).map(([key, field]) => [key, field.default]));
const eventTree = eventBlock.edit({ name:'crom/evenement', attributes:{...eventAttributes,source:'futurs'}, setAttributes:value=>{changed=value;} });
const eventControls = eventTree.children.find(child=>child?.type==='InspectorControls').children[0].children;
const eventSources = eventControls.find(child=>child.props.key==='source').props.options.map(option=>option.value);
for (const mode of ['futurs', 'en_cours', 'selection', 'prochain']) assert.ok(eventSources.includes(mode));
assert.ok(eventControls.some(child=>child.props.key==='limit'));
eventControls.find(child=>child.props.key==='layout').props.onChange('liste');
assert.equal(changed.presentation, 'liste');
assert.equal(eventBlock.attributes.titre.default, '');
console.log('Bloc Événement : sources, liste/cartes, limite et titre facultatif : OK.');
