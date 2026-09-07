/* Pure V8/Node DOM and network doubles; not browser certification. */
async function testDeliveryProofPanel(source) {
    var assertions=0,handlers={},nodes={},fetches=[],gets=0,fail=false;
    function check(ok,label){assertions++;if(!ok)throw new Error(label);}
    var root={querySelector:function(s){return nodes[s]||(nodes[s]={innerHTML:'',textContent:''});},addEventListener:function(n,f){handlers[n]=f;}};
    var document={querySelector:function(){return root;},createElement:function(){return {textContent:'',get innerHTML(){return this.textContent;}};}};
    function delivery(id,status){return {id:id,status:status,transition_version:2,minimarket:{},delivery:{}};}
    var window={VeciAhorra:{restUrl:'/wp-json/veciahorra/v1/',nonce:'test_nonce',api:{
        get:function(path){gets++;return Promise.resolve({data:path==='/courier/me'?{display_name:'Courier',service_zone_name:'Zone',status:'approved'}:path==='/courier/deliveries/available'?[]:[delivery(1001,'assigned'),delivery(1002,'picked_up'),delivery(1003,'delivered')]});}
    }},fetch:function(url,options){fetches.push({url:url,options:options});return Promise.resolve({ok:!fail,json:function(){return Promise.resolve(fail?{success:false,error:{code:'otp_invalid'}}:{success:true});}});}};
    function FormData(){this.values={};}FormData.prototype.append=function(k,v){this.values[k]=v;};
    new Function('document','window','FormData',source)(document,window,FormData);
    async function flush(){for(var i=0;i<25;i++)await Promise.resolve();}
    await flush();
    var html=nodes['[data-va-courier-owned]'].innerHTML;
    check((html.match(/data-confirm-delivery/g)||[]).length===1,'picked_up_only_form');
    check(html.includes('accept="image/jpeg,image/png,image/webp"')&&html.includes('capture="environment"'),'image_accept_capture');
    check(html.includes('pattern="[0-9]{6}"')&&html.includes('autocomplete="off"'),'six_digit_otp_field');
    check(!html.includes('data-action="delivered"'),'old_bypass_button_absent');
    var consent={checked:false,required:false},visible={checked:true},label={hidden:true},button={disabled:false};
    var form={elements:{photo:{files:[{size:200}]},otp:{value:'001234'},recipient_visible:visible,recipient_consent:consent},
        closest:function(selector){return selector==='[data-id]'?{dataset:{id:'1002',version:'2'}}:form;},
        querySelector:function(selector){return selector==='button'?button:label;},checkValidity:function(){return !visible.checked||consent.checked;},reportValidity:function(){}};
    handlers.change({target:{name:'recipient_visible',checked:true,closest:function(){return form;}}});
    check(consent.required&&!label.hidden,'visible_requires_consent');
    var event={target:form,preventDefault:function(){}};
    handlers.submit(event);await flush();check(fetches.length===0,'no_consent_no_upload');
    consent.checked=true;var before=gets;handlers.submit(event);await flush();
    check(fetches.length===1&&fetches[0].options.body.values.otp==='001234','leading_zero_otp_preserved');
    var request=fetches[0];
    check(request.options.body.values.photo===form.elements.photo.files[0]&&request.options.body.values.expected_version==='2','photo_revision_multipart');
    check(request.options.body.values.recipient_visible==='1'&&request.options.body.values.recipient_consent==='1','consent_payload');
    check(request.options.credentials==='same-origin'&&request.options.headers['X-WP-Nonce']==='test_nonce'&&!('Content-Type' in request.options.headers),'cookie_nonce_boundary');
    check(!request.url.includes('001234')&&gets===before+3,'otp_not_url_and_refresh');
    fail=true;form.elements.otp.value='001234';handlers.submit(event);await flush();
    check(form.elements.otp.value===''&&!button.disabled&&!nodes['[data-va-courier-message]'].textContent.includes('001234'),'safe_error_retry');
    form.elements.photo.files[0].size=8*1024*1024+1;var count=fetches.length;handlers.submit(event);await flush();check(fetches.length===count,'oversize_no_upload');
    return {DELIVERY_PROOF_PANEL:'PASS',ASSERTIONS:assertions,WEB_CERTIFICATION:false};
}
if(typeof module!=='undefined'&&require.main===module){
    testDeliveryProofPanel(require('fs').readFileSync(require('path').join(__dirname,'../../assets/frontend/js/courier-panel.js'),'utf8'))
      .then(function(r){console.log(JSON.stringify(r));console.log(JSON.stringify(testCustomerDeliveryProof(require('fs').readFileSync(require('path').join(__dirname,'../../assets/frontend/js/customer-panel.js'),'utf8'))));}).catch(function(e){console.error(e);process.exitCode=1;});
}

function testCustomerDeliveryProof(source) {
    var assertions=0;
    function check(ok,label){assertions++;if(!ok)throw new Error(label);}
    var begin=source.indexOf('(detail.delivery_proofs || []).forEach');
    var end=source.indexOf('timelineSection = renderTimeline',begin);
    check(begin>=0&&end>begin,'customer_detail_connected');
    function element(tag,cls,text){return {tag:tag,textContent:text||'',children:[],append:function(){this.children.push.apply(this.children,arguments);}};}
    var window={location:{href:'https://shop.test/compras',origin:'https://shop.test'},setTimeout:function(){}};
    function TestURL(value){this.href=value;this.origin=value.match(/^https?:\/\/[^/]+/)[0];}
    var render=new Function('detail','deliverySection','element','window','URL',source.slice(begin,end));
    function view(proof){var section=element('div');render({delivery_proofs:[proof]},section,element,window,TestURL);return section.children[0];}
    var live=view({delivery_id:1001,otp:'001234',otp_expires_at:new Date(Date.now()+60000).toISOString()});
    check(live.children[1].textContent.includes('001234'),'owner_otp_rendered_in_detail');
    var expired=view({delivery_id:1001,otp:'001234',otp_expires_at:'2000-01-01T00:00:00Z'});
    check(!expired.children[1].textContent.includes('001234'),'expired_otp_hidden');
    var done=view({delivery_id:1001,otp:null,evidence_url:'https://shop.test/wp-admin/admin-post.php?action=veciahorra_delivery_evidence&delivery_id=1001'});
    check(done.children[1].tag==='a'&&done.children[1].rel==='noopener','authorized_evidence_link');
    var foreign=view({delivery_id:1001,otp:null,evidence_url:'https://foreign.test/file.jpg'});
    check(foreign.children.length===1,'foreign_link_rejected');
    return {CUSTOMER_PROOF_PANEL:'PASS',ASSERTIONS:assertions,WEB_CERTIFICATION:false};
}
