( () => {
	'use strict';

	const notice = document.getElementById( 'network-ai-connector-notice' );
	const root = document.getElementById( 'wpbody-content' );
	if ( ! notice || ! root ) {
		console.error( 'Network AI Connector could not mount its managed-connections notice.' );
		return;
	}

	// Core hides legacy admin notices outside the asynchronously rendered route.
	const mount = () => {
		const page = root.querySelector( '.connectors-page' );
		if ( ! page ) {
			return false;
		}
		page.prepend( notice );
		return true;
	};

	if ( ! mount() ) {
		const observer = new MutationObserver( () => {
			if ( mount() ) {
				observer.disconnect();
			}
		} );
		observer.observe( root, { childList: true, subtree: true } );
	}
} )();
