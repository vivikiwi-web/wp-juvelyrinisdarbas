<?php
/**
 * Editable about-page sections for WPBakery.
 *
 * Cards and the inquiry band are stored as shortcodes, so text, icons and
 * the button stay editable in the page builder.
 */

/**
 * Decode a WPBakery param group into a list of rows.
 *
 * @param string $value Stored attribute value.
 * @return array<int, array<string, string>>
 */
function jd_parse_param_group( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return array();
	}

	if ( function_exists( 'vc_param_group_parse_atts' ) ) {
		$parsed = vc_param_group_parse_atts( $value );
		return is_array( $parsed ) ? $parsed : array();
	}

	$parsed = json_decode( rawurldecode( $value ), true );
	return is_array( $parsed ) ? $parsed : array();
}

/**
 * Read the URL and target from a WPBakery link attribute.
 *
 * @param string $value Stored link value.
 * @return array{url: string, target: string}
 */
function jd_parse_vc_link( $value ) {
	$link = array(
		'url'    => '',
		'target' => '',
	);

	if ( ! is_string( $value ) || '' === $value ) {
		return $link;
	}

	if ( function_exists( 'nm_build_link' ) ) {
		$parsed = nm_build_link( $value );
		if ( is_array( $parsed ) ) {
			$link['url']    = isset( $parsed['url'] ) ? (string) $parsed['url'] : '';
			$link['target'] = isset( $parsed['target'] ) ? (string) $parsed['target'] : '';
		}
		return $link;
	}

	if ( 0 === strpos( $value, '/' ) || 0 === strpos( $value, 'http://' ) || 0 === strpos( $value, 'https://' ) ) {
		$link['url'] = $value;
	}

	return $link;
}

/**
 * Register the two page sections in WPBakery.
 */
function jd_register_page_sections() {
	if ( ! function_exists( 'vc_map' ) ) {
		return;
	}

	vc_map(
		array(
			'name'                    => esc_html__( 'Paslaugų kortelės', 'savoy-child' ),
			'base'                    => 'jd_services',
			'category'                => esc_html__( 'Juvelyrinis darbas', 'savoy-child' ),
			'description'             => esc_html__( 'Trys paslaugų stulpeliai su ikona.', 'savoy-child' ),
			'icon'                    => 'icon-wpb-application-icon-large',
			'show_settings_on_create' => true,
			'params'                  => array(
				array(
					'type'        => 'param_group',
					'heading'     => esc_html__( 'Kortelės', 'savoy-child' ),
					'param_name'  => 'items',
					'description' => esc_html__( 'Pridėkite, pašalinkite arba sukeiskite korteles.', 'savoy-child' ),
					'params'      => array(
						array(
							'type'        => 'attach_image',
							'heading'     => esc_html__( 'Ikona', 'savoy-child' ),
							'param_name'  => 'icon',
							'description' => esc_html__( 'Paveikslas iš medijos bibliotekos.', 'savoy-child' ),
						),
						array(
							'type'        => 'textfield',
							'heading'     => esc_html__( 'Antraštė', 'savoy-child' ),
							'param_name'  => 'title',
							'admin_label' => true,
						),
						array(
							'type'        => 'textarea',
							'heading'     => esc_html__( 'Tekstas', 'savoy-child' ),
							'param_name'  => 'text',
						),
					),
				),
			),
		)
	);

	vc_map(
		array(
			'name'                    => esc_html__( 'Užklausos juosta', 'savoy-child' ),
			'base'                    => 'jd_cta',
			'category'                => esc_html__( 'Juvelyrinis darbas', 'savoy-child' ),
			'description'             => esc_html__( 'Centrinė juosta su mygtuku.', 'savoy-child' ),
			'icon'                    => 'icon-wpb-ui-button',
			'show_settings_on_create' => true,
			'params'                  => array(
				array(
					'type'        => 'textfield',
					'heading'     => esc_html__( 'Viršutinė eilutė', 'savoy-child' ),
					'param_name'  => 'eyebrow',
					'admin_label' => true,
				),
				array(
					'type'        => 'textfield',
					'heading'     => esc_html__( 'Antraštė', 'savoy-child' ),
					'param_name'  => 'title',
				),
				array(
					'type'        => 'textarea',
					'heading'     => esc_html__( 'Tekstas', 'savoy-child' ),
					'param_name'  => 'text',
				),
				array(
					'type'        => 'textfield',
					'heading'     => esc_html__( 'Mygtuko tekstas', 'savoy-child' ),
					'param_name'  => 'button_title',
				),
				array(
					'type'        => 'vc_link',
					'heading'     => esc_html__( 'Mygtuko nuoroda', 'savoy-child' ),
					'param_name'  => 'link',
					'description' => esc_html__( 'Numatytoji nuoroda yra /kontaktai/.', 'savoy-child' ),
				),
			),
		)
	);
}
add_action( 'vc_before_init', 'jd_register_page_sections' );

/**
 * Service columns.
 *
 * @param array<string, string> $atts Shortcode attributes.
 * @return string
 */
function jd_shortcode_services( $atts ) {
	$atts = shortcode_atts(
		array(
			'items' => '',
		),
		$atts,
		'jd_services'
	);

	$items = jd_parse_param_group( $atts['items'] );
	if ( empty( $items ) ) {
		return '';
	}

	$cards = '';

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$title   = isset( $item['title'] ) ? (string) $item['title'] : '';
		$text    = isset( $item['text'] ) ? (string) $item['text'] : '';
		$icon_id = isset( $item['icon'] ) ? absint( $item['icon'] ) : 0;

		if ( '' === $title && '' === $text && 0 === $icon_id ) {
			continue;
		}

		$icon_html = '';
		if ( $icon_id > 0 ) {
			$icon_src = wp_get_attachment_image_url( $icon_id, 'full' );
			if ( is_string( $icon_src ) && '' !== $icon_src ) {
				$icon_html = '<img class="jd-services__icon-image" src="' . esc_url( $icon_src ) . '" alt="" loading="lazy" decoding="async" />';
			}
		}

		$cards .= '<article class="jd-services__item">';
		if ( is_string( $icon_html ) && '' !== $icon_html ) {
			$cards .= '<div class="jd-services__icon">' . $icon_html . '</div>';
		}
		if ( '' !== $title ) {
			$cards .= '<h4>' . esc_html( $title ) . '</h4>';
		}
		if ( '' !== $text ) {
			$cards .= '<p>' . esc_html( $text ) . '</p>';
		}
		$cards .= '</article>';
	}

	if ( '' === $cards ) {
		return '';
	}

	return '<section class="jd-services"><div class="jd-services__list">' . $cards . '</div></section>';
}
add_shortcode( 'jd_services', 'jd_shortcode_services' );

/**
 * Inquiry band. The button uses Savoy's filled button markup.
 *
 * @param array<string, string> $atts Shortcode attributes.
 * @return string
 */
function jd_shortcode_cta( $atts ) {
	$atts = shortcode_atts(
		array(
			'eyebrow'      => '',
			'title'        => '',
			'text'         => '',
			'button_title' => '',
			'link'         => '',
		),
		$atts,
		'jd_cta'
	);

	$eyebrow      = (string) $atts['eyebrow'];
	$title        = (string) $atts['title'];
	$text         = (string) $atts['text'];
	$button_title = (string) $atts['button_title'];
	$link         = jd_parse_vc_link( (string) $atts['link'] );

	if ( '' === $eyebrow && '' === $title && '' === $text && '' === $button_title ) {
		return '';
	}

	$html = '<section class="jd-cta"><div class="jd-cta__inner">';

	if ( '' !== $eyebrow ) {
		$html .= '<p class="jd-cta__eyebrow">' . esc_html( $eyebrow ) . '</p>';
	}
	if ( '' !== $title ) {
		$html .= '<h2>' . esc_html( $title ) . '</h2>';
	}
	if ( '' !== $text ) {
		$html .= '<p class="jd-cta__text">' . esc_html( $text ) . '</p>';
	}

	if ( '' !== $button_title ) {
		$url = esc_url( $link['url'] );
		if ( '' === $url ) {
			$url = esc_url( home_url( '/kontaktai/' ) );
		}

		$target_attr = '';
		if ( '_blank' === $link['target'] ) {
			$target_attr = ' target="_blank" rel="noopener noreferrer"';
		}

		$arrow = '<svg class="jd-cta__arrow" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M2.5 8h10M9 4.5 12.5 8 9 11.5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';

		$html .= '<a class="nm_btn nm_btn_lg nm_btn_filled jd-cta__button" href="' . $url . '"' . $target_attr . '>';
		$html .= '<span class="nm_btn_title">' . esc_html( $button_title ) . $arrow . '</span>';
		$html .= '<span class="nm_btn_bg"></span>';
		$html .= '</a>';
	}

	$html .= '</div></section>';

	return $html;
}
add_shortcode( 'jd_cta', 'jd_shortcode_cta' );
