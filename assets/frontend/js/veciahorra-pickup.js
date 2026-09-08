(function(window,document){
    'use strict';
    var config=window.VeciAhorra || {}, mapsPromise=null;
    function geocoder(){
        var key=config.pickup && config.pickup.googleBrowserKey;
        if(!key)return Promise.reject(new Error('La búsqueda automática de direcciones no está configurada. Usa tu ubicación o introduce coordenadas.'));
        if(window.google && window.google.maps && window.google.maps.Geocoder)return Promise.resolve(new window.google.maps.Geocoder());
        if(!mapsPromise)mapsPromise=new Promise(function(resolve,reject){
            var script=document.createElement('script');
            window.vaPickupMapsReady=function(){resolve();delete window.vaPickupMapsReady;};
            script.async=true;
            script.src='https://maps.googleapis.com/maps/api/js?key='+encodeURIComponent(key)+'&loading=async&callback=vaPickupMapsReady&v=quarterly';
            script.onerror=function(){mapsPromise=null;reject(new Error('No se pudo cargar Google Maps. Puedes introducir coordenadas.'));};
            document.head.appendChild(script);
        });
        return mapsPromise.then(function(){return new window.google.maps.Geocoder();});
    }
    function mount(root){
        if(root.dataset.ready)return;root.dataset.ready='1';
        var q=function(name){return root.querySelector('[data-pickup-'+name+']');};
        var snapshot=config.cartSnapshot, proposal=null, key=null, selectedResults=[], revision=0;
        var lat=q('latitude'),lon=q('longitude'),confirm=q('confirm'),status=q('status');
        function text(tag,value){var node=document.createElement(tag);node.textContent=value;return node;}
        function money(value){return new Intl.NumberFormat('es-CL',{style:'currency',currency:'CLP',maximumFractionDigits:0}).format(Number(value));}
        function discard(){proposal=null;key=null;q('proposal').replaceChildren();q('proposal').hidden=true;}
        function erasePoint(){lat.value='';lon.value='';q('address').value='';q('point').textContent='';q('address-results').replaceChildren(new Option('Selecciona un resultado',''));selectedResults=[];confirm.checked=false;}
        function refresh(next){
            if(snapshot && next && (snapshot.cart_id!==next.cart_id || snapshot.version!==next.version)){revision++;discard();}
            snapshot=next;
            root.hidden=!(snapshot && snapshot.status==='active' && snapshot.summary && snapshot.summary.pickup_search_eligible===true && config.currentUser && config.currentUser.loggedIn && config.launch && config.launch.commerceEnabled);
            if(root.hidden){discard();erasePoint();q('controls').hidden=true;}
        }
        function changed(){revision++;confirm.checked=false;discard();q('point').textContent='Punto manual: '+lat.value+', '+lon.value+'. Revisa y confirma las coordenadas.';}
        function choose(latitude,longitude,label){lat.value=latitude;lon.value=longitude;changed();q('point').textContent=label+' ('+lat.value+', '+lon.value+'). Confirma el punto antes de buscar.';}
        lat.addEventListener('input',changed);lon.addEventListener('input',changed);
        q('address').addEventListener('input',function(){revision++;confirm.checked=false;discard();selectedResults=[];q('address-results').replaceChildren(new Option('Selecciona un resultado',''));lat.value='';lon.value='';q('point').textContent='Selecciona un resultado de dirección o introduce coordenadas.';});
        q('google-status').textContent=config.pickup && config.pickup.googleBrowserKey ? 'Al buscar una dirección, Google recibe solo el texto de esa búsqueda.' : 'Búsqueda automática de direcciones sin configurar. Puedes usar tu ubicación o introducir coordenadas.';
        q('open').addEventListener('click',function(){q('controls').hidden=false;q('cancel').hidden=false;});
        q('locate').addEventListener('click',function(){
            if(!navigator.geolocation){status.textContent='Ubicación no disponible. Introduce otra dirección o coordenadas.';return;}
            var requestRevision=++revision;
            navigator.geolocation.getCurrentPosition(function(position){
                if(requestRevision!==revision)return;
                choose(position.coords.latitude,position.coords.longitude,'Ubicación del dispositivo');status.textContent='Revisa y confirma el punto.';
            },function(error){
                status.textContent=error.code===1?'Permiso de ubicación denegado. Introduce otra dirección o coordenadas.':error.code===3?'Se agotó el tiempo para obtener la ubicación. Introduce otra dirección o coordenadas.':'No se pudo obtener tu ubicación. Introduce otra dirección o coordenadas.';
            },{enableHighAccuracy:false,timeout:10000,maximumAge:0});
        });
        q('geocode').addEventListener('click',function(){
            var address=q('address').value.trim();
            if(!address || address.length>255 || /[<>]/.test(address)){status.textContent='Introduce una dirección válida de hasta 255 caracteres.';return;}
            var requestRevision=++revision;
            geocoder().then(function(client){return client.geocode({address:address});}).then(function(response){
                if(requestRevision!==revision)return;
                selectedResults=(response.results || []).slice(0,10);
                q('address-results').replaceChildren(new Option('Selecciona un resultado',''));
                selectedResults.forEach(function(result,index){q('address-results').append(new Option(String(result.formatted_address || '').slice(0,255),String(index)));});
                status.textContent=selectedResults.length?'Selecciona un resultado y confirma el punto.':'No se encontró esa dirección. Prueba otra o introduce coordenadas.';
            }).catch(function(error){status.textContent=error.message || 'No se pudo buscar la dirección. Puedes introducir coordenadas.';});
        });
        q('address-results').addEventListener('change',function(){
            var selected=q('address-results').value;if(selected==='')return;
            var result=selectedResults[Number(selected)];if(!result)return;
            choose(result.geometry.location.lat(),result.geometry.location.lng(),String(result.formatted_address || '').slice(0,255));
        });
        q('search').addEventListener('click',function(){
            if(!confirm.checked || lat.value==='' || lon.value==='' || !Number.isFinite(Number(lat.value)) || !Number.isFinite(Number(lon.value)) || Math.abs(Number(lat.value))>90 || Math.abs(Number(lon.value))>180){status.textContent='Introduce coordenadas válidas y confirma el punto.';return;}
            discard();var requestRevision=++revision;
            q('search').disabled=true;status.textContent='Buscando todos los productos en un solo minimarket…';
            config.api.post('/cart/proximity/search',{latitude:Number(lat.value),longitude:Number(lon.value),confirmed:true}).then(function(response){
                if(requestRevision!==revision)return;
                proposal=response.data.proposal;
                if(!proposal){status.textContent='No se encontraron todos los productos en un solo minimarket cercano.';return;}
                key=window.crypto.randomUUID();var box=q('proposal');box.hidden=false;
                box.append(text('h3',proposal.store_name),text('p','Retiro · Distancia aproximada: '+(proposal.distance_metres/1000).toFixed(2)+' km en línea geodésica; no indica ruta ni tiempo de viaje.'));
                proposal.items.forEach(function(item){box.append(text('p',item.name+' · '+item.quantity+' unidades · '+money(item.unit_price)+' cada una · Subtotal actual: '+money(item.previous_subtotal)+' · Nuevo subtotal: '+money(item.subtotal)));});
                box.append(text('p','Subtotal actual: '+money(proposal.current_product_subtotal)+'. Nuevo subtotal: '+money(proposal.summary.product_subtotal)+'. Plataforma: '+money(proposal.summary.platform_fee)+'. Despacho: '+money(proposal.summary.delivery_fee)+'. Nuevo total: '+money(proposal.summary.total)+'.'));
                box.append(text('p','Los precios pueden ser distintos. Revalidaremos el stock al aceptar. Propuesta válida hasta '+new Date(proposal.expires_at).toLocaleTimeString()+'.'));
                var accept=text('button','Cambiar a este minimarket para retiro');accept.type='button';accept.className='va-button va-button--primary';accept.dataset.pickupAccept='';
                accept.addEventListener('click',function(){
                    if(!proposal)return;
                    if(Date.now()>=Date.parse(proposal.expires_at)){discard();status.textContent='La propuesta expiró. Realiza una nueva búsqueda.';return;}
                    accept.disabled=true;var chosen=proposal;
                    config.api.post('/cart/proximity/accept',{proposal_id:chosen.proposal_id,accepted:true},{headers:{'Idempotency-Key':key}}).then(function(){discard();erasePoint();window.dispatchEvent(new CustomEvent('va:pickup-accepted'));}).catch(function(error){
                        status.textContent=error.message || 'No se pudo confirmar el cambio.';
                        if(error.status===409){discard();}else{accept.disabled=false;}
                    });
                });
                box.append(accept);status.textContent='Revisa la propuesta antes de aceptarla.';
            }).catch(function(error){status.textContent=error.message || 'No fue posible buscar.';}).finally(function(){q('search').disabled=false;});
        });
        q('cancel').addEventListener('click',function(){revision++;discard();erasePoint();q('controls').hidden=true;q('cancel').hidden=true;status.textContent='';});
        window.addEventListener('va:cart-snapshot',function(event){refresh(event.detail);});
        refresh(snapshot);
    }
    function ready(){document.querySelectorAll('[data-va-pickup]').forEach(mount);}
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready);else ready();
})(window,document);
