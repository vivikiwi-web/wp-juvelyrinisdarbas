<?php
/**
 * Optional eyebrow for the existing nm_banner shortcode.
 *
 * The field is registered on the installed element. The rendered banner is
 * left intact except for one escaped line inserted before its title.
 */

/**
 * Register the optional eyebrow on the existing banner element.
 */
function jd_register_banner_eyebrow_param() {
    if ( ! function_exists( 'vc_add_param' ) ) {
        return;
    }

    vc_add_param(
        'nm_banner',
        array(
            'type'        => 'textfield',
            'heading'     => esc_html__( 'Viršutinė antraštė', 'savoy-child' ),
            'param_name'  => 'jd_eyebrow',
            'description' => esc_html__( 'Trumpas tekstas virš banerio pavadinimo. Neprivaloma.', 'savoy-child' ),
            'value'       => '',
            'weight'      => 1,
        )
    );
}
add_action( 'vc_after_init', 'jd_register_banner_eyebrow_param' );

/**
 * Read a plain eyebrow string from shortcode attributes.
 *
 * @param mixed $attr Shortcode attributes from do_shortcode_tag.
 * @return string Empty when the field is missing or blank.
 */
function jd_banner_eyebrow_text( $attr ) {
    if ( ! is_array( $attr ) || ! array_key_exists( 'jd_eyebrow', $attr ) || ! is_scalar( $attr['jd_eyebrow'] ) ) {
        return '';
    }

    return sanitize_text_field( (string) $attr['jd_eyebrow'] );
}

/**
 * Insert one eyebrow paragraph before the banner title.
 *
 * @param string $output  Original nm_banner HTML.
 * @param string $eyebrow Escaped eyebrow markup.
 * @return string Original HTML when the expected title wrapper is absent.
 */
function jd_banner_insert_eyebrow( $output, $eyebrow ) {
    $marker    = '<div class="nm-banner-text-inner';
    $open_pos  = strpos( $output, $marker );
    $second    = ( false === $open_pos ) ? false : strpos( $output, $marker, $open_pos + strlen( $marker ) );

    if ( false === $open_pos || false !== $second ) {
        return $output;
    }

    $open_end = strpos( $output, '>', $open_pos );
    if ( false === $open_end ) {
        return $output;
    }

    $inner_start = $open_end + 1;
    $inner_end   = strpos( $output, '</div>', $inner_start );
    if ( false === $inner_end ) {
        return $output;
    }

    $inner = substr( $output, $inner_start, $inner_end - $inner_start );
    if ( 1 !== preg_match_all( '/class="nm-banner-title"/', $inner, $titles, PREG_OFFSET_CAPTURE ) ) {
        return $output;
    }

    $title_offset = $titles[0][0][1];
    $tag_start    = strrpos( substr( $inner, 0, $title_offset ), '<' );
    if ( false === $tag_start || ! preg_match( '/^<h[1-6]\b/', substr( $inner, $tag_start ) ) ) {
        return $output;
    }

    $insert_at = $inner_start + $tag_start;
    $updated   = substr( $output, 0, $insert_at ) . $eyebrow . substr( $output, $insert_at );

    if ( 1 !== substr_count( $updated, 'class="jd-banner-eyebrow"' ) ) {
        return $output;
    }

    return $updated;
}

/**
 * Add the eyebrow to a rendered nm_banner when the field has text.
 *
 * @param string $output Shortcode HTML.
 * @param string $tag    Shortcode name.
 * @param mixed  $attr   Parsed attributes.
 * @param array  $m      Regular expression match. Unused.
 * @return string
 */
function jd_render_banner_eyebrow( $output, $tag, $attr, $m ) {
    unset( $m );

    if ( 'nm_banner' !== $tag || ! is_string( $output ) ) {
        return $output;
    }

    $text = jd_banner_eyebrow_text( $attr );
    if ( '' === $text || false !== strpos( $output, 'class="jd-banner-eyebrow"' ) ) {
        return $output;
    }

    return jd_banner_insert_eyebrow(
        $output,
        '<p class="jd-banner-eyebrow">' . esc_html( $text ) . '</p>'
    );
}
add_filter( 'do_shortcode_tag', 'jd_render_banner_eyebrow', 10, 4 );
