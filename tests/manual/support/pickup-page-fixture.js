window.VeciAhorra={restUrl:location.origin+'/wp-json/veciahorra/v1',nonce:'local-nonce',cart:{sessionId:''},currentUser:{loggedIn:true,id:9001},launch:{commerceEnabled:true},pickup:{googleBrowserKey:''},checkout:{minimumDeliveryAmount:8000,platformFeeClp:700,deliveryFeeClp:1000}};
window.fixtureCalls=[];
window.fixtureCart={success:true,cart_id:'a'.repeat(48),version:4,status:'active',fulfillment_method:'pickup',service_zone_id:1001,total:'3000.00',data:[{id:1001,product_id:1001,product_name:'Test product',offer_group:'test-offer-group',quantity:2,unit_price_snapshot:'1500.00',subtotal:'3000.00',valid:true,available:true}],summary:{product_subtotal:'3000.00',platform_fee:'700.00',delivery_fee:'0.00',total:'3700.00',currency:'CLP',delivery_eligible:false,fulfillment_method:'pickup',pickup_search_eligible:false}};
window.fetch=async(url,options)=>{
    const body=options.body?JSON.parse(options.body):null;
    fixtureCalls.push({url,body,method:options.method});
    if(options.method==='GET')return new Response(JSON.stringify(fixtureCart),{status:200});
    if(url.endsWith('/cart/method')){
        fixtureCart.version++;fixtureCart.fulfillment_method=body.fulfillment_method;fixtureCart.summary.fulfillment_method=body.fulfillment_method;fixtureCart.summary.pickup_search_eligible=body.fulfillment_method==='delivery';
        return new Response(JSON.stringify({success:true,data:{cart:{...fixtureCart,items:fixtureCart.data}}}),{status:200});
    }
    throw new Error('Unexpected request: '+url);
};
