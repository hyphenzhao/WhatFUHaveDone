// Offline body assembly checks; the browser test covers the composer controls.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const context = vm.createContext({ document: { addEventListener() {} } });
vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/js/mail.js'), 'utf8') + '\nthis.mail = Mail;', context);
const compose = context.mail.composeText;
assert.equal(compose('Draft', 'Name\nLab', '> Original'), 'Draft\n\n-- \nName\nLab\n\n> Original');
assert.equal(compose('Draft', '', '> Original'), 'Draft\n\n> Original');
assert.equal(compose('Draft', '', ''), 'Draft');
assert.equal(compose('', 'Name', ''), '-- \nName');
assert.equal(compose('Draft', '<b>Name</b>', ''), 'Draft\n\n-- \n<b>Name</b>');
console.log('Mail body assembly checks passed.');
