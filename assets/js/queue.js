(function (window, document) {
    'use strict';

    function show(id) {
        var modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        return modal;
    }

    function hide(modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    function set(id, value) {
        var el = document.getElementById(id);
        if (el) {
            el.value = value;
        }
        return el;
    }

    document.addEventListener('click', function (e) {
        var resched = e.target.closest('[data-queue-resched]');
        if (resched) {
            set('rescheduleAptId', resched.getAttribute('data-apt-id'));
            set('rescheduleDate', resched.getAttribute('data-date'));
            set('rescheduleRemarks', '');
            document.getElementById('reschedulePatient').textContent = 'For: ' + resched.getAttribute('data-name');
            show('rescheduleModal');
            return;
        }
        var reject = e.target.closest('[data-queue-reject]');
        if (reject) {
            set('rejectAptId', reject.getAttribute('data-apt-id'));
            set('rejectRemarks', '');
            document.getElementById('rejectPatient').textContent = 'For: ' + reject.getAttribute('data-name');
            show('rejectModal');
            return;
        }
        var closer = e.target.closest('[data-close-modal]');
        if (closer) {
            hide(document.getElementById(closer.getAttribute('data-close-modal')));
            return;
        }
        if (e.target.classList && e.target.classList.contains('modal-overlay')) {
            hide(e.target);
        }
    });
})(window, document);
