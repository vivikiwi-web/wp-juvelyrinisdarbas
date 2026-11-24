<?php
defined( 'ABSPATH' ) || exit;

/**
 * Inquiry emails reuse WooCommerce order templates.
 * Rename the order wording only while a message is rendered, and hide
 * quantity, line price, totals and the shipping address.
 */

function jd_inquiry_email_replacements() {
    return array(
        'You’ve received a new order from %s:'       => 'Gavote naują užklausą iš %s:',
        'You’ve received the following order from %s:' => 'Gavote naują užklausą iš %s:',
        'Order #%s'                                  => 'Užklausa #%s',
        '[Order #%s]'                                => 'Užklausa #%s',
        'Order summary'                              => 'Užklausos suvestinė',
    );
}

function jd_inquiry_email_begin() {
    $GLOBALS['jd_rendering_inquiry_email'] = true;
}
add_action( 'woocommerce_email_header', 'jd_inquiry_email_begin', 1 );

function jd_inquiry_email_end() {
    $GLOBALS['jd_rendering_inquiry_email'] = false;
}
add_action( 'woocommerce_email_footer', 'jd_inquiry_email_end', 999 );

function jd_inquiry_email_gettext( $translation, $text, $domain ) {
    if ( 'woocommerce' !== $domain || empty( $GLOBALS['jd_rendering_inquiry_email'] ) ) {
        return $translation;
    }

    $replacements = jd_inquiry_email_replacements();

    if ( isset( $replacements[ $text ] ) ) {
        return $replacements[ $text ];
    }

    return $translation;
}
add_filter( 'gettext', 'jd_inquiry_email_gettext', 10, 3 );

function jd_inquiry_email_hide_shipping_address( $needs_address ) {
    if ( empty( $GLOBALS['jd_rendering_inquiry_email'] ) ) {
        return $needs_address;
    }

    return false;
}
add_filter( 'woocommerce_order_needs_shipping_address', 'jd_inquiry_email_hide_shipping_address', 20 );

function jd_inquiry_email_remove_mobile_promo( $mailer ) {
    $emails = $mailer->get_emails();

    if ( isset( $emails['WC_Email_New_Order'] ) ) {
        remove_action( 'woocommerce_email_footer', array( $emails['WC_Email_New_Order'], 'mobile_messaging' ), 9 );
    }
}
add_action( 'woocommerce_email', 'jd_inquiry_email_remove_mobile_promo' );

function jd_inquiry_email_content( $html ) {
    if ( ! is_string( $html ) || false === strpos( $html, 'email-order-details' ) ) {
        return $html;
    }

    $stripped = preg_replace(
        '/(?:<hr\b[^>]*>\s*)?<table\b(?=[^>]*\bemail-order-details\b)[^>]*>(?!\s*(?:<!--.*?-->\s*)*<tr\b)(?:\s|<!--.*?-->)*<\/table>/s',
        '',
        $html,
        1
    );

    return is_string( $stripped ) ? $stripped : $html;
}
add_filter( 'woocommerce_mail_content', 'jd_inquiry_email_content' );

function jd_inquiry_email_styles( $css ) {
    $css .= '
        .email-order-details thead th:nth-child(2),
        .email-order-details thead th:nth-child(3),
        .email-order-details tr.order_item > td:nth-child(2),
        .email-order-details tr.order_item > td:nth-child(3),
        tr.order-totals,
        table.email-order-details:has(tr.order-totals),
        table.email-order-details:has(tr.order_item) + hr {
            display: none !important;
            mso-hide: all;
            max-height: 0;
            overflow: hidden;
        }
    ';

    return $css;
}
add_filter( 'woocommerce_email_styles', 'jd_inquiry_email_styles' );
