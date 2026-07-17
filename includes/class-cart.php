<?php
defined( 'ABSPATH' ) || exit;

class Filecheck_Cart {

    protected static $_instance = null;

    /**
     * Per-request cache of proof files keyed by job ID, so multiple hooks
     * touching the same cart item don't repeat the transient lookup.
     */
    protected $proof_cache = array();

    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
        add_filter( 'woocommerce_get_item_data', array( $this, 'get_item_data' ), 10, 2 );
        add_action( 'woocommerce_after_cart_item_name', array( $this, 'render_cart_item_proofs' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_line_item_meta' ), 10, 4 );
        add_action( 'woocommerce_order_item_meta_end', array( $this, 'order_item_meta_end' ), 10, 4 );
        add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_internal_item_meta' ) );
        add_action( 'wp_ajax_filecheck_save_job',        array( $this, 'save_job_id_to_session' ) );
        add_action( 'wp_ajax_nopriv_filecheck_save_job', array( $this, 'save_job_id_to_session' ) );
    }

    public function save_job_id_to_session() {
        check_ajax_referer( 'filecheck_save_job', 'nonce' );

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $job_id     = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );

        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => 'Invalid product ID.' ) );
        }

        if ( ! WC()->session ) {
            wp_send_json_error( array( 'message' => 'Session unavailable.' ) );
        }

        $session_key = 'fc_job_' . $product_id;

        if ( $job_id ) {
            WC()->session->set( $session_key, $job_id );
        } else {
            WC()->session->__unset( $session_key );
        }

        wp_send_json_success();
    }

    public function hide_internal_item_meta( $keys ) {
        $keys[] = '_filecheck_job_id';
        $keys[] = '_filecheck_downloaded_files';
        return $keys;
    }

    public function order_item_meta_end( $item_id, $item, $order, $plain_text = false ) {
        if ( ! is_a( $item, 'WC_Order_Item' ) || empty( $item->get_meta( '_filecheck_job_id' ) ) ) {
            return;
        }
        if ( $plain_text ) {
            echo "\n" . esc_html__( 'Uploaded Files: Provided', 'filecheck' ) . "\n";
        } else {
            echo '<p class="filecheck-uploaded" style="margin:6px 0 0;font-size:0.9em;">' . esc_html__( 'Uploaded Files: Provided', 'filecheck' ) . '</p>';
        }
    }

    public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        $job_id = '';
        // Nonce verification is handled by WooCommerce's own add-to-cart flow; this filter
        // only reads an already-sanitized job ID submitted alongside the product form.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( ! empty( $_POST['filecheck_job_id'] ) ) {
            $job_id = sanitize_text_field( wp_unslash( $_POST['filecheck_job_id'] ) );
        } elseif ( WC()->session ) {
            $job_id = WC()->session->get( 'fc_job_' . $product_id, '' );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( $job_id ) {
            $cart_item_data['filecheck_job_id'] = $job_id;
        }
        return $cart_item_data;
    }

    public function get_item_data( $item_data, $cart_item ) {
        if ( isset( $cart_item['filecheck_job_id'] ) && ! empty( $cart_item['filecheck_job_id'] ) ) {
            $item_data[] = array(
                'name'    => __( 'Files Uploaded', 'filecheck' ),
                'display' => __( 'Yes', 'filecheck' ),
            );
        }
        return $item_data;
    }

    /**
     * Renders soft-proof thumbnails under the item name on the cart page.
     * Files without proofs (proofing disabled on the workflow) are skipped;
     * if no file has proofs, nothing is rendered at all.
     */
    public function render_cart_item_proofs( $cart_item, $cart_item_key ) {
        if ( empty( $cart_item['filecheck_job_id'] ) ) {
            return;
        }

        $files = $this->get_proof_files( $cart_item['filecheck_job_id'] );
        if ( empty( $files ) ) {
            return;
        }

        $max_files = 4;
        $shown     = array_slice( $files, 0, $max_files );
        $more      = count( $files ) - count( $shown );

        echo '<div class="fc-cart-proofs">';
        foreach ( $shown as $file ) {
            $page_count = count( $file['proofs'] );
            echo '<span class="fc-cart-proof" title="' . esc_attr( $file['name'] ) . '">';
            // Proof URLs expire ~7 days after render; onerror hides the tile
            // instead of showing a broken image in long-lived carts.
            echo '<img src="' . esc_url( $file['proofs'][0]['url'] ) . '" alt="' . esc_attr( $file['name'] ) . '" loading="lazy" onerror="this.parentNode.style.display=\'none\'">';
            if ( $page_count > 1 ) {
                /* translators: %d: number of pages in the uploaded file. */
                echo '<span class="fc-cart-proof-pages">' . esc_html( sprintf( _n( '%d pg', '%d pgs', $page_count, 'filecheck' ), $page_count ) ) . '</span>';
            }
            echo '</span>';
        }
        if ( $more > 0 ) {
            /* translators: %d: number of additional uploaded files not shown as thumbnails. */
            echo '<span class="fc-cart-proof-more">' . esc_html( sprintf( __( '+%d more', 'filecheck' ), $more ) ) . '</span>';
        }
        echo '</div>';
    }

    /**
     * Returns the job's files that have proof pages, as
     * array( array( 'name' => string, 'proofs' => array( array( 'url' => string ) ) ) ).
     * Cached in a transient — a completed job's proofs are immutable, so one
     * API call per job serves every subsequent cart render.
     */
    protected function get_proof_files( $job_id ) {
        if ( isset( $this->proof_cache[ $job_id ] ) ) {
            return $this->proof_cache[ $job_id ];
        }

        $transient_key = 'filecheck_proofs_' . md5( $job_id );
        $files         = get_transient( $transient_key );

        if ( false === $files ) {
            $files = array();

            if ( get_option( 'filecheck_secret_key' ) ) {
                $summary = Filecheck_API_Client::instance()->get_job_summary( $job_id );

                if ( is_wp_error( $summary ) ) {
                    // Cache the miss briefly so an API outage can't add a
                    // slow remote call to every cart page load.
                    set_transient( $transient_key, array(), 5 * MINUTE_IN_SECONDS );
                } else {
                    foreach ( $summary['files'] as $file ) {
                        if ( ! empty( $file['proofs'] ) ) {
                            $files[] = array(
                                'name'   => $file['name'],
                                'proofs' => $file['proofs'],
                            );
                        }
                    }
                    set_transient( $transient_key, $files, 6 * HOUR_IN_SECONDS );
                }
            }
        }

        $this->proof_cache[ $job_id ] = $files;
        return $files;
    }

    public function add_order_line_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( isset( $values['filecheck_job_id'] ) && ! empty( $values['filecheck_job_id'] ) ) {
            $item->update_meta_data( '_filecheck_job_id', $values['filecheck_job_id'] );
            if ( WC()->session ) {
                $product_id = $item->get_product_id();
                WC()->session->__unset( 'fc_job_' . $product_id );
            }
        }
    }
}
