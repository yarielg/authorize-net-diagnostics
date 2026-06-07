<?php
namespace BEEOCHAuthNetDiag;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table implementation for the diagnostics log.
 */
class ListTable extends \WP_List_Table {

	/** @var Repository */
	private Repository $repo;

	public function __construct( Repository $repo ) {
		parent::__construct( array(
			'singular' => 'diagnostic_record',
			'plural'   => 'diagnostic_records',
			'ajax'     => false,
		) );
		$this->repo = $repo;
	}

	// ── Columns ───────────────────────────────────────────────────────────────

	public function get_columns(): array {
		return array(
			'cb'                => '<input type="checkbox" />',
			'created_at_gmt'    => __( 'Date (GMT)', 'beeoch-authnet-diagnostics' ),
			'order_id'          => __( 'Order', 'beeoch-authnet-diagnostics' ),
			'order_number'      => __( 'Order #', 'beeoch-authnet-diagnostics' ),
			'transaction_id'    => __( 'Transaction ID', 'beeoch-authnet-diagnostics' ),
			'request_type'      => __( 'Request Type', 'beeoch-authnet-diagnostics' ),
			'http_status'       => __( 'HTTP', 'beeoch-authnet-diagnostics' ),
			'api_response_code' => __( 'AN Result', 'beeoch-authnet-diagnostics' ),
			'transaction_saved' => __( 'Saved', 'beeoch-authnet-diagnostics' ),
			'payment_completed' => __( 'Paid', 'beeoch-authnet-diagnostics' ),
			'diagnostic_status' => __( 'Status', 'beeoch-authnet-diagnostics' ),
		);
	}

	public function get_sortable_columns(): array {
		return array(
			'created_at_gmt' => array( 'created_at_gmt', true ),
			'order_id'       => array( 'order_id', false ),
			'transaction_id' => array( 'transaction_id', false ),
			'http_status'    => array( 'http_status', false ),
		);
	}

	// ── Bulk actions ──────────────────────────────────────────────────────────

	protected function get_bulk_actions(): array {
		return array( 'delete' => __( 'Delete', 'beeoch-authnet-diagnostics' ) );
	}

	// ── Column renderers ──────────────────────────────────────────────────────

	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="record_ids[]" value="%d" />', (int) $item->id );
	}

	protected function column_created_at_gmt( $item ): string {
		$ts  = strtotime( $item->created_at_gmt );
		$ago = human_time_diff( $ts, time() );
		return sprintf(
			'<abbr title="%s">%s ago</abbr>',
			esc_attr( gmdate( 'Y-m-d H:i:s', $ts ) . ' UTC' ),
			esc_html( $ago )
		);
	}

	protected function column_order_id( $item ): string {
		if ( ! $item->order_id ) {
			return '—';
		}
		$edit_url = get_edit_post_link( (int) $item->order_id );
		// HPOS: try admin orders URL.
		if ( ! $edit_url && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $item->order_id );
			if ( $order && method_exists( $order, 'get_edit_order_url' ) ) {
				$edit_url = $order->get_edit_order_url();
			}
		}
		$view_url = add_query_arg(
			array( 'page' => 'beeoch-authnet-diagnostics', 'record_id' => $item->id ),
			admin_url( 'admin.php' )
		);
		$link = $edit_url
			? sprintf( '<a href="%s">#%d</a>', esc_url( $edit_url ), (int) $item->order_id )
			: esc_html( '#' . $item->order_id );

		$actions = array(
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( $view_url ), __( 'Details', 'beeoch-authnet-diagnostics' ) ),
		);
		return $link . $this->row_actions( $actions );
	}

	protected function column_order_number( $item ): string {
		return $item->order_number ? esc_html( $item->order_number ) : '—';
	}

	protected function column_transaction_id( $item ): string {
		return $item->transaction_id ? esc_html( $item->transaction_id ) : '—';
	}

	protected function column_request_type( $item ): string {
		return $item->request_type ? esc_html( $item->request_type ) : '—';
	}

	protected function column_http_status( $item ): string {
		$code = (int) $item->http_status;
		if ( ! $code ) {
			return '<span style="color:#c00;">Network Error</span>';
		}
		$color = $code >= 400 ? '#c00' : '#0a0';
		return sprintf( '<span style="color:%s">%d</span>', esc_attr( $color ), $code );
	}

	protected function column_api_response_code( $item ): string {
		$code = $item->api_response_code ? esc_html( $item->api_response_code ) : '—';
		$msg  = $item->api_response_message
			? sprintf( '<br><small style="color:#666">%s</small>', esc_html( substr( $item->api_response_message, 0, 80 ) ) )
			: '';
		return $code . $msg;
	}

	protected function column_transaction_saved( $item ): string {
		return $item->transaction_saved ? '<span style="color:green">&#10003;</span>' : '<span style="color:#999">—</span>';
	}

	protected function column_payment_completed( $item ): string {
		return $item->payment_completed ? '<span style="color:green">&#10003;</span>' : '<span style="color:#999">—</span>';
	}

	protected function column_diagnostic_status( $item ): string {
		return sprintf(
			'<span class="beeoch-status beeoch-status--%s">%s</span>',
			esc_attr( $item->diagnostic_status ),
			esc_html( $this->status_label( $item->diagnostic_status ) )
		);
	}

	public function column_default( $item, $column_name ): string {
		return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '—';
	}

	// ── Filter nav ────────────────────────────────────────────────────────────

	protected function get_views(): array {
		$current        = isset( $_GET['status_filter'] ) ? sanitize_key( $_GET['status_filter'] ) : '';
		$base_url       = admin_url( 'admin.php?page=beeoch-authnet-diagnostics' );
		$all_count      = $this->repo->count_records();

		$filters = array(
			''                             => __( 'All', 'beeoch-authnet-diagnostics' ),
			'approved_not_registered'      => __( 'Approved Not Registered', 'beeoch-authnet-diagnostics' ),
			'transaction_saved_not_completed' => __( 'Saved Not Completed', 'beeoch-authnet-diagnostics' ),
			'api_error'                    => __( 'API Errors', 'beeoch-authnet-diagnostics' ),
			'network_error'                => __( 'Network Errors', 'beeoch-authnet-diagnostics' ),
			'fatal_error'                  => __( 'Fatal Errors', 'beeoch-authnet-diagnostics' ),
			'declined'                     => __( 'Declined', 'beeoch-authnet-diagnostics' ),
			'completed'                    => __( 'Completed', 'beeoch-authnet-diagnostics' ),
		);

		$views = array();
		foreach ( $filters as $status => $label ) {
			$count = $status === ''
				? $all_count
				: $this->repo->count_records( array( 'status_filter' => $status ) );

			$active  = ( $current === $status ) ? ' class="current"' : '';
			$url     = $status === '' ? $base_url : add_query_arg( 'status_filter', $status, $base_url );
			$views[] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$active,
				esc_html( $label ),
				$count
			);
		}
		return $views;
	}

	// ── Data loading ──────────────────────────────────────────────────────────

	public function prepare_items(): void {
		$per_page     = 25;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$args = array(
			'status_filter' => isset( $_GET['status_filter'] ) ? sanitize_key( $_GET['status_filter'] ) : '',
			'search'        => isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '',
			'orderby'       => isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'id',
			'order'         => isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC',
			'per_page'      => $per_page,
			'offset'        => $offset,
		);

		$total = $this->repo->count_records( $args );

		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		) );

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$this->items = $this->repo->get_records( $args );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public static function status_label( string $status ): string {
		$labels = array(
			'pending'                         => 'Pending',
			'completed'                       => 'Completed',
			'declined'                        => 'Declined',
			'api_error'                       => 'API Error',
			'network_error'                   => 'Network Error',
			'approved_not_registered'         => 'Approved / Not Registered',
			'transaction_saved_not_completed' => 'Saved / Not Completed',
			'fatal_error'                     => 'Fatal Error',
			'on_hold'                         => 'On Hold',
			'failed'                          => 'Failed',
		);
		return $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
	}
}
