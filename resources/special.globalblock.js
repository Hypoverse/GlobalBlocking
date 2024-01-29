/*!
 * JavaScript for Special:GlobalBlock
 * Largely lifted from mediawiki.special.block.js
 */
( function () {
	// Like OO.ui.infuse(), but if the element doesn't exist
	// return null instead of throwing an exception.
	function infuseIfExists( $el ) {
		if ( !$el.length ) {
			return null;
		}
		return OO.ui.infuse( $el );
	}

	/* eslint-disable no-jquery/no-global-selector */
	$( function () {
		var blockTargetWidget, globalAnonOnlyWidget, localAnonOnlyWidget;

		function preserveSelectedStateOnDisable( widget ) {
			var widgetWasSelected;

			if ( !widget ) {
				return;
			}

			// 'disable' event fires if disabled state changes
			widget.on( 'disable', function ( disabled ) {
				if ( disabled ) {
					// Disabling an enabled widget
					// Save selected and set selected to false
					widgetWasSelected = widget.isSelected();
					widget.setSelected( false );
				} else {
					// Enabling a disabled widget
					// Set selected to the saved value
					if ( widgetWasSelected !== undefined ) {
						widget.setSelected( widgetWasSelected );
					}
					widgetWasSelected = undefined;
				}
			} );
		}

		function updateBlockOptions() {
			var blocktarget = blockTargetWidget.getValue().trim(),
				isEmpty = blocktarget === '',
				isIp = mw.util.isIPAddress( blocktarget, true );

			globalAnonOnlyWidget.setDisabled( !isIp && !isEmpty );
			localAnonOnlyWidget.setDisabled( !isIp && !isEmpty );
		}

		// This code is also loaded on the "block succeeded" page where there is no form,
		// so check for block target widget; if it exists, the form is present
		blockTargetWidget = infuseIfExists( $( '#mw-globalblock-address' ) );

		if ( blockTargetWidget ) {
			globalAnonOnlyWidget = OO.ui.infuse( $( '#mw-globalblock-anon-only' ) );
			localAnonOnlyWidget = infuseIfExists( $( '#mw-globalblock-local-soft' ) );
			blockTargetWidget.on( 'change', updateBlockOptions );

			// When disabling checkboxes, preserve their selected state in case they are re-enabled
			preserveSelectedStateOnDisable( globalAnonOnlyWidget );
			preserveSelectedStateOnDisable( localAnonOnlyWidget );

			updateBlockOptions();
		}
	} );
}() );
