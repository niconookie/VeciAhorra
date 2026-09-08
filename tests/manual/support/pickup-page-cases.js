const page=arguments[0],done=arguments[arguments.length-1];
(async()=>{
    let count=0;const check=(ok,label)=>{count++;if(!ok)throw Error(label);};
    await new Promise(resolve=>setTimeout(resolve,80));
    check(document.querySelector('[data-va-pickup]').hidden,'PICKUP_DEFAULT_NO_ALTERNATIVE');
    let control=document.querySelector(page==='cart'?'[data-va-cart-method]':'input[name="delivery_method"][value="delivery"]');
    check(!!control,'DELIVERY_INTENT_AVAILABLE_BELOW_MINIMUM');
    if(page==='cart')control.value='delivery';else control.checked=true;
    control.dispatchEvent(new Event('change',{bubbles:true}));await new Promise(resolve=>setTimeout(resolve,80));
    check(fixtureCalls.some(call=>call.url.endsWith('/cart/method')&&call.body.expected_version===4&&call.body.fulfillment_method==='delivery'),'PERSIST_DELIVERY_INTENT');
    check(!document.querySelector('[data-va-pickup]').hidden,'ALTERNATIVE_AFTER_DELIVERY_INTENT');
    if(page==='checkout')check(document.querySelector('[data-va-checkout-submit]').disabled,'BELOW_MINIMUM_CANNOT_CHECKOUT_DELIVERY');
    fixtureCart.fulfillment_method='pickup';fixtureCart.version++;fixtureCart.summary.pickup_search_eligible=false;
    window.dispatchEvent(new CustomEvent('va:pickup-accepted'));await new Promise(resolve=>setTimeout(resolve,80));
    check(document.querySelector('[data-va-pickup]').hidden,'ACCEPTANCE_HIDES_ALTERNATIVE');
    if(page==='cart')check(document.querySelector('[data-va-cart-method]').value==='pickup','CART_ACCEPT_REFRESH');
    else check(document.querySelector('input[name="delivery_method"]:checked').value==='pickup','CHECKOUT_ACCEPT_REFRESH');
    return {page,tests:1,assertions:count,failed:0,external_calls:0};
})().then(done,error=>done({page,failed:1,error:error.message}));
