/* global HubSpotFormsV4, dataLayer, localStorage */

window.addEventListener( 'hs-form-event:on-ready', ( event ) => {
	window.hsForms = window.hsForms || {};
	const form = HubSpotFormsV4.getFormFromEvent( event );
	const instanceId = form.getInstanceId();
	const config = window.hsForms[ instanceId ];

	if ( ! config ) {
		return;
	}

	const element = document.getElementById( instanceId );
	if ( element?.dataset.hsFormSubmitted === '1' ) {
		return;
	}

	const submitButton = document.querySelector(
		`#${ instanceId } [type="submit"]`
	);
	if ( config.submitButtonClass ) {
		submitButton.classList.add( ...config.submitButtonClass.split( ' ' ) );
	}
	if ( config.submitText ) {
		submitButton.textContent = config.submitText;
	}
} );

window.addEventListener( 'hs-form-event:on-submission:success', ( event ) => {
	window.dataLayer = window.dataLayer || [];
	window.hsForms = window.hsForms || {};

	const form = HubSpotFormsV4.getFormFromEvent( event );
	const instanceId = form.getInstanceId();
	const config = window.hsForms[ instanceId ];

	if ( ! config ) {
		return;
	}

	dataLayer.push( {
		event: config.gtmEventName,
		formId: form.getFormId(),
		instanceId,
		source: 'hubspot_form_wordpress_plugin',
		conversionId: form.getConversionId(),
	} );

	if ( config.redirectUrl ) {
		window.location.href = config.redirectUrl;
		return;
	}

	const template = document.getElementById(
		`${ instanceId }-inline-message`
	);
	if ( template && template.content ) {
		const element = document.getElementById( instanceId );
		element.replaceChildren( template.content.cloneNode( true ) );
		// Strip the HubSpot data attributes so the v4 SDK's async render
		// can't re-render the form into the container after we've swapped
		// in the success message. Without this, the SDK overwrites the
		// success content as soon as its developer/{portalId}.js finishes
		// loading — flaky in tests, broken under slow networks in production.
		element.removeAttribute( 'data-form-id' );
		element.removeAttribute( 'data-portal-id' );
		element.removeAttribute( 'data-region' );
		element.classList.remove( 'hs-form-html' );
		element.dataset.hsFormSubmitted = '1';
	}

	if ( config.persistSuccess && config.storageKey ) {
		try {
			const paths = JSON.parse(
				localStorage.getItem( config.storageKey ) || '[]'
			);
			if ( ! paths.includes( window.location.pathname ) ) {
				paths.push( window.location.pathname );
				localStorage.setItem(
					config.storageKey,
					JSON.stringify( paths )
				);
			}
		} catch ( e ) {}
	}
} );
