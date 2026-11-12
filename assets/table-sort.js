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
 *   data-sort-pin    on a row: it stays at its end of the table (a totals row at
 *                    the bottom, a summary row at the top)
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
    var MONTH_WORD = /^(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)$/i;
    var DAY_WORD = /^(?:mon(?:day)?|tue(?:s(?:day)?)?|wed(?:nesday)?|thu(?:r(?:s(?:day)?)?)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)$/i;
    var CONTROLS = 'select, input[type="checkbox"], input[type="radio"], input[type="text"], input[type="number"], input[type="date"], input[type="time"], input[type="email"], input[type="tel"], input[type="url"], textarea';
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

    /** The year a date printed without one most likely means: the one that puts it nearest today (so 8 Nov then 24 Jan read as one summer). */
    function nearestYear(month, day) {
        var now = new Date(), year = now.getFullYear(), best = year, gap = Infinity, y, away;
        for (y = year - 1; y <= year + 1; y++) {
            away = Math.abs(Date.UTC(y, month, day) - now.getTime());
            if (away < gap) { gap = away; best = y; }
        }
        return best;
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
        // [Sun ]24 Jan[ 2027][, 5:00pm]: the day and month names must be real ones, so "2 Junior" is no date.
        m = /^(?:([A-Za-z]{3,9}),? )?(\d{1,2}) ([A-Za-z]{3,9})(?![A-Za-z])(?: (\d{4})(?!\d))?(?:,? (?:at )?(\d{1,2}):(\d{2}) ?([AaPp][Mm])?)?/.exec(text);
        if (m && MONTH_WORD.test(m[3]) && (!m[1] || DAY_WORD.test(m[1]))) {
            var month = MONTHS[m[3].slice(0, 3).toLowerCase()];
            var year = m[4] ? +m[4] : nearestYear(month, +m[2]);
            return Date.UTC(year, month, +m[2]) / 60000 + (m[5] ? minutes(m[5], m[6], m[7]) : 0);
        }
        m = /^(\d{1,2}):(\d{2}) ?([AaPp][Mm])?(?![\d:])/.exec(text);
        if (m) {
            return minutes(m[1], m[2], m[3]);
        }
        return null;
    }

    /** A number or an amount at the start of the text ($1,234.50, -3, 45%), else null. */
    function numberKey(text) {
        // Digits in groups and nothing else (a phone number): all of its digits, so 0412 111 222 and 0412 345 678 differ.
        if (text.indexOf(' ') !== -1 && /^\+?\d[\d ]*\d$/.test(text)) {
            return parseFloat(text.replace(/\D/g, ''));
        }
        var m = /^([-−+])? ?\$? ?(\d[\d,]*(?:\.\d+)?|\.\d+)/.exec(text);
        if (!m) { return null; }
        var n = parseFloat(m[2].replace(/,/g, ''));
        if (isNaN(n)) { return null; }
        return (m[1] === '-' || m[1] === '−') ? -n : n;
    }

    /**
     * A cell's own words: its text without dropdown options, scripts and styles, and without the words
     * on its controls (a button, a label, screen-reader text), which say what a control does and not
     * what the row holds.
     */
    function cellText(node) {
        var out = '', child = node.firstChild, tag;
        while (child) {
            if (child.nodeType === 3) {
                out += child.nodeValue;
            } else if (child.nodeType === 1) {
                tag = child.nodeName;
                if (tag !== 'SELECT' && tag !== 'SCRIPT' && tag !== 'STYLE' && tag !== 'TEXTAREA' && tag !== 'TEMPLATE' && tag !== 'BUTTON' && tag !== 'LABEL'
                    && !(child.classList && child.classList.contains('screen-reader-text'))) {
                    out += ' ' + cellText(child) + ' ';
                }
            }
            child = child.nextSibling;
        }
        return out;
    }

    function compareText(a, b) {
        return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
    }

    /** What a cell sorts by: data-sort, else its words, else (a cell holding only a control) the control as it stands. */
    function cellValue(cell) {
        if (!cell) { return ''; }
        var given = cell.getAttribute('data-sort');
        if (given !== null) { return clean(given); }
        var text = clean(cellText(cell));
        if (text !== '') { return text; }
        var control = cell.querySelector(CONTROLS);
        if (!control) { return ''; }
        if (control.nodeName === 'SELECT') {
            return control.selectedIndex >= 0 ? clean(control.options[control.selectedIndex].text) : '';
        }
        if (control.type === 'checkbox' || control.type === 'radio') {
            return control.checked ? '1' : '0';
        }
        return clean(control.value);
    }

    /** The table's rows as groups (a row and the child rows under it) and its pinned rows (top: before the first group; pins: the rest, kept last); null when it has an odd row or under two groups. */
    function plan(table) {
        var count = table.tHead.rows[0].cells.length;
        var rows = table.tBodies[0].rows;
        var groups = [], pins = [], top = [], group = null, i, j, row;
        for (i = 0; i < rows.length; i++) {
            row = rows[i];
            if (row.hasAttribute('data-sort-pin')) {
                (groups.length ? pins : top).push(row);
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
        return groups.length > 1 ? { groups: groups, pins: pins, top: top } : null;
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
            // What kind of column is it? Dates when its cells start with a date, numbers when they start with a
            // number or an amount. A few cells that hold only words (Never, No file yet, Free) don't change that,
            // and nor does a minority of other cells: those go after the dated or numbered cells. Empty cells go last.
            var values = [], dateKeys = [], numberKeys = [], rank = [], keys = [];
            var filled = 0, dated = 0, numbered = 0, wordsBesideDates = true, wordsBesideNumbers = true;
            for (i = 0; i < groups.length; i++) {
                values[i] = cellValue(groups[i].rows[0].cells[column]);
                if (EMPTY.test(values[i])) {
                    values[i] = '';
                    continue;
                }
                filled++;
                dateKeys[i] = dateKey(values[i]);
                numberKeys[i] = numberKey(values[i]);
                if (dateKeys[i] !== null) { dated++; } else if (/\d/.test(values[i])) { wordsBesideDates = false; }
                if (numberKeys[i] !== null) { numbered++; } else if (/\d/.test(values[i])) { wordsBesideNumbers = false; }
            }
            var kind = 'text';
            if (dated > 0 && (wordsBesideDates || dated * 2 >= filled)) {
                kind = 'date';
            } else if (numbered > 0 && (wordsBesideNumbers || numbered * 2 >= filled)) {
                kind = 'number';
            }
            for (i = 0; i < groups.length; i++) {
                if (values[i] === '') { rank[i] = 2; continue; }
                keys[i] = kind === 'date' ? dateKeys[i] : (kind === 'number' ? numberKeys[i] : null);
                rank[i] = (kind === 'text' || keys[i] !== null) ? 0 : 1;
            }
            var sign = direction === 'descending' ? -1 : 1;
            order = groups.slice().sort(function (a, b) {
                var x = rank[a.at], y = rank[b.at], c;
                // Dated or numbered cells, then cells with only words, then empty cells: the same in both directions.
                if (x !== y) { return x - y; }
                if (x === 2) { return a.at - b.at; }
                c = (x === 0 && kind !== 'text') ? keys[a.at] - keys[b.at] : 0;
                // The same number or date (1 of 3 and 1 of 4): by the whole text. Still the same: the order they were in.
                if (c === 0) { c = compareText(values[a.at], values[b.at]); }
                return c === 0 ? a.at - b.at : sign * c;
            });
        }
        for (i = 0; i < found.top.length; i++) {
            body.appendChild(found.top[i]);
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

    /** Does a column hold anything to sort by: a value in some row, or a control someone can set? (A column of buttons only doesn't.) */
    function hasValues(table, column) {
        var rows = table.tBodies[0].rows, i, cell;
        for (i = 0; i < rows.length; i++) {
            cell = rows[i].cells[column];
            if (!cell || rows[i].hasAttribute('data-sort-child') || rows[i].hasAttribute('data-sort-pin')) { continue; }
            if (!EMPTY.test(cellValue(cell)) || cell.querySelector(CONTROLS)) { return true; }
        }
        return false;
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
            if (!hasValues(table, column)) { return; }
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
