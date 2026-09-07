/* Pure DOM/network doubles, not certification of browsers or WordPress authentication. */
async function testRefundAdminPanel(source) {
    let submit, calls=[], assertions=0, accepted=true, valid=true;
    const check=(ok,label)=>{assertions++;if(!ok)throw new Error(label);};
    const message={textContent:''}, button={disabled:false}, confirmation={checked:false}, note={value:'Review'};
    const form={dataset:{endpoint:'/wp-json/veciahorra/v1/admin/deliveries/1001/cancel-and-refund',nonce:'nonce',version:'4',retry:'0',key:'persistent-key-00001'},reportValidity:()=>valid,
        querySelector:s=>s==='[data-result]'?message:s==='button'?button:s==='[name=confirmed]'?confirmation:note};
    const document={addEventListener:(event,fn)=>{submit=fn;}};
    const window={confirm:()=>accepted};
    const fetch=async(url,options)=>{calls.push({url,options});return {ok:true,json:async()=>({success:true,data:{status:'refunded'}})};};
    new Function('document','window','fetch',source)(document,window,fetch);
    const event={target:{closest:()=>form},preventDefault:()=>{}};
    await submit(event);check(calls.length===0,'Checkbox required');
    confirmation.checked=true;accepted=false;await submit(event);check(calls.length===0,'Irreversible dialog required');
    accepted=true;valid=false;await submit(event);check(calls.length===0,'Form validity');
    valid=true;await submit(event);
    check(calls.length===1&&JSON.parse(calls[0].options.body).expected_version===4,'Version sent');
    check(calls[0].options.headers['X-WP-Nonce']==='nonce'&&calls[0].options.credentials==='same-origin','Nonce and same origin');
    check(JSON.parse(calls[0].options.body).confirmed===true,'Explicit server confirmation');
    await submit(event);check(calls[0].url===calls[1].url&&calls[0].options.body===calls[1].options.body,'Replay retains exact key and payload');
    check(!button.disabled&&message.textContent.includes('confirmada'),'Result visible');
    return {REFUND_ADMIN_PANEL:'PASS',ASSERTIONS:assertions};
}
function testRefundCustomerPanel(source) {
    let assertions=0;
    const check=(ok,label)=>{assertions++;if(!ok)throw new Error(label);};
    const begin=source.indexOf('(detail.return_refunds || []).forEach');
    const end=source.indexOf('(detail.delivery_proofs || []).forEach',begin);
    check(begin>=0&&end>begin,'Customer refund connected');
    function element(tag,cls,text){return {textContent:text||'',children:[],append(...items){this.children.push(...items);}};}
    const render=new Function('detail','deliverySection','element',source.slice(begin,end));
    for(const status of ['refund_pending','refunded','refund_uncertain']){
        const section=element('div');
        render({return_refunds:[{order_id:1001,status,message:'Refund status',total_refund:9700,product_refund:8000,platform_fee_refund:700,delivery_fee_refund:1000,confirmed_at:status==='refunded'?'2026-09-07 12:00:00':null}],delivery_returns:[{order_id:1001,message:'Stale incident'}]},section,element);
        const text=JSON.stringify(section);
        check(text.includes('9700')&&text.includes('8000')&&text.includes('700')&&text.includes('1000'),'Breakdown visible');
        check(!text.includes('Stale incident'),'No contradictory incident copy');
        check(text.includes(status==='refunded'?'Monto devuelto':'Monto solicitado'),'Confirmed versus requested amount');
    }
    return {REFUND_CUSTOMER_PANEL:'PASS',ASSERTIONS:assertions};
}
