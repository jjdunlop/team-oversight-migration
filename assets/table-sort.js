/*
 * Sortable tables (1.94.0). Every plain list table on a Club Manager admin screen
 * sorts when a column heading is clicked: once A to Z (or low to high, or oldest
 * first), again the other way, a third time back to the order the page drew.
 *
 * A table is taken when it has the class widefat, wp-list-table or murvc-sortable,
 * one heading row and one body, and every body row is a plain row of the same
 * cells as the headings. Markers a page can add:
 *   data-nosort      on a table (or anything around it): leave it alone;
 *                    on a heading: that column gets no button
 *   data-sort="..."  on a cell: the value to sort by, in place of its text
 *   data-sort-child  on a row: it belongs to the row above and moves with it
 *   data-sort-pin    on a row: it stays at the bottom (a totals row)
 * A table with any other odd row (a merged cell, a "nothing here" row) is left alone.
 *
 * It only moves rows and sets attributes; it never writes markup. Values are read
 * when a heading is clicked, so a changed dropdown or tick sorts as it now stands.
 */
(function () {
    'use strict';
    if (window.murvcTableSort) { return; }
    window.murvcTableSort = true;

    var MONTHS = { jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5, jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11 };
    var EMPTY = /^(?:|[-–—]|n\/a)$/i;
    var SKIP_HEADING = /^(?:actions?|select all)$/i;

    function clean(text) {
        return String(text).replace(/\s+/g, ' ').replace(/^ | $/g, '');
    }

    /** Hours and minutes (with am or pm when given) as minutes since midnight. */
    function minutes(hours, mins, half) {
        var h = parseInt(hours, 10);
        if (half) {
            half = half.toLowerCase();
            if (half === 'pm' && h < 12) { h += 12; }
            if (half === 'am' && h === 12) { h = 0; }
        }
        return h * 60 + parseInt(mins, 10);
    }

    /** A date or a time at the start of the text, as a number, else null. The site prints 12 Nov 2026, Sun 24 Jan, 2026-11-12 and 12/11/2026. */
    function dateKey(text) {
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{1,2}):(\d{2}))?/.exec(text);
        if (m) {
            return Date.UTC(+m[1], +m[2] - 1, +m[3]) / 60000 + (m[4] ? minutes(m[4], m[5], '') : 0);
        }
        m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})(?!\d)/.exec(text);
        if (m) {
            return Date.UTC(+m[3], +m[2] - 1, +m[1]) / 60000;
        }
        m = /^(?:[A-Za-z]{3,9},? )?(\d{1,2}) ([A-Za-z]{3,9})(?: (\d{4}))?(?:,? (?:at )?(\d{1,2}):(\d{2}) ?([AaPp][Mm])?)?/.exec(text);
        if (m) {
            var month = MONTHS[m[2].slice(0, 3).toLowerCase()];
            if (month === undefined) { return null; }
            return Date.UTC(m[3] ? +m[3] : 2000, month, +m[1]) / 60000 + (m[4] ? minutes(m[4], m[5], m[6]) : 0);
        }
        m = /^(\d{1,2}):(\d{2}) ?([AaPp][Mm])?(?![\d:])/.exec(text);
        if (m) {
            return minutes(m[1], m[2], m[3]);
        }
        return null;
    }

    /** A number or an amount at the start of the text ($1,234.50, -3, 45%), else null. */
    function numberKey(text) {
        var m = /^([-−+])? ?\$? ?(\d[\d,]*(?:\.\d+)?|\.\d+)/.exec(text);
        if (!m) { return null; }
        var n = parseFloat(m[2].replace(/,/g, ''));
        if (isNaN(n)) { return null; }
        return (m[1] === '-' || m[1] === '−') ? -n : n;
    }

    /** A cell's own words: its text without dropdown options, scripts and styles. */
    function cellText(node) {
        var out = '', child = node.firstChild, tag;
        while (child) {
            if (child.nodeType === 3) {
                out += child.nodeValue;
            } else if (child.nodeType === 1) {
                tag = child.nodeName;
                if (tag !== 'SELECT' && tag !== 'SCRIPT' && tag !== 'STYLE' && tag !== 'TEXTAREA' && tag !== 'TEMPLATE') {
                    out += ' ' + cellText(child) + ' ';
                }
            }
            child = child.nextSibling;
        }
        return out;
    }

    /** What a cell sorts by: data-sort, else its words, else (a cell holding only a control) the control as it stands. */
    function cellValue(cell) {
        if (!cell) { return ''; }
        var given = cell.getAttribute('data-sort');
        if (given !== null) { return clean(given); }
        var text = clean(cellText(cell));
        if (text !== '') { return text; }
        var control = cell.querySelector('select, input[type="checkbox"], input[type="radio"], input[type="text"], input[type="number"], input[type="date"], input[type="email"], textarea');
        if (!control) { return ''; }
        if (control.nodeName === 'SELECT') {
            return control.selectedIndex >= 0 ? clean(control.options[control.selectedIndex].text) : '';
        }
        if (control.type === 'checkbox' || control.type === 'radio') {
            return control.checked ? '1' : '0';
        }
        return clean(control.value);
    }

    /** The table's rows as groups (a row and the child rows under it) and its pinned rows; null when it has an odd row or under two groups. */
    function plan(table) {
        var count = table.tHead.rows[0].cells.length;
        var rows = table.tBodies[0].rows;
        var groups = [], pins = [], group = null, i, j, row;
        for (i = 0; i < rows.length; i++) {
            row = rows[i];
            if (row.hasAttribute('data-sort-pin')) {
                pins.push(row);
                group = null;
                continue;
            }
            if (row.hasAttribute('data-sort-child')) {
                if (!group) { return null; }
                group.rows.push(row);
                continue;
            }
            if (row.cells.length !== count) { return null; }
            for (j = 0; j < count; j++) {
                if (row.cells[j].colSpan > 1 || row.cells[j].rowSpan > 1) { return null; }
            }
            group = { rows: [row], at: groups.length };
            groups.push(group);
        }
        return groups.length > 1 ? { groups: groups, pins: pins } : null;
    }

    /** May this table be sorted at all? */
    function takes(table) {
        if (!/(?:^|\s)(?:widefat|wp-list-table|murvc-sortable)(?:\s|$)/.test(table.className)) { return false; }
        if (/(?:^|\s)form-table(?:\s|$)/.test(table.className)) { return false; }
        if (table.closest('[data-nosort]')) { return false; }
        if (!table.tHead || table.tHead.rows.length !== 1 || table.tBodies.length !== 1) { return false; }
        var cells = table.tHead.rows[0].cells, i;
        for (i = 0; i < cells.length; i++) {
            if (cells[i].colSpan > 1 || cells[i].rowSpan > 1) { return false; }
        }
        return plan(table) !== null;
    }

    /** Sort by one column; direction '' puts the rows back as the page drew them. False when the table can't be sorted as it now stands. */
    function sortBy(table, column, direction) {
        var found = plan(table);
        if (!found) { return false; }
        var body = table.tBodies[0];
        var groups = found.groups, order = [], i;
        if (direction === '') {
            // The order the page drew: each row remembers its place; a row added since goes last.
            order = groups.slice().sort(function (a, b) {
                var x = a.rows[0].murvcSortPlace, y = b.rows[0].murvcSortPlace;
                x = x === undefined ? Infinity : x;
                y = y === undefined ? Infinity : y;
                return x === y ? a.at - b.at : x - y;
            });
        } else {
            var values = [], dates = true, numbers = true, any = false, d, n;
            for (i = 0; i < groups.length; i++) {
                values[i] = cellValue(groups[i].rows[0].cells[column]);
                if (EMPTY.test(values[i])) {
                    values[i] = '';
                    continue;
                }
                any = true;
                if (dates && dateKey(values[i]) === null) { dates = false; }
                if (numbers && numberKey(values[i]) === null) { numbers = false; }
            }
            var keys = [];
            for (i = 0; i < groups.length; i++) {
                if (values[i] === '') { keys[i] = null; continue; }
                if (any && dates) { keys[i] = dateKey(values[i]); }
                else if (any && numbers) { keys[i] = numberKey(values[i]); }
                else { keys[i] = values[i]; }
            }
            var textual = !(any && (dates || numbers));
            var sign = direction === 'descending' ? -1 : 1;
            order = groups.slice().sort(function (a, b) {
                var x = keys[a.at], y = keys[b.at], c;
                // Nothing in the cell: always after the rows that have something.
                if (x === null || y === null) {
                    return (x === null && y === null) ? a.at - b.at : (x === null ? 1 : -1);
                }
                c = textual ? x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' }) : x - y;
                return c === 0 ? a.at - b.at : sign * c;
            });
        }
        for (i = 0; i < order.length; i++) {
            for (var r = 0; r < order[i].rows.length; r++) {
                body.appendChild(order[i].rows[r]);
            }
        }
        for (i = 0; i < found.pins.length; i++) {
            body.appendChild(found.pins[i]);
        }
        return true;
    }

    /** Is the list split over pages (so a sort only orders the rows shown)? */
    function paged(table) {
        if (table.hasAttribute('data-sort-paged')) { return true; }
        var wrap = table.closest('.wrap');
        return !!(wrap && wrap.querySelector('.tablenav-pages a.page-numbers, .tablenav-pages .pagination-links a'));
    }

    function enhance(table) {
        if (table.murvcSortReady || !takes(table)) { return; }
        table.murvcSortReady = true;
        var rows = table.tBodies[0].rows, heads = table.tHead.rows[0].cells, i;
        for (i = 0; i < rows.length; i++) {
            rows[i].murvcSortPlace = i;
        }
        var onPage = paged(table);
        Array.prototype.forEach.call(heads, function (head, column) {
            if (head.nodeName !== 'TH' || head.hasAttribute('data-nosort')) { return; }
            if (head.querySelector('a, button, input, select, textarea')) { return; }
            var label = clean(head.textContent);
            if (label === '' || SKIP_HEADING.test(label)) { return; }
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'murvc-sort';
            button.title = (onPage ? 'Sort the rows on this page by ' : 'Sort by ') + label;
            while (head.firstChild) { button.appendChild(head.firstChild); }
            head.appendChild(button);
            button.addEventListener('click', function () {
                var now = head.getAttribute('aria-sort');
                var next = now === 'ascending' ? 'descending' : (now === 'descending' ? '' : 'ascending');
                if (!sortBy(table, column, next)) { return; }
                Array.prototype.forEach.call(heads, function (other) { other.removeAttribute('aria-sort'); });
                if (next !== '') { head.setAttribute('aria-sort', next); }
            });
        });
    }

    function start() {
        var scope = document.getElementById('wpbody-content') || document.body;
        Array.prototype.forEach.call(scope.querySelectorAll('table'), enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
