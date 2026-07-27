const ERROR_MESSAGES = Object.freeze({
    unauthorized: 'La sesión actual no permite consultar el detalle del pedido.',
    forbidden: 'No tienes permisos suficientes para consultar este detalle.',
    not_found: 'El pedido solicitado no está disponible.',
    invalid_request: 'La solicitud administrativa de detalle no es válida.',
    server_error: 'No fue posible cargar el detalle por un problema interno.',
    network_error: 'No fue posible comunicarse con el servidor.',
    invalid_response: 'El servidor devolvió una respuesta incompatible.',
});

const DIMENSIONS = Object.freeze([
    ['commercial', 'Comercial'],
    ['financial', 'Financiero'],
    ['reservations', 'Reservas'],
    ['processing', 'Procesamiento'],
    ['fulfillment', 'Fulfillment'],
    ['delivery', 'Delivery'],
    ['payment_session', 'Sesión de pago'],
]);
const INSPECTOR_DIMENSIONS = Object.freeze([...DIMENSIONS.map(([key]) => key), 'read']);

export function createOrderDetailView({
    root,
    loadingRegion,
    errorRegion,
    contentRegion,
} = {}) {
    validateElements(root, loadingRegion, errorRegion, contentRegion);
    const doc = root.ownerDocument;

    function render(candidate) {
        const snapshot = normalizeSnapshot(candidate);
        clearRegions();
        root.setAttribute('aria-busy', snapshot.status === 'loading' ? 'true' : 'false');

        if (snapshot.status === 'idle') return;
        if (snapshot.status === 'loading') {
            loadingRegion.hidden = false;
            loadingRegion.append(element('p', '', 'Cargando detalle del pedido…'));
            return;
        }
        if (snapshot.status !== 'ready') {
            errorRegion.hidden = false;
            errorRegion.append(element('p', '', ERROR_MESSAGES[snapshot.status]));
            errorRegion.focus({ preventScroll: true });
            return;
        }

        contentRegion.append(
            summarySection(snapshot.detail),
            operationalSection(snapshot.detail.operational),
            storeSection(snapshot.detail.store),
            customerSection(snapshot.detail.customer),
            linesSection(snapshot.detail.lines, snapshot.detail.totals.currency),
            processingSection(snapshot.detail.processing),
            fulfillmentSection(snapshot.detail.fulfillment),
            timelineSection(snapshot.detail.operational.timeline),
            paymentSection(snapshot.detail.payment),
            inspectorSection(snapshot.detail.inspector)
        );
        contentRegion.hidden = false;
    }

    function clearRegions() {
        loadingRegion.replaceChildren();
        errorRegion.replaceChildren();
        contentRegion.replaceChildren();
        loadingRegion.hidden = true;
        errorRegion.hidden = true;
        contentRegion.hidden = true;
    }

    function summarySection(detail) {
        const section = sectionNode('Resumen del pedido', 'summary');
        section.append(definitions([
            ['Order', integer(detail.identity.id)],
            ['Estado persistido', scalar(detail.identity.persisted_status)],
            ['Estado operacional', scalar(detail.operational.primary_state)],
            ['Total', money(detail.totals.total, detail.totals.currency)],
            ['Líneas', integer(detail.totals.line_count)],
            ['Unidades', integer(detail.totals.unit_count)],
            ['Creado', scalar(detail.identity.created_at)],
            ['Actualizado', scalar(detail.identity.updated_at)],
        ]));
        return section;
    }

    function operationalSection(operational) {
        const section = sectionNode('Estado operacional', 'operational');
        const pairs = [
            ['Estado principal', scalar(operational.primary_state)],
            ['Consistencia', scalar(operational.consistency?.classification)],
            ['Requiere atención', yesNo(operational.requires_attention)],
        ];
        for (const [key, label] of DIMENSIONS) {
            pairs.push([label, scalar(operational.dimensions?.[key])]);
        }
        section.append(definitions(pairs));
        return section;
    }

    function storeSection(store) {
        const section = sectionNode('Minimarket', 'store');
        section.append(definitions([
            ['Store', integer(store?.id)],
            ['Relación vigente', yesNo(store?.exists)],
            ['Nombre comercial', scalar(store?.business_name)],
            ['Estado actual', scalar(store?.current_status)],
        ]));
        return section;
    }

    function customerSection(customer) {
        const section = sectionNode('Comprador', 'customer');
        const relationship = customer?.relationship_status === 'linked'
            ? 'Relación con comprador confirmada'
            : 'Relación con comprador no confirmada o no disponible';
        section.append(definitions([['Relación', relationship]]));
        return section;
    }

    function linesSection(lines, currency) {
        const section = sectionNode('Líneas del pedido', 'lines');
        if (lines.length === 0) {
            section.append(emptyText('No hay líneas registradas.'));
            return section;
        }
        const list = element('ol', 'veciahorra-order-detail__cards');
        for (const line of lines) {
            const item = element('li', 'veciahorra-order-detail__card');
            if (!object(line)) {
                item.append(emptyText('Línea no disponible.'));
            } else {
                item.append(definitions([
                    ['Línea', integer(line.id)],
                    ['Producto', integer(line.product_id)],
                    ['Inventory', integer(line.inventory_id)],
                    ['Nombre histórico', scalar(line.product_name_snapshot)],
                    ['Estado del nombre', scalar(line.snapshot_name_status)],
                    ['Cantidad', integer(line.quantity)],
                    ['Precio unitario', money(line.unit_price, currency)],
                    ['Subtotal', money(line.subtotal, currency)],
                ]));
            }
            list.append(item);
        }
        section.append(list);
        return section;
    }

    function processingSection(processing) {
        const section = sectionNode('Procesamiento', 'processing');
        for (const [key, title] of [
            ['business_completion', 'Negocio'],
            ['delivery_completion', 'Delivery'],
            ['fulfillment_completion', 'Fulfillment'],
        ]) {
            const completion = processing?.[key];
            section.append(
                subheading(title),
                definitions([
                    ['Estado', scalar(completion?.status)],
                    ['Intentos', integer(completion?.attempt_count)],
                    ['Completado', scalar(completion?.completed_at)],
                    ['Actualizado', scalar(completion?.updated_at)],
                ])
            );
        }
        return section;
    }

    function fulfillmentSection(fulfillment) {
        const section = sectionNode('Fulfillment', 'fulfillment');
        section.append(definitions([['Modo', scalar(fulfillment?.mode)]]));
        section.append(subheading('Deliveries'));
        section.append(factList(fulfillment?.deliveries, (delivery) => [
            ['Delivery', integer(delivery.id)],
            ['Estado', scalar(delivery.status)],
            ['Creado', scalar(delivery.created_at)],
            ['Actualizado', scalar(delivery.updated_at)],
        ], 'No hay deliveries registradas.'));
        section.append(subheading('Tracking'));
        section.append(factList(fulfillment?.tracking, (tracking) => [
            ['Evento', scalar(tracking.event)],
            ['Fecha', scalar(tracking.created_at)],
        ], 'No hay tracking registrado.'));
        return section;
    }

    function timelineSection(timeline) {
        const section = sectionNode('Timeline', 'timeline');
        if (timeline.length === 0) {
            section.append(emptyText('No hay hitos operacionales registrados.'));
            return section;
        }
        const list = element('ol', 'veciahorra-order-detail__timeline');
        for (const event of timeline) {
            const item = element('li', 'veciahorra-order-detail__timeline-item');
            if (!object(event)) {
                item.append(emptyText('Evento no disponible.'));
            } else {
                item.append(
                    element('strong', '', scalar(event.label)),
                    element('span', '', scalar(event.occurred_at)),
                    element('span', '', `Origen: ${scalar(event.source)}`)
                );
            }
            list.append(item);
        }
        section.append(list);
        return section;
    }

    function paymentSection(payment) {
        const section = sectionNode('Pago', 'payment');
        section.append(subheading('Sesión'));
        section.append(definitions([
            ['Estado', scalar(payment.session?.status)],
            ['Monto', money(payment.session?.amount, payment.session?.currency)],
            ['Confirmado', scalar(payment.session?.confirmed_at)],
            ['Actualizado', scalar(payment.session?.updated_at)],
        ]));
        section.append(subheading('Evidencia financiera'));
        section.append(definitions([
            ['Estado', scalar(payment.financial_evidence?.status)],
            ['Validada', yesNo(payment.financial_evidence?.validated)],
            ['Monto', money(payment.financial_evidence?.amount, payment.financial_evidence?.currency)],
            ['Obtenida', scalar(payment.financial_evidence?.obtained_at)],
        ]));
        section.append(subheading('Pago durable'));
        section.append(definitions([
            ['Estado', scalar(payment.payment?.status)],
            ['Monto', money(payment.payment?.amount, payment.payment?.currency)],
            ['Pagado', scalar(payment.payment?.paid_at)],
        ]));
        section.append(subheading('Reconciliación'));
        section.append(definitions([
            ['Estado', scalar(payment.reconciliation?.status)],
            ['Intentos', integer(payment.reconciliation?.attempt_count)],
            ['Último intento', scalar(payment.reconciliation?.last_attempt_at)],
            ['Completada', scalar(payment.reconciliation?.completed_at)],
        ]));
        return section;
    }

    function inspectorSection(inspector) {
        const section = sectionNode('Inspector operacional', 'inspector');
        section.append(definitions([
            ['Clasificación', scalar(inspector?.classification)],
            ['Hallazgos', integer(inspector?.finding_count)],
            ['Blockers', integer(inspector?.blocker_count)],
            ['Warnings', integer(inspector?.warning_count)],
        ]));
        const findings = element('div', 'veciahorra-order-detail__findings');
        let count = 0;
        for (const dimension of INSPECTOR_DIMENSIONS) {
            const group = inspector?.by_dimension?.[dimension];
            if (!Array.isArray(group)) continue;
            for (const finding of group) {
                if (!object(finding)) continue;
                const item = element('article', 'veciahorra-order-detail__finding');
                item.append(
                    subheading(scalar(finding.title)),
                    definitions([
                        ['Dimensión', scalar(finding.affected_dimension)],
                        ['Severidad', scalar(finding.severity)],
                        ['Bloqueante', yesNo(finding.blocker)],
                    ])
                );
                findings.append(item);
                count += 1;
            }
        }
        if (count === 0) findings.append(emptyText('No hay hallazgos visibles.'));
        section.append(findings);
        return section;
    }

    function factList(value, pairs, emptyMessage) {
        if (!Array.isArray(value) || value.length === 0) return emptyText(emptyMessage);
        const list = element('ul', 'veciahorra-order-detail__cards');
        for (const fact of value) {
            const item = element('li', 'veciahorra-order-detail__card');
            item.append(object(fact) ? definitions(pairs(fact)) : emptyText('Información no disponible.'));
            list.append(item);
        }
        return list;
    }

    function sectionNode(title, name) {
        const section = element('section', `veciahorra-order-detail__section veciahorra-order-detail__${name}`);
        section.append(element('h2', '', title));
        return section;
    }

    function subheading(title) {
        return element('h3', '', title);
    }

    function definitions(pairs) {
        const list = element('dl', 'veciahorra-order-detail__facts');
        for (const [label, value] of pairs) {
            const group = element('div', 'veciahorra-order-detail__fact');
            group.append(element('dt', '', label), element('dd', '', value));
            list.append(group);
        }
        return list;
    }

    function emptyText(message) {
        return element('p', 'veciahorra-order-detail__empty', message);
    }

    function element(tag, className = '', text = null) {
        const node = doc.createElement(tag);
        if (className !== '') node.className = className;
        if (text !== null) node.textContent = text;
        return node;
    }

    return Object.freeze({ render });
}

function validateElements(root, loadingRegion, errorRegion, contentRegion) {
    for (const element of [root, loadingRegion, errorRegion, contentRegion]) {
        if (element?.nodeType !== 1) throw new TypeError('Valid detail DOM elements are required.');
    }
    if (
        !root.contains(loadingRegion)
        || !root.contains(errorRegion)
        || !root.contains(contentRegion)
        || loadingRegion.getAttribute('role') !== 'status'
        || errorRegion.getAttribute('role') !== 'alert'
        || errorRegion.tabIndex !== -1
    ) {
        throw new TypeError('Detail DOM elements are incompatible with the shell.');
    }
}

function normalizeSnapshot(snapshot) {
    if (!object(snapshot) || !Number.isSafeInteger(snapshot.orderId) || snapshot.orderId <= 0) {
        return invalidSnapshot();
    }
    if (snapshot.status === 'idle' && snapshot.isLoading === false
        && snapshot.detail === null && snapshot.error === null) return snapshot;
    if (snapshot.status === 'loading' && snapshot.isLoading === true
        && snapshot.detail === null && snapshot.error === null) return snapshot;
    if (Object.hasOwn(ERROR_MESSAGES, snapshot.status) && snapshot.isLoading === false
        && snapshot.detail === null && object(snapshot.error)) return snapshot;
    if (snapshot.status === 'ready' && snapshot.isLoading === false
        && snapshot.error === null && validDetail(snapshot.detail, snapshot.orderId)) return snapshot;
    return invalidSnapshot();
}

function invalidSnapshot() {
    return {
        status: 'invalid_response',
        orderId: 0,
        detail: null,
        error: { kind: 'invalid_response', code: null, status: 0 },
        isLoading: false,
    };
}

function validDetail(detail, orderId) {
    return object(detail)
        && object(detail.identity)
        && detail.identity.id === orderId
        && object(detail.customer)
        && ['linked', 'unknown'].includes(detail.customer.relationship_status)
        && object(detail.store)
        && Array.isArray(detail.lines)
        && object(detail.fulfillment)
        && object(detail.payment)
        && object(detail.totals)
        && object(detail.operational)
        && object(detail.operational.dimensions)
        && object(detail.operational.consistency)
        && Array.isArray(detail.operational.timeline)
        && object(detail.inspector);
}

function scalar(value) {
    if (typeof value === 'string' && value !== '') return value;
    if (typeof value === 'number' && Number.isFinite(value)) return String(value);
    return '—';
}

function integer(value) {
    return Number.isSafeInteger(value) && value >= 0 ? String(value) : '—';
}

function yesNo(value) {
    if (value === true) return 'Sí';
    if (value === false) return 'No';
    return '—';
}

function money(amount, currency) {
    const safeAmount = scalar(amount);
    if (safeAmount === '—') return safeAmount;
    const safeCurrency = scalar(currency);
    return safeCurrency === '—' ? safeAmount : `${safeCurrency} ${safeAmount}`;
}

function object(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}
