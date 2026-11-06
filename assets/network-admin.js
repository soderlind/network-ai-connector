( () => {
	'use strict';

	document.querySelectorAll( 'details.nac-connector' ).forEach( ( connector ) => {
		const summary = connector.querySelector( 'summary' );
		const form = connector.querySelector( 'form' );
		if ( ! summary || ! form ) {
			console.error( 'Network AI Connector could not initialize a connector control.' );
			return;
		}

		const updateExpanded = () => {
			summary.setAttribute( 'aria-expanded', String( connector.open ) );
			if ( ! connector.open ) {
				form.reset();
			}
		};

		summary.setAttribute( 'role', 'button' );
		connector.addEventListener( 'toggle', updateExpanded );
		updateExpanded();
		connector.classList.add( 'nac-connector--enhanced' );
	} );

	document.querySelectorAll( '.nac-connector__plugin-form' ).forEach( ( form ) => {
		form.addEventListener( 'submit', ( event ) => {
			const button = form.querySelector( 'button[type="submit"]' );
			if ( form.dataset.busy ) {
				event.preventDefault();
				return;
			}
			form.dataset.busy = 'true';
			if ( button ) {
				button.textContent = button.dataset.busyLabel || button.textContent;
				button.setAttribute( 'aria-disabled', 'true' );
				button.classList.add( 'is-busy' );
			}
		} );
	} );
} )();
