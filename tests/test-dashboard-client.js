'use strict';
// Standalone: node tests/test-dashboard-client.js. Exercises actual bundled helpers.
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');
let source = fs.readFileSync(path.join(__dirname, '../assets/admin.js'), 'utf8');
source = source.replace("if (document.readyState === 'loading') {", "window.testDates={g2j:g2j,j2g:j2g,parseInput:parseInput,displayDay:displayDay,esc:esc}; if (document.readyState === 'loading') {");
function load(jalali) {
 const context = { window: { rasadAdmin: { defaults: { jalali: jalali, to: '2261-12-31' } } }, document: { readyState: 'loading', addEventListener() {} }, Intl, Date, Math, Number, String, Array, Object, setTimeout, clearTimeout };
 vm.runInNewContext(source, context);
 return context.window.testDates;
}
const dates = load(true);
assert.equal(dates.displayDay('2024-03-20'), '1403-01-01');
assert.equal(dates.parseInput('۱۴۰۳/۰۱/۰۱'), '2024-03-20');
assert.equal(dates.parseInput('١٤٠٣/١٢/٣٠'), '2025-03-20');
assert.equal(dates.parseInput('1402/12/30'), '');
assert.equal(dates.parseInput('1403/07/31'), '');
assert.equal(dates.parseInput('1403/00/01'), '');
let count = 0;
for (let stamp = Date.UTC(2000, 0, 1); stamp < Date.UTC(2130, 0, 1); stamp += 86400000) {
 const day = new Date(stamp).toISOString().slice(0, 10);
 assert.equal(dates.parseInput(dates.displayDay(day)), day, day);
 count++;
}
const gregorian = load(false);
assert.equal(gregorian.parseInput('2024/02/29'), '2024-02-29');
assert.equal(gregorian.parseInput('2100/02/29'), '');
assert.equal(gregorian.parseInput('2024/02/30'), '');
assert.equal(gregorian.parseInput('2024/13/01'), '');
console.log('PASS ' + count + ' daily Jalali round trips, century boundaries, localized digits, invalid dates, Gregorian input');
