<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( function_exists( 'qlqr_fs' ) ) {
    qlqr_fs()->set_basename( true, __FILE__ );
} else {
    /**
     * DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE
     * `function_exists` CALL ABOVE TO PROPERLY WORK.
     */
    if ( !function_exists( 'qlqr_fs' ) ) {
        // Create a helper function for easy SDK access.
        function qlqr_fs() {
            global $qlqr_fs;
            if ( !isset( $qlqr_fs ) ) {
                // Include Freemius SDK.
                require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';
                $qlqr_fs = fs_dynamic_init( array(
                    'id'               => '37063',
                    'slug'             => 'plugnova-link-shortener-qr',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_ee5ca37ccc5eab8b384d378abcc91',
                    'is_premium'       => false,
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'is_org_compliant' => true,
                    'menu'             => array(
                        'slug'    => 'plugnova-link-shortener-qr',
                        'support' => false,
                    ),
                    'is_live'          => true,
                ) );
            }
            return $qlqr_fs;
        }

        // Init Freemius.
        qlqr_fs();
        // Signal that SDK was initiated.
        do_action( 'qlqr_fs_loaded' );
    }
}