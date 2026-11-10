(function (window, document) {
    'use strict';

    function tokenize(text) {
        return String(text || '').toLowerCase().replace(/,/g, ' ').split(/\s+/).filter(Boolean).map(function (t) {
            var m = t.match(/^\+?63(\d{9,10})$/);
            return m ? '0' + m[1] : t;
        });
    }

    function init(root) {
        var target = document.querySelector(root.getAttribute('data-pm-target'));
        if (!target) {
            return;
        }
        var summary = document.querySelector(root.getAttribute('data-pm-summary'));
        var empty = document.querySelector(root.getAttribute('data-pm-empty'));
        var more = document.querySelector(root.getAttribute('data-pm-more'));
        var pageSize = parseInt(root.getAttribute('data-pm-page'), 10) || 0;
        var tabs = [].slice.call(root.querySelectorAll('[data-pm-loc]'));
        var pills = [].slice.call(root.querySelectorAll('[data-pm-age]'));
        var streetBox = root.querySelector('[data-pm-street-box]');
        var street = root.querySelector('[data-pm-street]');
        var search = root.querySelector('[data-pm-search]');
        var state = { loc: 'all', street: '', age: 'all', q: '', limit: pageSize };
        var timer = null;

        function setCount(key, value) {
            [].forEach.call(root.querySelectorAll('[data-pm-count="' + key + '"]'), function (el) {
                el.textContent = value;
            });
        }

        function writeUrl() {
            if (!window.history || !window.history.replaceState) {
                return;
            }
            var params = new URLSearchParams(window.location.search);
            ['tab', 'street', 'infants', 'q'].forEach(function (k) { params.delete(k); });
            if (state.loc !== 'all') { params.set('tab', state.loc); }
            if (state.loc === 'marick' && state.street) { params.set('street', state.street); }
            if (state.age === 'infants') { params.set('infants', '1'); }
            if (state.q) { params.set('q', state.q); }
            var qs = params.toString();
            window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
        }

        function apply() {
            var rows = [].slice.call(target.querySelectorAll('[data-pm-row]'));
            var tokens = tokenize(state.q);
            var byLoc = { all: 0, marick: 0, outsider: 0 };
            var scope = { all: 0, infants: 0 };
            var matches = [];

            rows.forEach(function (row) {
                var loc = row.getAttribute('data-loc');
                var baby = row.getAttribute('data-infant') === '1';
                byLoc.all++;
                if (byLoc[loc] !== undefined) { byLoc[loc]++; }
                var inLoc = state.loc === 'all' || loc === state.loc;
                var inStreet = state.loc !== 'marick' || !state.street || row.getAttribute('data-street') === state.street;
                if (!inLoc || !inStreet) {
                    return;
                }
                scope.all++;
                if (baby) { scope.infants++; }
                if (state.age === 'infants' && !baby) {
                    return;
                }
                var blob = row.getAttribute('data-search') || '';
                if (tokens.every(function (t) { return blob.indexOf(t) > -1; })) {
                    matches.push(row);
                }
            });

            rows.forEach(function (row) { row.hidden = true; });
            var shown = state.limit > 0 ? matches.slice(0, state.limit) : matches;
            shown.forEach(function (row) { row.hidden = false; });

            setCount('loc:all', byLoc.all);
            setCount('loc:marick', byLoc.marick);
            setCount('loc:outsider', byLoc.outsider);
            setCount('age:all', scope.all);
            setCount('age:infants', scope.infants);

            if (empty) { empty.hidden = rows.length === 0 ? true : matches.length > 0; }
            if (summary) {
                summary.textContent = rows.length === 0 ? '' : 'Showing ' + shown.length + ' of ' + matches.length + ' matching patients';
            }
            if (more) {
                var remaining = matches.length - shown.length;
                more.hidden = remaining <= 0;
                more.textContent = 'Show ' + Math.min(remaining, pageSize) + ' more';
            }
        }

        function syncControls() {
            tabs.forEach(function (btn) {
                btn.setAttribute('aria-selected', btn.getAttribute('data-pm-loc') === state.loc ? 'true' : 'false');
            });
            pills.forEach(function (btn) {
                btn.setAttribute('aria-pressed', btn.getAttribute('data-pm-age') === state.age ? 'true' : 'false');
            });
            if (streetBox) { streetBox.hidden = state.loc !== 'marick'; }
            if (street) { street.value = state.street; }
        }

        function update(changes) {
            Object.keys(changes).forEach(function (k) { state[k] = changes[k]; });
            if (state.loc !== 'marick') { state.street = ''; }
            state.limit = pageSize;
            syncControls();
            apply();
            writeUrl();
        }

        tabs.forEach(function (btn) {
            btn.addEventListener('click', function () { update({ loc: btn.getAttribute('data-pm-loc') }); });
        });
        pills.forEach(function (btn) {
            btn.addEventListener('click', function () { update({ age: btn.getAttribute('data-pm-age') }); });
        });
        if (street) {
            street.addEventListener('change', function () { update({ street: street.value }); });
        }
        if (search) {
            search.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () { update({ q: search.value.trim() }); }, 150);
            });
        }
        if (more) {
            more.addEventListener('click', function () {
                state.limit += pageSize;
                apply();
            });
        }
        document.addEventListener('mh:live-applied', apply);

        var params = new URLSearchParams(window.location.search);
        var tab = params.get('tab');
        var initial = {
            loc: tab === 'marick' || tab === 'outsider' ? tab : 'all',
            age: params.get('infants') === '1' ? 'infants' : 'all',
            q: (params.get('q') || '').trim(),
            street: (params.get('street') || '').toUpperCase()
        };
        if (street) {
            var known = [].some.call(street.options, function (o) { return o.value === initial.street; });
            if (!known) { initial.street = ''; }
        }
        if (search) { search.value = initial.q; }
        update(initial);
    }

    document.addEventListener('DOMContentLoaded', function () {
        [].forEach.call(document.querySelectorAll('[data-pm-filters]'), init);
    });
})(window, document);
