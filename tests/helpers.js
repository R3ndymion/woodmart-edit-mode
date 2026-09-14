'use strict';

const fs = require('fs');
const path = require('path');

const PLUGIN_ROOT = path.join(__dirname, '..');

let failed = false;

function check(name, actual, expected) {
	const ok = actual === expected;

	console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}: ${JSON.stringify(actual)}${ok ? '' : ' != ' + JSON.stringify(expected)}`);

	if (!ok) {
		failed = true;
		process.exitCode = 1;
	}
}

function done(suite) {
	console.log(`\n${suite}: ${failed ? 'FAILED' : 'all assertions passed'}`);
}

function asset(name) {
	return fs.readFileSync(path.join(PLUGIN_ROOT, 'assets', name), 'utf8');
}

/**
 * Locate the WordPress install this plugin lives in, so the real admin bar stylesheet can be
 * loaded. Override with WP_ROOT when running outside an install.
 */
function findAdminBarCss() {
	if (process.env.WP_ROOT) {
		return path.join(process.env.WP_ROOT, 'wp-includes/css/admin-bar.css');
	}

	let dir = PLUGIN_ROOT;

	for (let i = 0; i < 8; i++) {
		const candidate = path.join(dir, 'wp-includes/css/admin-bar.css');

		if (fs.existsSync(candidate)) {
			return candidate;
		}

		const parent = path.dirname(dir);

		if (parent === dir) {
			break;
		}

		dir = parent;
	}

	return null;
}

module.exports = { PLUGIN_ROOT, check, done, asset, findAdminBarCss };
