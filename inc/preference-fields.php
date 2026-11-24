<?php
defined( 'ABSPATH' ) || exit;

/** Per-product inquiry schema, shared by the editor, popup and server validator. */
function jd_default_preference_fields( $product ) {
    $fields = array();
    if ( 'yes' === $product->get_meta( '_jd_is_ring' ) ) {
        $fields['size'] = array( 'label' => 'Pageidaujamas vidinis žiedo skersmuo (mm)', 'type' => 'number', 'min' => '10', 'max' => '30', 'step' => '0.1' );
        if ( 'yes' === $product->get_meta( '_jd_is_pair' ) ) {
            $fields['size_2'] = array( 'label' => 'Antro žiedo skersmuo (mm)', 'type' => 'number', 'min' => '10', 'max' => '30', 'step' => '0.1' );
        }
    }
    $fields['material'] = array( 'label' => 'Pageidaujama medžiaga', 'type' => 'select', 'options' => array( 'gold' => 'Auksas', 'silver' => 'Sidabras' ) );
    $fields['gold_color'] = array( 'label' => 'Pageidaujama aukso spalva', 'type' => 'select', 'options' => array( 'yellow' => 'Geltonas', 'white' => 'Baltas', 'red' => 'Raudonas' ) );
    $fields['stone'] = array( 'label' => 'Pageidaujamas akmuo', 'type' => 'select', 'options' => array_combine(
        array( 'Deimantas', 'Safyras', 'Rubinas', 'Smaragdas', 'Ametistas', 'Topazas', 'Cirkonis', 'Kitas akmuo' ),
        array( 'Deimantas', 'Safyras', 'Rubinas', 'Smaragdas', 'Ametistas', 'Topazas', 'Cirkonis', 'Kitas akmuo' ) ) );
    $fields['carat'] = array( 'label' => 'Pageidaujamas akmens svoris (ct)', 'type' => 'number', 'min' => '0.01', 'max' => '100', 'step' => '0.01' );
    $fields['wishes'] = array( 'label' => 'Papildomi pageidavimai', 'type' => 'textarea' );
    foreach ( $fields as &$field ) {
        $field = array_merge( array( 'enabled' => true, 'options' => array(), 'min' => '', 'max' => '', 'step' => 'any', 'condition' => null ), $field );
    }
    unset( $field );
    $fields['gold_color']['condition'] = array( 'field' => 'material', 'operator' => 'not_equals', 'value' => 'silver' );
    return $fields;
}

function jd_preference_fields( $product, $enabled_only = true ) {
    $saved = $product->get_meta( '_jd_preference_fields' );
    $fields = is_array( $saved ) ? $saved : jd_default_preference_fields( $product );
    // Preserve the original relationship for configurations saved before conditions existed.
    if ( isset( $fields['gold_color'], $fields['material'] ) && ! array_key_exists( 'condition', $fields['gold_color'] ) &&
        'select' === $fields['material']['type'] && isset( $fields['material']['options']['silver'] ) ) {
        $fields['gold_color']['condition'] = array( 'field' => 'material', 'operator' => 'not_equals', 'value' => 'silver' );
    }
    foreach ( $fields as &$field ) { $field += array( 'condition' => null ); }
    unset( $field );
    return $enabled_only ? array_filter( $fields, function ( $field ) { return ! empty( $field['enabled'] ); } ) : $fields;
}

/** A dependent field is inactive when its source is missing, disabled or inactive. */
function jd_preference_field_active( $key, $fields, $values, $trail = array() ) {
    if ( ! isset( $fields[ $key ] ) || empty( $fields[ $key ]['enabled'] ) || isset( $trail[ $key ] ) ) { return false; }
    $condition = $fields[ $key ]['condition'] ?? null;
    if ( ! $condition ) { return true; }
    $trail[ $key ] = true;
    if ( ! jd_preference_field_active( $condition['field'], $fields, $values, $trail ) ) { return false; }
    $equal = (string) ( $values[ $condition['field'] ] ?? '' ) === (string) $condition['value'];
    return 'equals' === $condition['operator'] ? $equal : ! $equal;
}

/** Reject malformed editor input as a whole; retain the previous configuration. */
function jd_sanitize_preference_fields( $rows ) {
    if ( ! is_array( $rows ) || count( $rows ) > 20 ) {
        return new WP_Error( 'fields', 'Galima pridėti iki 20 laukų.' );
    }
    $fields = array();
    foreach ( $rows as $row ) {
        if ( ! is_array( $row ) ) { return new WP_Error( 'field', 'Netinkami lauko duomenys.' ); }
        foreach ( array( 'key', 'label', 'type', 'choices', 'min', 'max', 'step', 'condition_field', 'condition_operator', 'condition_value' ) as $part ) {
            if ( isset( $row[ $part ] ) && ! is_scalar( $row[ $part ] ) ) { return new WP_Error( 'field', 'Netinkami lauko duomenys.' ); }
        }
        $key = sanitize_key( $row['key'] ?? '' );
        $label = sanitize_text_field( $row['label'] ?? '' );
        $type = $row['type'] ?? '';
        if ( ! preg_match( '/^[a-z][a-z0-9_]{0,59}$/', $key ) || isset( $fields[ $key ] ) || ! $label || mb_strlen( $label ) > 100 ||
            ! in_array( $type, array( 'text', 'textarea', 'select', 'number' ), true ) ) {
            return new WP_Error( 'field', 'Patikrinkite laukų pavadinimus ir tipus. Pavadinimas negali būti tuščias.' );
        }
        $field = array( 'enabled' => ! empty( $row['enabled'] ), 'label' => $label, 'type' => $type, 'options' => array(), 'min' => '', 'max' => '', 'step' => 'any', 'condition' => null );
        if ( ! empty( $row['condition_field'] ) ) {
            $field['condition'] = array( 'field' => sanitize_key( $row['condition_field'] ),
                'operator' => $row['condition_operator'] ?? 'equals', 'value' => sanitize_text_field( $row['condition_value'] ?? '' ) );
        }
        if ( 'select' === $type ) {
            $lines = preg_split( '/\r\n|\r|\n/', (string) ( $row['choices'] ?? '' ) );
            if ( count( $lines ) > 40 ) { return new WP_Error( 'choices', 'Viename lauke galima nurodyti iki 40 pasirinkimų.' ); }
            foreach ( $lines as $line ) {
                if ( '' === trim( $line ) ) { continue; }
                $parts = array_map( 'trim', explode( '|', $line, 2 ) );
                $value = sanitize_text_field( $parts[0] );
                $text = sanitize_text_field( $parts[1] ?? $parts[0] );
                if ( '' === $value || '' === $text || mb_strlen( $value ) > 120 || mb_strlen( $text ) > 120 || isset( $field['options'][ $value ] ) ) {
                    return new WP_Error( 'choices', 'Pasirinkimai turi būti unikalūs, netušti ir iki 120 ženklų.' );
                }
                $field['options'][ $value ] = $text;
            }
        }
        if ( 'number' === $type ) {
            foreach ( array( 'min', 'max', 'step' ) as $limit ) {
                $value = trim( str_replace( ',', '.', (string) ( $row[ $limit ] ?? '' ) ) );
                if ( '' === $value || ( 'step' === $limit && 'any' === $value ) ) { continue; }
                if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || ( 'step' === $limit && (float) $value <= 0 ) ) {
                    return new WP_Error( 'number', 'Patikrinkite skaičių ribas ir žingsnį.' );
                }
                $field[ $limit ] = $value;
            }
            if ( '' !== $field['min'] && '' !== $field['max'] && (float) $field['min'] > (float) $field['max'] ) {
                return new WP_Error( 'number', 'Mažiausia reikšmė negali viršyti didžiausios.' );
            }
        }
        $fields[ $key ] = $field;
    }
    foreach ( $fields as $key => $field ) {
        $condition = $field['condition'];
        if ( ! $condition ) { continue; }
        $source = $fields[ $condition['field'] ] ?? null;
        if ( $condition['field'] === $key || ! $source || 'select' !== $source['type'] ||
            ! in_array( $condition['operator'], array( 'equals', 'not_equals' ), true ) ||
            ( '' !== $condition['value'] && ! array_key_exists( $condition['value'], $source['options'] ) ) ) {
            return new WP_Error( 'condition', 'Patikrinkite lauko „' . $field['label'] . '“ aktyvavimo sąlygą. Pasirinkite esamą sąrašo lauką ir jo reikšmę arba „Visada aktyvus“.' );
        }
        $seen = array( $key => true );
        $cursor = $condition['field'];
        while ( isset( $fields[ $cursor ] ) ) {
            if ( isset( $seen[ $cursor ] ) ) { return new WP_Error( 'cycle', 'Laukų aktyvavimo sąlygos negali priklausyti viena nuo kitos ratu.' ); }
            $seen[ $cursor ] = true;
            $next = $fields[ $cursor ]['condition'];
            if ( ! $next ) { break; }
            $cursor = $next['field'];
        }
    }
    return $fields;
}

function jd_render_preference_editor_row( $key, $field, $index, $fields = array() ) {
    $prefix = 'jd_fields[' . $index . ']';
    $condition = $field['condition'] ?? null;
    $choices = array();
    foreach ( $field['options'] as $value => $label ) { $choices[] = (string) $value === $label ? $label : $value . ' | ' . $label; }
    ?>
    <div class="jd-field-row">
        <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[key]" value="<?php echo esc_attr( $key ); ?>">
        <div class="jd-field-row-heading"><label><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[enabled]" value="1" <?php checked( $field['enabled'] ); ?>> Rodyti lauką</label><button type="button" class="button-link-delete jd-field-delete">Pašalinti</button></div>
        <label>Pavadinimas<input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $field['label'] ); ?>" maxlength="100" required></label>
        <label>Lauko tipas<select class="jd-field-type" name="<?php echo esc_attr( $prefix ); ?>[type]">
            <?php foreach ( array( 'text' => 'Trumpas tekstas', 'textarea' => 'Ilgas tekstas', 'select' => 'Pasirinkimų sąrašas', 'number' => 'Skaičius' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $field['type'], $value, false ) . '>' . esc_html( $label ) . '</option>'; } ?>
        </select></label>
        <div class="jd-field-choices" <?php if ( 'select' !== $field['type'] ) { echo 'hidden'; } ?>><label>Pasirinkimai<textarea name="<?php echo esc_attr( $prefix ); ?>[choices]" rows="4"><?php echo esc_textarea( implode( "\n", $choices ) ); ?></textarea></label><small>Po vieną pasirinkimą eilutėje. Pirmas punktas visada „Aptarti su juvelyru“. Įrašai su | turi pastovią reikšmę kairėje ir rodomą tekstą dešinėje.</small></div>
        <div class="jd-field-limits" <?php if ( 'number' !== $field['type'] ) { echo 'hidden'; } ?>>
            <?php foreach ( array( 'min' => 'Mažiausia reikšmė', 'max' => 'Didžiausia reikšmė', 'step' => 'Žingsnis' ) as $name => $label ) { echo '<label>' . esc_html( $label ) . '<input type="text" name="' . esc_attr( $prefix ) . '[' . esc_attr( $name ) . ']" value="' . esc_attr( $field[ $name ] ) . '"></label>'; } ?>
        </div>
        <div class="jd-field-condition">
            <label>Aktyvavimo sąlyga<select class="jd-condition-source" name="<?php echo esc_attr( $prefix ); ?>[condition_field]">
                <option value="">Visada aktyvus</option>
                <?php foreach ( $fields as $source_key => $source ) {
                    if ( $key !== $source_key && 'select' === $source['type'] ) { echo '<option value="' . esc_attr( $source_key ) . '" ' . selected( $condition['field'] ?? '', $source_key, false ) . '>' . esc_html( $source['label'] ) . '</option>'; }
                } ?>
            </select></label>
            <div class="jd-condition-details" <?php if ( ! $condition ) { echo 'hidden'; } ?>>
                <label>Palyginimas<select class="jd-condition-operator" name="<?php echo esc_attr( $prefix ); ?>[condition_operator]"><option value="equals" <?php selected( $condition['operator'] ?? '', 'equals' ); ?>>Yra</option><option value="not_equals" <?php selected( $condition['operator'] ?? '', 'not_equals' ); ?>>Nėra</option></select></label>
                <label>Reikšmė<select class="jd-condition-value" name="<?php echo esc_attr( $prefix ); ?>[condition_value]"><option value="">Aptarti su juvelyru</option>
                    <?php foreach ( $fields[ $condition['field'] ?? '' ]['options'] ?? array() as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $condition['value'] ?? '', (string) $value, false ) . '>' . esc_html( $label ) . '</option>'; } ?>
                </select></label>
            </div>
            <small>Laukas bus aktyvus tik tenkinant šią sąlygą. Kitu atveju pasirinkimas išvalomas ir laukas tampa neaktyvus.</small>
        </div>
    </div>
    <?php
}

function jd_render_preference_editor( $product ) {
    echo '<div class="jd-fields-editor"><h4>Pageidavimų laukai</h4><p>Keiskite šio gaminio iššokančio lango laukus. Lauko išjungimas nekeičia jau gautų užklausų. Visi kliento pageidavimai neprivalomi.</p><input type="hidden" name="jd_fields_present" value="1"><div class="jd-fields-rows">';
    $index = 0;
    $fields = jd_preference_fields( $product, false );
    foreach ( $fields as $key => $field ) { jd_render_preference_editor_row( $key, $field, $index++, $fields ); }
    echo '</div><button type="button" class="button jd-field-add">Pridėti lauką</button><template id="jd-field-template">';
    jd_render_preference_editor_row( '__KEY__', array( 'enabled' => true, 'label' => '', 'type' => 'text', 'options' => array(), 'min' => '', 'max' => '', 'step' => 'any' ), '__INDEX__' );
    echo '</template></div>';
}

add_action( 'admin_enqueue_scripts', function () {
    $screen = get_current_screen();
    if ( ! $screen || 'product' !== $screen->post_type || 'post' !== $screen->base ) { return; }
    $path = '/assets/js/preference-editor.js';
    wp_enqueue_script( 'jd-preference-editor', get_stylesheet_directory_uri() . $path, array(), filemtime( get_stylesheet_directory() . $path ), true );
    $path = '/assets/css/main.min.css';
    wp_enqueue_style( 'jd-preference-editor', get_stylesheet_directory_uri() . $path, array(), filemtime( get_stylesheet_directory() . $path ) );
} );

function jd_render_preference_inputs( $product ) {
    foreach ( jd_preference_fields( $product ) as $key => $field ) {
        echo '<label data-jd-field="' . esc_attr( $key ) . '" data-jd-condition="' . esc_attr( wp_json_encode( $field['condition'] ) ) . '"' . ( 'textarea' === $field['type'] ? ' class="jd-preferences-wide"' : '' ) . '>' . esc_html( $field['label'] );
        if ( 'select' === $field['type'] ) {
            echo '<select name="' . esc_attr( $key ) . '"><option value="">Aptarti su juvelyru</option>';
            foreach ( $field['options'] as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>'; }
            echo '</select>';
        } elseif ( 'textarea' === $field['type'] ) {
            echo '<textarea name="' . esc_attr( $key ) . '" maxlength="1500" rows="3"></textarea>';
        } else {
            echo '<input type="' . esc_attr( $field['type'] ) . '" name="' . esc_attr( $key ) . '"';
            if ( 'number' === $field['type'] ) {
                foreach ( array( 'min', 'max', 'step' ) as $limit ) { if ( '' !== $field[ $limit ] ) { echo ' ' . esc_attr( $limit ) . '="' . esc_attr( $field[ $limit ] ) . '"'; } }
            } else { echo ' maxlength="120"'; }
            echo '>';
        }
        echo '</label>';
    }
}
