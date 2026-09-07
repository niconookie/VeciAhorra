/* DOM/network doubles, not physical browser certification. */
async function testReturnPanel(source){
    var count=0,handlers={},nodes={},posts=[],confirm=true;
    function check(ok,label){count++;if(!ok)throw new Error(label);}
    var root={querySelector:function(s){return nodes[s]||(nodes[s]={innerHTML:'',textContent:''});},addEventListener:function(n,f){handlers[n]=f;}};
    var document={querySelector:function(){return root;},createElement:function(){return {textContent:'',get innerHTML(){return this.textContent;}};}};
    var window={confirm:function(){return confirm;},VeciAhorra:{api:{get:function(p){return Promise.resolve({data:p==='/courier/me'?{}:p==='/courier/returns'?[{order_id:1001,status:'return_pending',business_name:'Original',address:'Address'},{order_id:1002,status:'returned_to_store'}]:p==='/courier/deliveries'?[{id:1001,status:'picked_up',transition_version:2,minimarket:{},delivery:{}}]:[]});},post:function(path,body){posts.push({path:path,body:body});return Promise.resolve({});}}}};
    new Function('document','window',source)(document,window);
    async function flush(){for(var i=0;i<25;i++)await Promise.resolve();}await flush();
    var html=nodes['[data-va-courier-owned]'].innerHTML;
    check(html.includes('Informar problema de entrega')&&html.includes('data-confirm-delivery'),'NORMAL_AND_RETURN_OPTIONS');
    check(html.includes('recipient_absent')&&html.includes('recipient_rejected')&&html.includes('otp_unavailable')&&html.includes('photo_unavailable'),'CLOSED_REASONS_UI');
    check(nodes['[data-va-courier-returns]'].innerHTML.includes('Original')&&nodes['[data-va-courier-returns]'].innerHTML.includes('Tu custodia terminó'),'CUSTODY_INSTRUCTIONS');
    var button={},form={elements:{confirmed:{checked:false},reason:{value:'otp_unavailable'},observation:{value:' note '}},closest:function(s){return s==='[data-id]'?{dataset:{id:'1001',version:'2'}}:s==='[data-return-delivery]'?form:null;},checkValidity:function(){return this.elements.confirmed.checked;},reportValidity:function(){},querySelector:function(){return button;}};
    var event={target:form,preventDefault:function(){}};handlers.submit(event);await flush();check(posts.length===0,'EXPLICIT_CONFIRMATION_REQUIRED');
    form.elements.confirmed.checked=true;confirm=false;handlers.submit(event);await flush();check(posts.length===0,'CANCEL_PRESERVES_DELIVERY');
    confirm=true;handlers.submit(event);await flush();check(posts.length===1&&posts[0].path==='/courier/deliveries/1001/return'&&posts[0].body.expected_version===2&&posts[0].body.confirmed===true,'RETURN_COMMAND_VERSION');
    check(Object.keys(posts[0].body).sort().join(',')==='confirmed,expected_version,observation,reason'&&posts[0].body.observation==='note','NO_OTP_OR_PHOTO_IN_INCIDENT');
    return {RETURN_PANEL:'PASS',ASSERTIONS:count,BROWSER_CERTIFICATION:false};
}
async function testStoreReturnPanel(source){
    var count=0,fetches=[],form,accept=true;
    function check(ok,label){count++;if(!ok)throw new Error(label);}
    function element(tag){var node={tag:tag,children:[],innerHTML:'',textContent:'',append:function(child){this.children.push(child);},replaceChildren:function(){this.children=Array.from(arguments);},addEventListener:function(n,f){this[n]=f;},querySelector:function(){return {};}};if(tag==='form'){form=node;node.elements={condition:{value:'damaged'},observation:{value:' observed '}};node.checkValidity=function(){return true;};}return node;}
    var root=element('div'),document={querySelector:function(){return root;},createElement:element};
    var window={confirm:function(){return accept;},VeciAhorraMinimarket:{restUrl:'/wp-json/veciahorra/v1/minimarket/',nonce:'nonce'},fetch:function(url,options){fetches.push({url:url,options:options});return Promise.resolve({ok:true,json:function(){return Promise.resolve({success:true,data:options.method==='GET'?[{id:1001,order_id:1001,transition_version:3}]:{}});}});}};
    new Function('document','window',source)(document,window);async function flush(){for(var i=0;i<20;i++)await Promise.resolve();}await flush();
    check(form.innerHTML.includes('intact')&&form.innerHTML.includes('damaged')&&form.innerHTML.includes('incomplete'),'RECEIPT_CONDITIONS_UI');
    accept=false;form.submit({preventDefault:function(){}});await flush();check(fetches.length===1,'RECEIPT_CANCEL_NO_WRITE');
    accept=true;form.submit({preventDefault:function(){}});await flush();var command=fetches[1],body=JSON.parse(command.options.body);
    check(command.url==='/wp-json/veciahorra/v1/minimarket/returns/1001/receive'&&body.expected_version===3&&body.condition==='damaged'&&body.observation==='observed','RECEIPT_COMMAND');
    check(command.options.credentials==='same-origin'&&command.options.headers['X-WP-Nonce']==='nonce'&&!('store_id' in body),'STORE_AUTHORITY_FROM_SESSION');
    return {STORE_RETURN_PANEL:'PASS',ASSERTIONS:count,BROWSER_CERTIFICATION:false};
}
