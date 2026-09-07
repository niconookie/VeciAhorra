/* Pure DOM/API doubles. This does not certify WordPress HTTP or a browser. */
async function testCourierPanel(source) {
    var assertions = 0, handler, promptValue, confirmation = true, gets = 0, posts = [], fail = false;
    function check(ok, label) { assertions++; if (!ok) throw new Error(label); }
    var nodes = {};
    var root = {
        querySelector: function (selector) { return nodes[selector] || (nodes[selector] = {innerHTML: '', textContent: ''}); },
        addEventListener: function (event, callback) { handler = callback; }
    };
    var document = {
        querySelector: function () { return root; },
        createElement: function () { return {textContent: '', get innerHTML() { return this.textContent; }}; }
    };
    function delivery(id, status) { return {id:id, transition_version:7, status:status, minimarket:{}, delivery:{}}; }
    var window = {
        prompt: function () { return promptValue; }, confirm: function () { return confirmation; },
        VeciAhorra: {api: {
            get: function (path) { gets++; return Promise.resolve({data:path==='/courier/me'?{display_name:'Courier',service_zone_name:'Zone',status:'approved'}:path==='/courier/deliveries/available'?[delivery(1004,'pending')]:[delivery(1001,'assigned'),delivery(1002,'picked_up'),delivery(1003,'delivered')]}); },
            post: function (path,payload) { posts.push({path:path,payload:payload}); return fail?Promise.reject(new Error('conflict')):Promise.resolve({}); }
        }}
    };
    new Function('document','window',source)(document,window);
    async function flush() { for(var i=0;i<20;i++) await Promise.resolve(); }
    await flush();
    var html=nodes['[data-va-courier-owned]'].innerHTML;
    check((html.match(/data-action="abandon"/g)||[]).length===1,'assigned_only_release_button');
    check(html.includes('data-version="7"'),'revision_rendered');
    check(!nodes['[data-va-courier-available]'].innerHTML.includes('abandon'),'no_release_pending');
    var button={dataset:{action:'abandon'},closest:function(){return {dataset:{id:'1001',version:'7'}};}};
    function click() { handler({target:{closest:function(){return button;}}}); }
    promptValue=null;click();check(posts.length===0,'prompt_cancel_no_write');
    promptValue=' ';click();check(posts.length===0,'blank_reason_no_write');
    promptValue='a'.repeat(501);click();check(posts.length===0,'long_reason_no_write');
    promptValue='vehicle';confirmation=false;click();check(posts.length===0,'confirmation_cancel_no_write');
    confirmation=true;var before=gets;click();await flush();
    check(posts[0].path==='/courier/deliveries/1001/abandon' && posts[0].payload.reason==='vehicle' && posts[0].payload.expected_version===7,'release_payload_and_cas');
    check(gets===before+3,'success_refreshes_lists');
    fail=true;before=gets;click();await flush();
    check(gets===before+3 && nodes['[data-va-courier-message]'].textContent==='conflict','failure_refreshes_and_explains');
    return {PANEL_TEST:'PASS',ASSERTIONS:assertions,WEB_CERTIFICATION:false};
}
if (typeof module !== 'undefined' && require.main === module) {
    testCourierPanel(require('fs').readFileSync(require('path').join(__dirname,'../../assets/frontend/js/courier-panel.js'),'utf8'))
        .then(function(result){console.log(JSON.stringify(result));}).catch(function(error){console.error(error);process.exitCode=1;});
}
