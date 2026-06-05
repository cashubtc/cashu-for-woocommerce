/* global jQuery */
( function ( $ ) {
    'use strict';

    var $unified   = $( '#cashu_paths\\[unified\\]' );
    var $cashu     = $( '#cashu_paths\\[cashu\\]' );
    var $lightning = $( '#cashu_paths\\[lightning\\]' );
    var $default   = $( '#cashu_default_path' );

    if ( ! $unified.length || ! $cashu.length || ! $lightning.length || ! $default.length ) {
        return;
    }

    var labels = {
        unified:   $unified.next( 'label' ).text() || 'Unified',
        cashu:     $cashu.next( 'label' ).text() || 'Cashu',
        lightning: $lightning.next( 'label' ).text() || 'Lightning',
    };

    function refreshUnified() {
        var legsOn = $cashu.prop( 'checked' ) && $lightning.prop( 'checked' );
        if ( ! legsOn ) {
            $unified.prop( 'checked', false ).prop( 'disabled', true );
            $unified.attr( 'title', 'Requires Cashu + Lightning' );
        } else {
            $unified.prop( 'disabled', false ).removeAttr( 'title' );
        }
    }

    function refreshDefaultOptions() {
        var enabled = [];
        if ( $unified.prop( 'checked' ) )   enabled.push( 'unified' );
        if ( $cashu.prop( 'checked' ) )     enabled.push( 'cashu' );
        if ( $lightning.prop( 'checked' ) ) enabled.push( 'lightning' );

        var current = $default.val();
        $default.empty();
        enabled.forEach( function ( key ) {
            $default.append( $( '<option/>' ).val( key ).text( labels[ key ] || key ) );
        } );
        if ( enabled.indexOf( current ) !== -1 ) {
            $default.val( current );
        } else if ( enabled.length ) {
            $default.val( enabled[0] );
        }
    }

    $unified.add( $cashu ).add( $lightning ).on( 'change', function () {
        refreshUnified();
        refreshDefaultOptions();
    } );

    refreshUnified();
    refreshDefaultOptions();
} )( jQuery );
