<?php
/**
 * Plugin Name:       Bulk Order Status Changer
 * Plugin URI:        https://example.com/plugins/the-source/
 * Description:       Allows changing the status of WooCommerce orders in bulk by providing a list of order IDs.
 * Version:           1.0.2
 * Author:            Hussein Soueidan
 * Author URI:        https://example.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bulk-order-status-changer
 * Domain Path:       /languages
 * WC requires at least: 3.0
 * WC tested up to: latest
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Declare HPOS compatibility
add_action(
    'before_woocommerce_init',
    function() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
);

// Define plugin path constant
define( 'BOSC_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Add the admin menu page.
 */
function bosc_add_admin_menu() {
    add_submenu_page(
        'woocommerce', // Parent slug (WooCommerce menu)
        __( 'Bulk Order Status Change', 'bulk-order-status-changer' ), // Page title
        __( 'Bulk Status Change', 'bulk-order-status-changer' ), // Menu title
        'manage_woocommerce', // Capability required
        'bulk_order_status_changer', // Menu slug
        'bosc_admin_page_content' // Function to display the page content
    );
}
add_action( 'admin_menu', 'bosc_add_admin_menu' );

/**
 * Display the admin page content.
 */
function bosc_admin_page_content() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.', 'bulk-order-status-changer' ) );
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Bulk Order Status Changer', 'bulk-order-status-changer' ); ?></h1>
        <p><?php echo esc_html__( 'Enter order IDs below, separated by commas, spaces, or new lines.', 'bulk-order-status-changer' ); ?></p>

        <form method="post" action="">
            <?php wp_nonce_field( 'bosc_process_orders_action', 'bosc_nonce_field' ); ?>

            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="bosc_order_ids"><?php esc_html_e( 'Order IDs:', 'bulk-order-status-changer' ); ?></label>
                    </th>
                    <td>
                        <textarea id="bosc_order_ids" name="bosc_order_ids" rows="10" cols="50" class="large-text" placeholder="Place your order numbers here, separated by commas, spaces, or new lines e.g., 123, 124 125&#10;126"><?php
                            // Retain the order IDs input if the form is reloaded after an action
                            if ( isset( $_POST['bosc_order_ids'] ) ) {
                                echo esc_textarea( stripslashes( $_POST['bosc_order_ids'] ) );
                            }
                        ?></textarea>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="bosc_load_orders" class="button button-primary" value="<?php esc_attr_e( 'Load Orders', 'bulk-order-status-changer' ); ?>" />
            </p>
        </form>

        <?php
        // Process form submissions if a nonce is present and valid
        if ( isset( $_POST['bosc_nonce_field'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bosc_nonce_field'] ) ), 'bosc_process_orders_action' ) ) {
            if ( isset( $_POST['bosc_change_status'] ) ) {
                bosc_handle_change_status();
            } elseif ( isset( $_POST['bosc_load_orders'] ) ) {
                bosc_handle_load_orders();
            }
        }
        ?>
    </div>
    <?php
}

/**
 * Handles parsing order IDs and displaying them.
 */
function bosc_handle_load_orders() {
    if ( ! isset( $_POST['bosc_order_ids'] ) || empty( trim( $_POST['bosc_order_ids'] ) ) ) {
        echo '<div class="error"><p>' . esc_html__( 'Please enter some order IDs.', 'bulk-order-status-changer' ) . '</p></div>';
        return;
    }

    $order_ids_raw = sanitize_textarea_field( stripslashes( $_POST['bosc_order_ids'] ) );
    // Replace newlines and commas with spaces, then split by space. Handles multiple spaces too.
    $order_ids_str = preg_replace( '/[\s,]+/', ' ', $order_ids_raw );
    $order_ids_arr = array_filter( explode( ' ', $order_ids_str ), 'trim' ); // array_filter to remove empty elements

    if ( empty( $order_ids_arr ) ) { // Check if after filtering, the array is empty
        echo '<div class="error"><p>' . esc_html__( 'No valid order ID format found after parsing. Please check your input.', 'bulk-order-status-changer' ) . '</p></div>';
        return;
    }

    $valid_order_ids = [];
    $invalid_entries = [];
    $orders_to_display = [];

    foreach ( $order_ids_arr as $id_str ) {
        if ( is_numeric( $id_str ) && intval( $id_str ) > 0 ) {
            $order_id = intval( $id_str );
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $valid_order_ids[] = $order_id;
                $orders_to_display[] = $order;
            } else {
                $invalid_entries[] = esc_html( $id_str ) . ' (Order not found)';
            }
        } else {
            $invalid_entries[] = esc_html( $id_str ) . ' (Not a valid ID)';
        }
    }

    if ( ! empty( $invalid_entries ) ) {
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'The following entries were invalid or not found:', 'bulk-order-status-changer' ) . ' ' . implode( ', ', $invalid_entries ) . '</p></div>';
    }

    if ( empty( $orders_to_display ) ) {
        echo '<div class="error"><p>' . esc_html__( 'No valid orders found to display.', 'bulk-order-status-changer' ) . '</p></div>';
        return;
    }

    $valid_order_ids_string = implode( ',', $valid_order_ids );

    echo '<h3>' . esc_html__( 'Loaded Orders:', 'bulk-order-status-changer' ) . '</h3>';
    echo '<form method="post" action="">';
    wp_nonce_field( 'bosc_process_orders_action', 'bosc_nonce_field' );
    echo '<input type="hidden" name="bosc_order_ids" value="' . esc_attr( stripslashes( $_POST['bosc_order_ids'] ) ) . '" />';
    echo '<input type="hidden" name="bosc_loaded_order_ids" value="' . esc_attr( $valid_order_ids_string ) . '" />';

    echo '<table class="wp-list-table widefat fixed striped posts">';
    echo '<thead><tr>';
    echo '<th scope="col" id="order_id" class="manage-column column-order_id">' . esc_html__( 'Order ID', 'bulk-order-status-changer' ) . '</th>';
    echo '<th scope="col" id="customer" class="manage-column column-customer">' . esc_html__( 'Customer', 'bulk-order-status-changer' ) . '</th>';
    echo '<th scope="col" id="order_date" class="manage-column column-order_date">' . esc_html__( 'Order Date', 'bulk-order-status-changer' ) . '</th>';
    echo '<th scope="col" id="current_status" class="manage-column column-current_status">' . esc_html__( 'Current Status', 'bulk-order-status-changer' ) . '</th>';
    echo '<th scope="col" id="total" class="manage-column column-total">' . esc_html__( 'Total', 'bulk-order-status-changer' ) . '</th>';
    echo '</tr></thead>';
    echo '<tbody id="the-list">';

    foreach ( $orders_to_display as $order ) {
        echo '<tr>';
        echo '<td class="order_id column-order_id"><a href="' . esc_url( $order->get_edit_order_url() ) . '" target="_blank">#' . esc_html( $order->get_id() ) . '</a></td>';
        echo '<td class="customer column-customer">' . esc_html( $order->get_formatted_billing_full_name() ?: __( 'Guest', 'bulk-order-status-changer' ) ) . '</td>';
        echo '<td class="order_date column-order_date">' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td>';
        echo '<td class="current_status column-current_status">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td>';
        echo '<td class="total column-total">' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    $wc_statuses = wc_get_order_statuses();
    echo '<div style="margin-top: 20px; margin-bottom: 20px;">';
    echo '<label for="bosc_new_status" style="margin-right: 10px;"><strong>' . esc_html__( 'Select New Status:', 'bulk-order-status-changer' ) . '</strong></label>';
    echo '<select id="bosc_new_status" name="bosc_new_status">';
    foreach ( $wc_statuses as $status_key => $status_name ) {
        echo '<option value="' . esc_attr( $status_key ) . '">' . esc_html( $status_name ) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<p class="submit">';
    echo '<input type="submit" name="bosc_change_status" class="button button-primary" value="' . esc_attr__( 'Change Status for Loaded Orders', 'bulk-order-status-changer' ) . '" />';
    echo '</p>';
    echo '</form>';
}

/**
 * Handles changing the status of loaded orders.
 */
function bosc_handle_change_status() {
    // Capability check
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        echo '<div class="error"><p>' . esc_html__( 'You do not have sufficient permissions to perform this action.', 'bulk-order-status-changer' ) . '</p></div>';
        bosc_maybe_reload_orders_form();
        return;
    }

    if ( ! isset( $_POST['bosc_loaded_order_ids'] ) || empty( $_POST['bosc_loaded_order_ids'] ) ) {
        echo '<div class="error"><p>' . esc_html__( 'No order IDs were provided for status change.', 'bulk-order-status-changer' ) . '</p></div>';
        bosc_maybe_reload_orders_form();
        return;
    }

    if ( ! isset( $_POST['bosc_new_status'] ) || empty( $_POST['bosc_new_status'] ) ) {
        echo '<div class="error"><p>' . esc_html__( 'No new status was selected.', 'bulk-order-status-changer' ) . '</p></div>';
        bosc_maybe_reload_orders_form();
        return;
    }

    $loaded_order_ids_str = sanitize_text_field( stripslashes( $_POST['bosc_loaded_order_ids'] ) );
    $order_ids_to_update = explode( ',', $loaded_order_ids_str );
    $new_status = sanitize_key( $_POST['bosc_new_status'] ); // Use sanitize_key for status slugs
    $wc_statuses = wc_get_order_statuses();

    if ( ! array_key_exists( $new_status, $wc_statuses ) ) {
        echo '<div class="error"><p>' . sprintf( esc_html__( 'Invalid new status: %s.', 'bulk-order-status-changer' ), esc_html( $new_status ) ) . '</p></div>';
        bosc_maybe_reload_orders_form();
        return;
    }

    $updated_count = 0;
    $error_count = 0;
    $update_messages = [];

    foreach ( $order_ids_to_update as $order_id_str ) {
        $order_id = intval( trim( $order_id_str ) );
        if ( $order_id > 0 ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                try {
                    $order->update_status( $new_status, __( 'Status updated via Bulk Status Changer plugin.', 'bulk-order-status-changer' ), true );
                    $updated_count++;
                    $update_messages[] = '<li>' . sprintf( esc_html__( 'Order #%s status changed to %s.', 'bulk-order-status-changer' ), $order_id, esc_html( wc_get_order_status_name( $new_status ) ) ) . '</li>';
                } catch ( Exception $e ) {
                    $error_count++;
                    $update_messages[] = '<li>' . sprintf( esc_html__( 'Error updating order #%s: %s', 'bulk-order-status-changer' ), $order_id, esc_html( $e->getMessage() ) ) . '</li>';
                }
            } else {
                $error_count++;
                $update_messages[] = '<li>' . sprintf( esc_html__( 'Order #%s not found during update.', 'bulk-order-status-changer' ), $order_id ) . '</li>';
            }
        }
    }

    if ( $updated_count > 0 ) {
        echo '<div class="updated notice notice-success is-dismissible"><p>' . sprintf( esc_html__( '%d order(s) updated successfully.', 'bulk-order-status-changer' ), $updated_count ) . '</p></div>';
    }
    if ( $error_count > 0 ) {
        echo '<div class="error notice notice-error is-dismissible"><p>' . sprintf( esc_html__( '%d order(s) could not be updated.', 'bulk-order-status-changer' ), $error_count ) . '</p></div>';
    }

    if ( ! empty( $update_messages ) ) {
        echo '<h4>' . esc_html__( 'Update Details:', 'bulk-order-status-changer' ) . '</h4>';
        echo '<ul>' . implode( '', $update_messages ) . '</ul>';
    }
    
    // Re-trigger the display of orders to show their new statuses
    bosc_maybe_reload_orders_form();
}

/**
 * Helper function to re-display the loaded orders form if original IDs are available.
 */
function bosc_maybe_reload_orders_form() {
    if ( isset( $_POST['bosc_order_ids'] ) && ! empty( trim( stripslashes( $_POST['bosc_order_ids'] ) ) ) ) {
        // Re-render the list part of the UI.
        bosc_handle_load_orders();
    }
}