<?php
global $chek_hubspot_form_block_instance_ids;

// Use ?? to suppress "Undefined array key" warnings — WordPress strips
// attributes that match their block.json default value from saved content,
// so optional attributes may not be present at render time.
$portal_id        = ( $attributes['portalId'] ?? null ) ?: get_option( 'hubspot_embed_portal_id' );
$region           = ( $attributes['region']   ?? null ) ?: get_option( 'hubspot_embed_region', 'eu1' );
$form_id          = $attributes['formId'] ?? '';
$business_unit_id = ! empty( $attributes['businessUnitId'] )
	? absint( $attributes['businessUnitId'] )
	: absint( get_option( 'hubspot_embed_business_unit_id' ) );
$form_version     = isset( $attributes['formVersion'] ) && $attributes['formVersion'] === 'v2'
	? 'v2'
	: 'v4';

if ( empty( $portal_id ) || empty( $form_id ) ) {
	return;
}

// Track the instance ID of each form to ensure no collisions.
$chek_hubspot_form_block_instance_ids = $chek_hubspot_form_block_instance_ids ?? [];
$chek_hubspot_form_block_instance_ids[ $form_id ] = isset( $chek_hubspot_form_block_instance_ids[ $form_id ] )
	? $chek_hubspot_form_block_instance_ids[ $form_id ] + 1
	: 1;
$instance_id = $chek_hubspot_form_block_instance_ids[ $form_id ];

// Unique identifier for this form instance.
$target = sprintf( 'hubspot-form-%s-%s', $form_id, $instance_id );

// ---------------------------------------------------------------------------
// v2 branch: render Classic forms via hbspt.forms.create() (iframe).
// ---------------------------------------------------------------------------
if ( $form_version === 'v2' ) {
	wp_enqueue_script(
		'hs-forms-v2',
		'https://js.hsforms.net/forms/embed/v2.js',
		[],
		null,
		[
			'strategy'  => 'async',
			'in_footer' => true,
		]
	);

	$gtm_event_name = empty( $attributes['gtmEventName'] )
		? 'hubspot_form_submit'
		: sanitize_text_field( $attributes['gtmEventName'] );
	$redirect_url   = ! empty( $attributes['redirectUrl'] )
		? sanitize_url( $attributes['redirectUrl'] )
		: '';

	$create_args = [
		'region'   => $region,
		'portalId' => (string) $portal_id,
		'formId'   => $form_id,
		'target'   => '#' . $target,
	];

	$wrapper_attributes = [
		'id'             => $target,
		'class'          => 'hs-form-html hs-form-html--v2',
		'data-region'    => $region,
		'data-form-id'   => $form_id,
		'data-portal-id' => $portal_id,
	];

	?>
	<div <?php echo get_block_wrapper_attributes( $wrapper_attributes ); ?>>
		<div class="wp-block-chek-hubspot-form__loading"></div>
		<noscript>
			<p><?php esc_html_e( 'This form may not be visible due to adblockers, or JavaScript not being enabled.', 'chek-hubspot-form-block' ); ?></p>
		</noscript>
	</div>
	<script type="text/javascript">
		( function () {
			function create() {
				if ( typeof hbspt === 'undefined' || ! hbspt.forms ) {
					return setTimeout( create, 50 );
				}
				hbspt.forms.create( Object.assign( <?php echo wp_json_encode( $create_args ); ?>, {
					onFormSubmitted: function () {
						( window.dataLayer = window.dataLayer || [] ).push( {
							event:  <?php echo wp_json_encode( $gtm_event_name ); ?>,
							formId: <?php echo wp_json_encode( $form_id ); ?>,
							source: 'hubspot_form_wordpress_plugin'
						} );
						<?php if ( ! empty( $redirect_url ) ) : ?>
						window.location.href = <?php echo wp_json_encode( $redirect_url ); ?>;
						<?php endif; ?>
					}
				} ) );
			}
			create();
		} )();
	</script>
	<?php
	return;
}

// ---------------------------------------------------------------------------
// v4 branch (default): render inline via the developer SDK.
// ---------------------------------------------------------------------------

// If no global portal ID is set, the main plugin won't have enqueued the HubSpot
// scripts — enqueue them here using the block-level portal ID as a fallback.
if ( empty( get_option( 'hubspot_embed_portal_id' ) ) ) {
	wp_enqueue_script(
		"hs-forms-{$portal_id}",
		sprintf( 'https://js-%s.hsforms.net/forms/embed/developer/%s.js', $region, $portal_id ),
		[ 'chek-hubspot-form-view-script' ],
		null,
		[ 'strategy' => 'async' ]
	);

	$hs_script_url = sprintf( 'https://js-%s.hs-scripts.com/%s.js', $region, $portal_id );
	if ( ! empty( $business_unit_id ) ) {
		$hs_script_url = add_query_arg( 'businessUnitId', $business_unit_id, $hs_script_url );
	}

	wp_enqueue_script(
		"hs-script-loader-{$portal_id}",
		$hs_script_url,
		[],
		null,
		[
			'strategy'  => 'async',
			'in_footer' => true,
		]
	);
}

// Remove empty value attributes.
$attributes = array_filter( $attributes );

// Generate config object.
$config = [
	'submitButtonClass' => 'wp-element-button hs-button primary large',
];

$optional_config = [
	'redirectUrl' => 'sanitize_url',
	'submitText' => 'sanitize_text_field',
];

foreach ( $optional_config as $key => $callback ) {
	if ( ! empty( $attributes[ $key ] ) ) {
		$config[ $key ] = call_user_func( $callback, $attributes[ $key ] );
	}
}

if ( ! empty( $attributes['persistSuccess'] ) && empty( $config['redirectUrl'] ) ) {
	$config['persistSuccess'] = true;
	$config['storageKey']     = 'hs-form-submitted:' . $form_id;
}

// Add inline message if inner blocks present.
$has_inline_message = (
	! isset( $config['redirectUrl'] ) &&
	! empty( $block->parsed_block['innerBlocks'] ) &&
	trim( $block->parsed_block['innerBlocks'][0]['innerHTML'] ) !== '<p></p>'
);
if ( $has_inline_message ) {
	$inline_message = new WP_HTML_Tag_Processor( $content );
	$inline_message->next_tag( 'div' );
	$inline_message->remove_class( 'wp-block-chek-hubspot-form' );
	$inline_message->add_class( 'wp-block-chek-hubspot-form__inline-message' );
	$inline_message_html = (string) $inline_message;
}

// Google Tag Manager event.
$config['gtmEventName'] = empty( $attributes['gtmEventName'] ) ? 'hubspot_form_submit' : $attributes['gtmEventName'];

$wrapper_attributes = [
	'id' => $target,
	'class' => 'hs-form-html',
	'data-region' => $region,
	'data-form-id' => $form_id,
	'data-portal-id' => $portal_id,
];

?>
<script type="text/javascript">
	window.hsForms = window.hsForms || {};
	window.hsForms['<?php echo esc_js( $target ); ?>'] = <?php echo wp_json_encode( $config ); ?>;
</script>
<?php if ( $has_inline_message ) : ?>
<template id="<?php echo esc_attr( $target ); ?>-inline-message"><?php echo $inline_message_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- server-rendered trusted block content ?></template>
<?php endif; ?>
<div <?php echo get_block_wrapper_attributes( $wrapper_attributes ); ?>>
	<div class="wp-block-chek-hubspot-form__loading"></div>
	<noscript>
		<p><?php esc_html_e( 'This form may not be visible due to adblockers, or JavaScript not being enabled.', 'chek-hubspot-form-block' ); ?></p>
	</noscript>
</div>
<?php if ( $has_inline_message && ! empty( $attributes['persistSuccess'] ) ) : ?>
<script type="text/javascript">
	( function () {
		try {
			var cfg = window.hsForms && window.hsForms[ '<?php echo esc_js( $target ); ?>' ];
			if ( ! cfg || ! cfg.persistSuccess || ! cfg.storageKey ) {
				return;
			}
			var paths = [];
			try { paths = JSON.parse( localStorage.getItem( cfg.storageKey ) || '[]' ); } catch ( e ) {}
			if ( ! Array.isArray( paths ) || ! paths.includes( window.location.pathname ) ) {
				return;
			}
			var tmpl = document.getElementById( '<?php echo esc_js( $target ); ?>-inline-message' );
			var el   = document.getElementById( '<?php echo esc_js( $target ); ?>' );
			if ( ! tmpl || ! el ) {
				return;
			}
			var frag = tmpl.content.cloneNode( true );
			frag.querySelectorAll( '.is-hubspot-form-first-submission' ).forEach( function ( n ) {
				n.remove();
			} );
			el.replaceChildren( frag );
			el.removeAttribute( 'data-form-id' );
			el.removeAttribute( 'data-portal-id' );
			el.removeAttribute( 'data-region' );
			el.classList.remove( 'hs-form-html' );
		} catch ( e ) {}
	} )();
</script>
<?php endif; ?>
