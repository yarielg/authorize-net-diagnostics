<?php
namespace BEEOCHAuthNetDiag;

defined( 'ABSPATH' ) || exit;

/**
 * All database operations. Uses direct $wpdb — no WP_Query, no post meta.
 */
class Repository {

	/** @var string */
	private string $table;

	public function __construct() {
		$this->table = Installer::get_table_name();
	}

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Insert a new diagnostic event row.
	 *
	 * @param array $data Column => value pairs.
	 * @return int|null  Inserted row ID, or null on failure.
	 */
	public function insert_event( array $data ): ?int {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$data = array_merge(
			array(
				'created_at_gmt' => $now,
				'updated_at_gmt' => $now,
			),
			$data
		);

		$result = $wpdb->insert( $this->table, $data );
		if ( $result === false ) {
			return null;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update columns on an existing row.
	 *
	 * @param int   $id   Row primary key.
	 * @param array $data Column => value pairs.
	 * @return bool
	 */
	public function update_event( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at_gmt'] = current_time( 'mysql', true );

		$result = $wpdb->update(
			$this->table,
			$data,
			array( 'id' => $id )
		);
		return $result !== false;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Fetch a single row by primary key.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public function find_by_id( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id )
		);
		return $row ?: null;
	}

	/**
	 * Find the most recent pending row for an order.
	 * Optionally narrow by transaction ID.
	 *
	 * @param int    $order_id
	 * @param string $transaction_id Optional.
	 * @return object|null
	 */
	public function find_pending_by_order( int $order_id, string $transaction_id = '' ): ?object {
		global $wpdb;

		if ( $transaction_id !== '' ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->table}
					 WHERE order_id = %d
					   AND transaction_id = %s
					   AND transaction_saved = 0
					 ORDER BY id DESC
					 LIMIT 1",
					$order_id,
					$transaction_id
				)
			);
			if ( $row ) {
				return $row;
			}
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE order_id = %d
				   AND transaction_saved = 0
				 ORDER BY id DESC
				 LIMIT 1",
				$order_id
			)
		) ?: null;
	}

	/**
	 * Find the most recent row with transaction_saved=1 but payment_completed=0 for an order.
	 *
	 * @param int $order_id
	 * @return object|null
	 */
	public function find_saved_not_completed( int $order_id ): ?object {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE order_id = %d
				   AND transaction_saved = 1
				   AND payment_completed = 0
				 ORDER BY id DESC
				 LIMIT 1",
				$order_id
			)
		) ?: null;
	}

	/**
	 * Paginated record list with optional filtering and search.
	 *
	 * @param array $args {
	 *   string $status_filter   Optional diagnostic_status value.
	 *   string $search          Optional search term (order_id, order_number, transaction_id).
	 *   string $orderby         Column name.
	 *   string $order           ASC|DESC.
	 *   int    $per_page
	 *   int    $offset
	 * }
	 * @return object[]
	 */
	public function get_records( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'status_filter' => '',
			'search'        => '',
			'orderby'       => 'id',
			'order'         => 'DESC',
			'per_page'      => 25,
			'offset'        => 0,
		);
		$args = array_merge( $defaults, $args );

		$allowed_orderby = array(
			'id', 'order_id', 'order_number', 'transaction_id',
			'diagnostic_status', 'http_status', 'created_at_gmt',
		);
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status_filter'] ) ) {
			$where[]  = 'diagnostic_status = %s';
			$params[] = $args['status_filter'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( order_id LIKE %s OR order_number LIKE %s OR transaction_id LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = max( 0, (int) $args['offset'] );

		$sql = "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		return $rows ?: array();
	}

	/**
	 * Count records with the same filters as get_records (for pagination).
	 *
	 * @param array $args Same keys as get_records (status_filter, search).
	 * @return int
	 */
	public function count_records( array $args = array() ): int {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status_filter'] ) ) {
			$where[]  = 'diagnostic_status = %s';
			$params[] = $args['status_filter'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( order_id LIKE %s OR order_number LIKE %s OR transaction_id LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Count unresolved high-priority records (for admin notice).
	 *
	 * @return int
	 */
	public function count_unresolved(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table}
			 WHERE diagnostic_status IN ('approved_not_registered','transaction_saved_not_completed','fatal_error')
			   AND resolved_at_gmt IS NULL"
		);
	}

	// ── Health-check queries ──────────────────────────────────────────────────

	/**
	 * Count rows created on or after $since_gmt.
	 *
	 * @param string $since_gmt MySQL datetime string in UTC.
	 * @return int
	 */
	public function count_since( string $since_gmt ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE created_at_gmt >= %s",
				$since_gmt
			)
		);
	}

	/**
	 * Count rows with the given diagnostic status.
	 *
	 * @param string $status
	 * @return int
	 */
	public function count_by_status( string $status ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE diagnostic_status = %s",
				$status
			)
		);
	}

	/**
	 * Count rows stuck in 'pending' older than $older_than_seconds.
	 *
	 * @param int $older_than_seconds
	 * @return int
	 */
	public function count_stale_pending( int $older_than_seconds = 300 ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $older_than_seconds );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table}
				 WHERE diagnostic_status = 'pending'
				   AND created_at_gmt < %s",
				$cutoff
			)
		);
	}

	/**
	 * Return the most recently inserted row (for health check).
	 *
	 * @return object|null
	 */
	public function get_last_record(): ?object {
		global $wpdb;
		return $wpdb->get_row(
			"SELECT id, created_at_gmt, diagnostic_status, request_type
			 FROM {$this->table}
			 ORDER BY id DESC
			 LIMIT 1"
		) ?: null;
	}

	/**
	 * Check whether the table exists.
	 *
	 * @return bool
	 */
	public function table_exists(): bool {
		global $wpdb;
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table )
		);
		return $result === $this->table;
	}

	// ── Cleanup ───────────────────────────────────────────────────────────────

	/**
	 * Delete records older than $cutoff_gmt in the given status list.
	 *
	 * @param string[] $statuses
	 * @param string   $cutoff_gmt MySQL datetime string.
	 * @return int Rows deleted.
	 */
	public function delete_by_status_and_age( array $statuses, string $cutoff_gmt ): int {
		global $wpdb;

		if ( empty( $statuses ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( $statuses, array( $cutoff_gmt ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table}
				 WHERE diagnostic_status IN ({$placeholders})
				   AND created_at_gmt < %s",
				$params
			)
		);
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Delete very old completed non-transaction-type records (profile ops).
	 *
	 * @param string $cutoff_gmt
	 * @return int
	 */
	public function delete_non_transaction_records( string $cutoff_gmt ): int {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table}
				 WHERE request_type NOT LIKE %s
				   AND created_at_gmt < %s",
				'%Transaction%',
				$cutoff_gmt
			)
		);
		return (int) $wpdb->rows_affected;
	}
}
