'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const { spawnSync } = require( 'node:child_process' );
const { test } = require( 'node:test' );
const { pathToFileURL } = require( 'node:url' );

function findBrowser() {
	if ( process.env.CHROME_BIN ) {
		assert.ok( fs.existsSync( process.env.CHROME_BIN ), 'CHROME_BIN does not point to an existing browser.' );
		return process.env.CHROME_BIN;
	}

	const names = [ 'chromium', 'chromium-browser', 'google-chrome', 'google-chrome-stable', 'msedge' ];
	const candidates = [
		'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
		'/Applications/Chromium.app/Contents/MacOS/Chromium',
		'/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
		...( process.env.PATH || '' ).split( path.delimiter ).flatMap( ( directory ) =>
			names.map( ( name ) => path.join( directory, name ) )
		),
	];
	const browser = candidates.find( ( candidate ) => fs.existsSync( candidate ) );
	assert.ok( browser, 'Install a Chromium-based browser or set CHROME_BIN to its executable.' );
	return browser;
}

for ( const width of [ 375, 1280 ] ) {
	test( `site connector notices fit at viewport width ${ width }`, () => {
		const profile = fs.mkdtempSync( path.join( os.tmpdir(), 'network-ai-connector-browser-' ) );
		try {
			const fixture = pathToFileURL( path.join( __dirname, 'site-notice.html' ) );
			fixture.searchParams.set( 'frameWidth', String( width ) );
			const result = spawnSync(
				findBrowser(),
				[
					'--headless',
					'--allow-file-access-from-files',
					'--disable-gpu',
					'--disable-extensions',
					'--disable-background-networking',
					'--disable-component-update',
					'--no-first-run',
					'--no-default-browser-check',
					`--user-data-dir=${ profile }`,
					'--window-size=1440,900',
					'--virtual-time-budget=2000',
					'--dump-dom',
					fixture.href,
				],
				{ encoding: 'utf8', timeout: 30000 }
			);
			assert.ifError( result.error );
			assert.equal( result.status, 0, result.stderr );
			const output = result.stdout.match( /<output id="result">([^<]*)<\/output>/ );
			assert.ok( output, 'The browser test did not report a result.' );
			assert.equal( output[ 1 ], 'PASS' );
			const viewport = result.stdout.match( /data-viewport-width="(\d+)"/ );
			assert.ok( viewport, 'The browser test did not report its viewport width.' );
			assert.equal( Number( viewport[ 1 ] ), width );
		} finally {
			fs.rmSync( profile, { recursive: true, force: true } );
		}
	} );
}
