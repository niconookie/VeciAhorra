(function () {
    'use strict';

    var api = window.VeciAhorra && window.VeciAhorra.api;
    if (!api) return;

    function split(value) {
        return String(value || '').split(',').map(function (item) { return item.trim(); }).filter(Boolean);
    }

    function text(value, fallback) {
        return value === null || value === undefined || value === '' ? (fallback || '') : String(value);
    }

    function el(tag, className, content) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (content !== undefined) node.textContent = content;
        return node;
    }

    function setLink(anchor, href) {
        if (!href) {
            anchor.hidden = true;
            return;
        }
        anchor.href = href;
    }

    function fillCategories(form) {
        var category = form.elements.category_key;
        var subcategory = form.elements.subcategory_key;
        if (!category || !subcategory) return Promise.resolve({});

        return api.get('/service-provider/categories').then(function (response) {
            var catalog = response.data || {};
            category.replaceChildren();
            Object.keys(catalog).forEach(function (key) {
                category.add(new Option(catalog[key].label, key));
            });

            function fillSubcategories(selected) {
                subcategory.replaceChildren();
                var items = catalog[category.value] ? catalog[category.value].subcategories : {};
                Object.keys(items).forEach(function (key) {
                    subcategory.add(new Option(items[key], key));
                });
                if (selected) subcategory.value = selected;
            }

            category.addEventListener('change', function () { fillSubcategories(); });
            fillSubcategories();
            return { catalog: catalog, fillSubcategories: fillSubcategories };
        });
    }

    function formPayload(form) {
        var payload = Object.fromEntries(new FormData(form));
        payload.terms_accepted = form.elements.terms_accepted.checked;
        payload.emergency_service = form.elements.emergency_service.checked;
        payload.coverage = split(form.elements.coverage.value);
        payload.specialties = split(form.elements.specialties.value).slice(0, 5);
        payload.photo_id = Number(payload.photo_id || 0);
        payload.experience_years = Number(payload.experience_years || 0);
        return payload;
    }

    function setupProvider(root) {
        var form = root.querySelector('[data-va-provider-form]');
        if (!form) return;
        var status = root.querySelector('[data-va-provider-status]');
        var observation = root.querySelector('[data-va-provider-observation]');
        var submitButton = form.querySelector('[data-va-provider-submit]');
        var saveButton = form.querySelector('[data-va-wizard-save]');
        var nextButton = form.querySelector('[data-va-wizard-next]');
        var backButton = form.querySelector('[data-va-wizard-back]');
        var message = form.querySelector('[data-va-wizard-message]');
        var currentProfile = root.querySelector('[data-va-provider-current-profile]');
        var steps = Array.from(form.querySelectorAll('[data-va-wizard-step]'));
        var progress = root.querySelectorAll('[data-va-wizard-progress] li');
        var wizard = form.hasAttribute('data-va-provider-wizard');
        var current = 0;
        var categoryTools;
        var enrolled = false;

        function showMessage(value, error) {
            if (!status) return;
            status.textContent = value;
            status.style.color = error ? '#a32626' : '';
        }

        function statusLabel(value) {
            return {draft:'Borrador',in_review:'En revisión',observed:'Con observaciones',approved:'Aprobado',published:'Publicado',inactive:'Inactivo'}[value] || 'Estado no disponible';
        }

        function planLabel(value) {
            return {local:'Plan Local',featured:'Plan Destacado'}[value] || 'sin seleccionar';
        }

        function renderCurrentProfile(provider) {
            if (!currentProfile) return;
            var category = categoryTools && categoryTools.catalog[provider.category_key]
                ? categoryTools.catalog[provider.category_key].label
                : 'Servicio';
            currentProfile.replaceChildren();
            currentProfile.append(el('p', 'va-sp-eyebrow', 'Ficha configurada'), el('h2', '', text(provider.business_name, provider.full_name)));
            var facts = el('div', 'va-sp-current-profile__facts');
            [['Prestador',provider.full_name],['Categoría',category],['Comuna',provider.commune],['Teléfono',provider.phone],['Correo',provider.email]].forEach(function(item){var fact=el('p');fact.append(el('strong','',item[0]),el('span','',text(item[1],'Sin información')));facts.append(fact);});
            currentProfile.append(facts);
            currentProfile.hidden = false;
        }

        function hydrate(provider) {
            Object.keys(provider || {}).forEach(function (key) {
                var field = form.elements[key];
                if (!field) return;
                if (field.type === 'checkbox') field.checked = Boolean(Number(provider[key]));
                else if (field instanceof RadioNodeList) field.value = provider[key] || '';
                else if (Array.isArray(provider[key])) field.value = provider[key].join(', ');
                else field.value = provider[key] === null ? '' : provider[key];
            });
            if (categoryTools && provider.category_key) {
                form.elements.category_key.value = provider.category_key;
                categoryTools.fillSubcategories(provider.subcategory_key);
            }
            enrolled = true;
            var state = text(provider.status, 'draft');
            showMessage('Estado: ' + statusLabel(state) + ' · Plan: ' + planLabel(provider.plan));
            if (observation) observation.textContent = provider.admin_observation ? 'Observación administrativa: ' + provider.admin_observation : '';
            submitButton.hidden = state !== 'draft' && state !== 'observed';
            if (!wizard && state !== 'draft' && state !== 'observed') {
                form.hidden = true;
                renderCurrentProfile(provider);
            }
        }

        function showStep(index) {
            current = Math.max(0, Math.min(index, steps.length - 1));
            steps.forEach(function (step, i) { step.hidden = i !== current; });
            progress.forEach(function (item, i) {
                item.classList.toggle('current', i === current);
                item.classList.toggle('done', i < current);
            });
            backButton.hidden = current === 0;
            nextButton.hidden = current === steps.length - 1;
            saveButton.hidden = current !== steps.length - 1;
            submitButton.hidden = true;
            if (current === steps.length - 1) renderReview();
        }

        function validateStep() {
            var invalid = steps[current].querySelector(':invalid');
            if (invalid) {
                invalid.reportValidity();
                return false;
            }
            return true;
        }

        function renderReview() {
            var review = form.querySelector('[data-va-provider-review]');
            if (!review) return;
            var data = formPayload(form);
            review.replaceChildren();
            [
                ['Plan seleccionado', data.plan === 'featured' ? 'Plan Destacado · $2.000 / mes' : 'Plan Local · $1.000 / mes'],
                ['Titular', text(data.full_name) + ' · ' + text(data.email)],
                ['Servicio', text(data.business_name) + ' · ' + text(data.commune)],
                ['Especialidades', split(data.specialties).join(', ') || 'Sin especialidades declaradas']
            ].forEach(function (row) {
                var article = el('article');
                article.append(el('strong', '', row[0]), el('span', '', row[1]));
                review.append(article);
            });
        }

        fillCategories(form).then(function (tools) {
            categoryTools = tools;
            if (!window.VeciAhorra.currentUser || !window.VeciAhorra.currentUser.loggedIn) {
                showMessage('Inicia sesión para guardar y enviar tu perfil.');
                return null;
            }
            return api.get('/service-provider/me');
        }).then(function (response) {
            if (response) hydrate(response.data);
        }).catch(function (error) {
            if (error.status === 403) {
                return api.post('/service-provider/enroll', {}).then(function () {
                    enrolled = true;
                    showMessage('Perfil iniciado. Completa los cinco pasos.');
                });
            }
            if (error.status === 401) showMessage('Inicia sesión para guardar y enviar tu perfil.', false);
            else showMessage(error.message || 'No fue posible cargar el perfil.', true);
        });

        if (wizard) {
            showStep(0);
            nextButton.addEventListener('click', function () {
                if (validateStep()) showStep(current + 1);
            });
            backButton.addEventListener('click', function () { showStep(current - 1); });
        } else {
            steps.forEach(function (step) { step.hidden = false; });
            nextButton.hidden = true;
            backButton.hidden = true;
            saveButton.hidden = false;
            if (message) message.textContent = 'Los cambios quedan sujetos al estado actual del perfil';
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!form.reportValidity()) return;
            if (!enrolled) {
                showMessage('Inicia sesión para guardar tu perfil.', true);
                return;
            }
            saveButton.disabled = true;
            showMessage('Guardando perfil…');
            api.post('/service-provider/profile', formPayload(form)).then(function (response) {
                hydrate(response.data);
                showMessage('Perfil guardado. Ya puedes enviarlo a revisión.');
                submitButton.hidden = false;
            }).catch(function (error) {
                showMessage(error.message || 'No fue posible guardar el perfil.', true);
            }).finally(function () { saveButton.disabled = false; });
        });

        submitButton.addEventListener('click', function () {
            submitButton.disabled = true;
            showMessage('Enviando a revisión…');
            api.post('/service-provider/submit', {}).then(function (response) {
                hydrate(response.data);
                showMessage('Solicitud enviada a revisión correctamente.');
            }).catch(function (error) {
                showMessage(error.message || 'No fue posible enviar la solicitud.', true);
            }).finally(function () { submitButton.disabled = false; });
        });

        document.querySelectorAll('[data-va-choose-plan]').forEach(function (link) {
            link.addEventListener('click', function () {
                var plan = form.querySelector('[name="plan"][value="' + link.dataset.vaChoosePlan + '"]');
                if (plan) plan.checked = true;
                if (wizard) showStep(0);
            });
        });
    }

    document.querySelectorAll('[data-va-provider-panel]').forEach(setupProvider);

    function setupServices(root) {
        var filter = root.querySelector('[data-va-services-filter]');
        var list = root.querySelector('[data-va-services-list]');
        var detail = root.querySelector('[data-va-service-detail]');
        var status = root.querySelector('[data-va-services-status]');
        var raw = JSON.parse(root.querySelector('[data-va-service-categories]').textContent);
        var category = filter.elements.category_key;
        var subcategory = filter.elements.subcategory_key;

        Object.keys(raw).forEach(function (key) { category.add(new Option(raw[key].label, key)); });
        function fillSubcategories() {
            subcategory.replaceChildren(new Option('Todas las subcategorías', ''));
            var items = category.value && raw[category.value] ? raw[category.value].subcategories : {};
            Object.keys(items).forEach(function (key) { subcategory.add(new Option(items[key], key)); });
        }
        category.addEventListener('change', fillSubcategories);

        function photo(provider) {
            var holder = el('div', 'va-sp-service-photo');
            if (provider.photo_url) {
                var image = document.createElement('img');
                image.src = provider.photo_url;
                image.alt = 'Fotografía de ' + text(provider.name, provider.business_name);
                holder.append(image);
            } else holder.append(el('span', 'va-sp-placeholder', '🔧'));
            if (provider.featured) holder.append(el('span', 'va-sp-badge featured', 'Destacado'));
            return holder;
        }

        function categoryLabel(provider) {
            return raw[provider.category_key] ? raw[provider.category_key].label : text(provider.category_key, 'Servicio');
        }

        function serviceCard(provider) {
            var card = el('article', 'va-sp-service-card');
            card.dataset.provider = provider.id;
            var body = el('div', 'va-sp-service-card-body');
            body.append(el('span', 'va-sp-service-meta', categoryLabel(provider) + ' · ' + text(provider.commune)));
            body.append(el('h2', '', text(provider.business_name, provider.name)));
            body.append(el('p', '', text(provider.description, 'Conoce su experiencia y cobertura de atención.')));
            var badges = el('div', 'va-sp-tags');
            if (provider.verified) badges.append(el('span', '', '✓ Verificado'));
            if (provider.emergency_service) badges.append(el('span', '', 'Atiende urgencias'));
            body.append(badges);
            var button = el('button', 'va-sp-button navy', 'Ver perfil');
            button.type = 'button';
            body.append(button);
            card.append(photo(provider), body);
            return card;
        }

        function renderProfile(provider) {
            detail.replaceChildren();
            var profile = el('article', 'va-sp-public-profile');
            var aside = el('aside', 'va-sp-public-aside');
            aside.append(photo(provider));
            var phone = el('a', 'va-sp-button navy', 'Llamar');
            setLink(phone, provider.phone ? 'tel:' + provider.phone : '');
            var whatsapp = el('a', 'va-sp-button', 'Contactar por WhatsApp');
            var whatsNumber = String(provider.whatsapp || provider.phone || '').replace(/\D/g, '');
            setLink(whatsapp, whatsNumber ? 'https://wa.me/' + whatsNumber : '');
            aside.append(phone, whatsapp);

            var main = el('div', 'va-sp-public-main');
            main.append(el('p', 'va-sp-eyebrow', categoryLabel(provider)));
            main.append(el('h2', '', text(provider.business_name, provider.name)));
            main.append(el('p', 'va-sp-service-meta', text(provider.name) + ' · ' + text(provider.commune)));
            var badges = el('div', 'va-sp-tags');
            if (provider.featured) badges.append(el('span', '', '★ Destacado'));
            if (provider.verified) badges.append(el('span', '', '✓ Verificado'));
            main.append(badges, el('p', '', text(provider.description, 'Perfil de servicio local verificado por VeciAhorra.')));

            var facts = el('div', 'va-sp-public-facts');
            [
                ['Cobertura', Array.isArray(provider.coverage) ? provider.coverage.join(', ') : text(provider.coverage, provider.commune)],
                ['Experiencia', text(provider.experience_years, '0') + ' años'],
                ['Horario', text(provider.schedule, 'A convenir')],
                ['Urgencias', provider.emergency_service ? 'Sí, consultar disponibilidad' : 'No informado']
            ].forEach(function (item) {
                var fact = el('article');
                fact.append(el('small', '', item[0]), el('strong', '', item[1]));
                facts.append(fact);
            });
            main.append(facts, el('h3', '', 'Especialidades'));
            var specialties = el('div', 'va-sp-specialties');
            (provider.specialties || []).forEach(function (item) { specialties.append(el('span', '', item)); });
            if (!specialties.children.length) specialties.append(el('span', '', categoryLabel(provider)));
            main.append(specialties);
            if (provider.contact_email) {
                var email = el('a', 'va-sp-button secondary', 'Enviar correo');
                email.style.marginTop = '24px';
                setLink(email, 'mailto:' + provider.contact_email);
                main.append(email);
            }
            profile.append(aside, main);
            detail.append(profile);
            detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function load() {
            status.textContent = 'Buscando prestadores…';
            var query = new URLSearchParams(new FormData(filter));
            api.get('/services?' + query.toString()).then(function (response) {
                var providers = response.data || [];
                list.replaceChildren();
                providers.forEach(function (provider) { list.append(serviceCard(provider)); });
                status.textContent = providers.length ? providers.length + ' servicio' + (providers.length === 1 ? '' : 's') + ' disponible' + (providers.length === 1 ? '' : 's') : 'No encontramos servicios publicados con esos filtros.';
            }).catch(function (error) {
                status.textContent = error.message || 'No fue posible cargar los servicios.';
            });
        }

        filter.addEventListener('submit', function (event) { event.preventDefault(); load(); });
        list.addEventListener('click', function (event) {
            var card = event.target.closest('[data-provider]');
            if (!card) return;
            api.get('/services/' + encodeURIComponent(card.dataset.provider)).then(function (response) {
                renderProfile(response.data);
            }).catch(function (error) { status.textContent = error.message || 'No fue posible abrir el perfil.'; });
        });
        load();
    }

    document.querySelectorAll('[data-va-services]').forEach(setupServices);
}());
