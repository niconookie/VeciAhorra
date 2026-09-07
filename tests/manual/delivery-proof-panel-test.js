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
    check(html.includes('Tomar fotografía')&&html.includes('Seleccionar fotografía del dispositivo'),'explicit_choices');
    check(/name="photo_saved"[^>]*accept="image\/jpeg,image\/png,image\/webp"/.test(html)&&!/name="photo_saved"[^>]*(capture|multiple)/.test(html)&&!html.includes(' multiple'),'saved_picker_no_capture_single');
    check(html.includes('name="courier_confirmation" type="checkbox" required')&&html.includes('Confirmo que esta fotografía corresponde a los productos entregados en este pedido'),'confirmation_rendered');
    var consent={checked:false,required:false},visible={checked:true},label={hidden:true},button={disabled:false},selection={textContent:''},confirmation={checked:false};
    var camera={name:'photo_camera',files:[],value:''},saved={name:'photo_saved',files:[],value:''};
    var form={elements:{photo_camera:camera,photo_saved:saved,courier_confirmation:confirmation,otp:{value:'001234'},recipient_visible:visible,recipient_consent:consent},
        closest:function(selector){if(selector==='[data-return-delivery]')return null;return selector==='[data-id]'?{dataset:{id:'1002',version:'2'}}:form;},
        querySelector:function(selector){return selector==='button'?button:selector==='[data-photo-selection]'?selection:label;},checkValidity:function(){return confirmation.checked&&(!visible.checked||consent.checked)&&/^[0-9]{6}$/.test(this.elements.otp.value);},reportValidity:function(){}};
    camera.closest=saved.closest=function(){return form;};
    function choose(input,file){input.files=file?[file]:[];input.value=file?file.name:'';handlers.change({target:input});}
    var event={target:form,preventDefault:function(){}};
    confirmation.checked=true;handlers.submit(event);await flush();check(fetches.length===0,'no_photo_no_upload');
    var cameraFile={size:200,name:'camera.jpg'},savedFile={size:300,name:'saved.png'};
    choose(camera,cameraFile);check(!confirmation.checked&&selection.textContent.includes('camera.jpg'),'selection_name_and_new_confirmation');
    handlers.submit(event);await flush();check(fetches.length===0,'no_confirmation_no_upload');
    confirmation.checked=true;
    handlers.change({target:{name:'recipient_visible',checked:true,closest:function(){return form;}}});
    check(consent.required&&!label.hidden,'visible_requires_consent');
    handlers.submit(event);await flush();check(fetches.length===0,'no_consent_no_upload');
    consent.checked=true;var before=gets;handlers.submit(event);await flush();
    check(fetches.length===1&&fetches[0].options.body.values.otp==='001234','leading_zero_otp_preserved');
    var request=fetches[0];
    check(request.options.body.values.photo===cameraFile&&request.options.body.values.expected_version==='2','camera_photo_revision_multipart');
    check(request.options.body.values.courier_confirmation==='1','confirmation_payload');
    check(request.options.body.values.recipient_visible==='1'&&request.options.body.values.recipient_consent==='1','consent_payload');
    check(request.options.credentials==='same-origin'&&request.options.headers['X-WP-Nonce']==='test_nonce'&&!('Content-Type' in request.options.headers),'cookie_nonce_boundary');
    check(!request.url.includes('001234')&&gets===before+3,'otp_not_url_and_refresh');
    choose(saved,savedFile);check(camera.value===''&&!confirmation.checked&&selection.textContent.includes('saved.png'),'saved_replaces_camera');
    choose(camera,null);check(form.deliveryPhoto===savedFile,'cancel_preserves_last_selection');
    confirmation.checked=true;handlers.submit(event);await flush();
    check(fetches[1].url===request.url&&fetches[1].options.body.values.photo===savedFile&&Object.keys(fetches[1].options.body.values).filter(function(k){return k.indexOf('photo')>=0;}).join(',')==='photo','SAME_BACKEND_SAVED');
    choose(camera,cameraFile);check(saved.value===''&&!confirmation.checked,'camera_replaces_saved');
    confirmation.checked=true;handlers.submit(event);await flush();
    check(fetches[2].url===request.url&&fetches[2].options.body.values.photo===cameraFile,'SAME_BACKEND_CAMERA');
    form.elements.otp.value='';var otpCount=fetches.length;handlers.submit(event);await flush();check(fetches.length===otpCount,'otp_still_required');
    fail=true;form.elements.otp.value='001234';handlers.submit(event);await flush();
    check(form.elements.otp.value===''&&!button.disabled&&!nodes['[data-va-courier-message]'].textContent.includes('001234'),'safe_error_retry');
    cameraFile.size=8*1024*1024+1;var count=fetches.length;handlers.submit(event);await flush();check(fetches.length===count,'oversize_no_upload');
    return {DELIVERY_PROOF_PANEL:'PASS',ASSERTIONS:assertions,WEB_CERTIFICATION:false};
}
if(typeof module!=='undefined'&&require.main===module){
    testDeliveryProofPanel(require('fs').readFileSync(require('path').join(__dirname,'../../assets/frontend/js/courier-panel.js'),'utf8'))
      .then(async function(r){console.log(JSON.stringify(r));console.log(JSON.stringify(testCustomerDeliveryProof(require('fs').readFileSync(require('path').join(__dirname,'../../assets/frontend/js/customer-panel.js'),'utf8'))));console.log(JSON.stringify(await testDeliveryChoiceMutations(require('fs').readFileSync(require('path').join(__dirname,'../../assets/frontend/js/courier-panel.js'),'utf8'))));}).catch(function(e){console.error(e);process.exitCode=1;});
}

async function testDeliveryChoiceMutations(source) {
    var old="card.dataset.id+'/delivered'",replacement="card.dataset.id+(file.name==='saved.png'?'/upload-saved':'/delivered')";
    if(source.split(old).length!==2)throw new Error('nonunique_path_mutation');
    try{await testDeliveryProofPanel(source.replace(old,replacement));}
    catch(error){if(error.message==='SAME_BACKEND_SAVED')return {UPLOAD_PATH_MUTATION:'DETECTED',RESTORED:'in_memory_only'};throw error;}
    throw new Error('separate_upload_path_not_detected');
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
