(function () {
    'use strict';

    var root = document.querySelector('[data-va-customer-panel-mount]');

    if (!root || root.dataset.vaCustomerPanelInitialized === 'true') {
        return;
    }

    root.dataset.vaCustomerPanelInitialized = 'true';
    root.classList.add('va-customer-panel--initialized');
}());
