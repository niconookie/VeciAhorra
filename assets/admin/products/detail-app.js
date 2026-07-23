import {createProductDetailApi} from './detail-api.js';
import {createProductDetailStore} from './detail-store.js';
import {createProductDetailView} from './detail-view.js';
import {buildInventoryAdminUrl} from './navigation.js';

if (!window.__veciahorraProductDetailInitialized) {
    window.__veciahorraProductDetailInitialized = true;
    initialize();
}

function initialize() {
    const config=readConfig();
    const nodes={
        main:document.getElementById('veciahorra-product-detail-main'),
        messages:document.getElementById('veciahorra-product-detail-messages'),
    };
    const api=createProductDetailApi(config);
    const store=createProductDetailStore(api,config.productId);
    const actions={
        listUrl:config.listUrl,
        editUrl:config.editUrl,
        inventoryListUrl:(id)=>buildInventoryAdminUrl(config.inventoryUrl,id),
        inventoryCreateUrl:(id)=>buildInventoryAdminUrl(config.inventoryUrl,id,'create'),
        reload:()=>store.load(),
        changeStatus:(status)=>store.changeStatus(status),
    };
    const view=createProductDetailView(nodes,actions);
    store.subscribe(view.render);
    view.render(store.getState());
    store.load();
}

function readConfig() {
    const raw=JSON.parse(document.getElementById('veciahorra-products-config').textContent);
    if (!Number.isInteger(raw.productId) || raw.productId<1) throw new Error('Product ID invalido.');
    ['restUrl','nonce','listUrl','editUrl','inventoryUrl'].forEach((key)=>{
        if (typeof raw[key]!=='string' || raw[key]==='') throw new Error(`Configuracion ${key} invalida.`);
    });
    return raw;
}
