<?php
namespace BEEOCHAuthNetDiag;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to wc_authorize_net_cim_credit_card_api_request_performed.
 *
 * Fires for every AN API call. We only capture createTransactionRequest
 * calls — the only calls that can result in a charge.
 *
 * Every public callback is wrapped in try/catch so this plugin can NEVER
 * throw an exception into WooCommerce's payment flow.
 *
 * Overhead per transaction: ~2 XML parses (<2 ms) + 1 DB INSERT (~3 ms).
 */
class ApiListener {

	/** Last inserted diagnostic record ID (this PHP process/request only). */
	private static ?int $active_record_id = null;

	/** @var Repository */
	private Repository $repo;

	/** @var Settings */
	private Settings $settings;

	public function __construct( Repository $repo, Settings $settings ) {
		$this->repo     = $repo;
		$this->settings = $settings;
	}

	// ── Hook registration ─────────────────────────────────────────────────────

	public function register(): void {
		add_action(
			'wc_authorize_net_cim_credit_card_api_request_performed',
			array( $this, 'handle_api_event' ),
			20, 3
		);
	}

	// ── Main callback — wrapped so it can NEVER interfere with payment ────────

	/**
	 * @param mixed $request_data   Already-sanitized array from the gateway.
	 * @param mixed $response_data  Already-sanitized array from the gateway.
	 * @param mixed $api            SV_WC_API_Base instance.
	 */
	public function handle_api_event( $request_data, $response_data, $api ): void {
		try {
			$this->process_api_event( $request_data, $response_data, $api );
		} catch ( \Throwable $e ) {
			// Intentionally silent — this plugin must never affect payment.
		}
	}

	// ── Processing (called from try block above) ──────────────────────────────

	private function process_api_event( $request_data, $response_data, $api ): void {
		if ( ! $this->settings->is_enabled() ) {
			return;
		}

		$request_data  = is_array( $request_data )  ? $request_data  : array();
		$response_data = is_array( $response_data ) ? $response_data : array();

		// ── Parse request type ────────────────────────────────────────────────
		$request_body = isset( $request_data['body'] ) ? (string) $request_data['body'] : '';
		$request_type = $this->extract_root_element( $request_body );

		// ── Only log transaction requests ─────────────────────────────────────
		// Skip profile management, webhook registration, and all other
		// ancillary API calls — they are not relevant to the charge problem.
		if ( ! $this->is_transaction_request( $request_type ) ) {
			return;
		}

		// ── Parse response ────────────────────────────────────────────────────
		$response_body = isset( $response_data['body'] ) ? (string) $response_data['body'] : '';
		$http_status   = isset( $response_data['code'] ) ? (int) $response_data['code'] : 0;
		$duration      = isset( $request_data['duration'] )
			? (float) rtrim( (string) $request_data['duration'], 's' )
			: null;

		$parsed = $this->parse_response( $response_body );

		// ── Extract order context ─────────────────────────────────────────────
		$order     = ( method_exists( $api, 'get_order' ) ) ? $api->get_order() : null;
		$order_id  = null;
		$order_num = null;
		if ( $order && method_exists( $order, 'get_id' ) ) {
			$order_id  = (int) $order->get_id();
			$order_num = method_exists( $order, 'get_order_number' )
				? (string) $order->get_order_number()
				: (string) $order_id;
		}

		// ── Determine initial status ──────────────────────────────────────────
		$status = $this->determine_status( $http_status, $response_body, $parsed );

		// ── Insert diagnostic row ─────────────────────────────────────────────
		$record_id = $this->repo->insert_event( array(
			'correlation_key'      => $this->make_correlation_key( $order_id ),
			'order_id'             => $order_id,
			'order_number'         => $order_num,
			'transaction_id'       => $parsed['transaction_id'],
			'request_type'         => $request_type,
			'diagnostic_status'    => $status,
			'http_status'          => $http_status ?: null,
			'api_response_code'    => $parsed['api_response_code'],
			'api_response_message' => $parsed['api_response_message'],
			'request_duration'     => $duration,
			'request_body'         => $request_body ?: null,
			'response_body'        => $response_body ?: null,
		) );

		if ( ! $record_id ) {
			return;
		}

		self::$active_record_id = $record_id;

		// For approved charges: register shutdown guard + schedule verification.
		if ( $status === 'pending' ) {
			ShutdownHandler::register( $record_id, $this->repo );
			$this->schedule_verification( $record_id, $order_id );
		}
	}

	// ── Static accessors ──────────────────────────────────────────────────────

	public static function get_active_record_id(): ?int {
		return self::$active_record_id;
	}

	public static function clear_active_record_id(): void {
		self::$active_record_id = null;
	}

	// ── Transaction filter ────────────────────────────────────────────────────

	/**
	 * Return true only for request types we care about.
	 * Filters out createCustomerProfileRequest, createCustomerPaymentProfileRequest,
	 * getCustomerProfileRequest, createCustomerShippingAddressRequest, etc.
	 *
	 * @param string|null $request_type Root XML element of the request body.
	 */
	private function is_transaction_request( ?string $request_type ): bool {
		if ( $request_type === null ) {
			// Could not parse the request — may be a network error on a charge.
			// Log it conservatively to avoid missing the approved-but-not-saved case.
			return true;
		}
		// Match createTransactionRequest, getTransactionDetailsRequest, voidTransactionRequest, etc.
		return stripos( $request_type, 'transaction' ) !== false;
	}

	// ── Status determination ──────────────────────────────────────────────────

	private function determine_status( int $http_status, string $body, array $parsed ): string {
		if ( $http_status === 0 || empty( $body ) ) {
			return 'network_error';
		}
		if ( $http_status >= 400 ) {
			return 'api_error';
		}
		if ( $parsed['approved'] ) {
			return 'pending';
		}
		if ( $parsed['declined'] ) {
			return 'declined';
		}
		if ( $parsed['transaction_error'] ) {
			return 'api_error';
		}
		// Non-charge transaction call (e.g., getTransactionDetails) or unrecognised.
		return $parsed['result_ok'] ? 'completed' : 'api_error';
	}

	// ── Response XML parser ───────────────────────────────────────────────────

	private function parse_response( string $body ): array {
		$result = array(
			'transaction_id'       => null,
			'api_response_code'    => null,
			'api_response_message' => null,
			'approved'             => false,
			'declined'             => false,
			'transaction_error'    => false,
			'result_ok'            => false,
		);

		if ( empty( $body ) ) {
			return $result;
		}

		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $body );
		libxml_use_internal_errors( $prev );

		if ( $xml === false ) {
			$result['api_response_message'] = substr( wp_strip_all_tags( $body ), 0, 500 );
			return $result;
		}

		$result_code   = isset( $xml->messages->resultCode )
			? strtolower( (string) $xml->messages->resultCode )
			: '';
		$result['result_ok'] = ( $result_code === 'ok' );

		if ( isset( $xml->messages->message ) ) {
			$msg = $xml->messages->message;
			$result['api_response_code']    = (string) ( $msg->code ?? '' ) ?: null;
			$result['api_response_message'] = (string) ( $msg->text ?? '' ) ?: null;
		}

		if ( isset( $xml->transactionResponse ) ) {
			$tr            = $xml->transactionResponse;
			$response_code = (string) ( $tr->responseCode ?? '' );

			$trans_id = (string) ( $tr->transId ?? '' );
			if ( $trans_id !== '' && $trans_id !== '0' ) {
				$result['transaction_id'] = $trans_id;
			}

			if ( isset( $tr->messages->message ) ) {
				$tm = $tr->messages->message;
				if ( ! empty( (string) ( $tm->code ?? '' ) ) ) {
					$result['api_response_code'] = (string) $tm->code;
				}
				if ( ! empty( (string) ( $tm->description ?? '' ) ) ) {
					$result['api_response_message'] = (string) $tm->description;
				}
			} elseif ( isset( $tr->errors->error ) ) {
				$te = $tr->errors->error;
				if ( ! empty( (string) ( $te->errorCode ?? '' ) ) ) {
					$result['api_response_code'] = (string) $te->errorCode;
				}
				if ( ! empty( (string) ( $te->errorText ?? '' ) ) ) {
					$result['api_response_message'] = (string) $te->errorText;
				}
			}

			$result['approved']         = ( $response_code === '1' );
			$result['declined']         = ( $response_code === '2' );
			$result['transaction_error'] = ( $response_code === '3' );
		}

		return $result;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function extract_root_element( string $body ): ?string {
		if ( empty( $body ) ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $body );
		libxml_use_internal_errors( $prev );
		return $xml !== false ? $xml->getName() : null;
	}

	private function make_correlation_key( ?int $order_id ): string {
		return hash( 'sha256', ( $order_id ?? 'no-order' ) . microtime() . wp_generate_uuid4() );
	}

	private function schedule_verification( int $record_id, ?int $order_id ): void {
		$args      = array( $record_id, (int) $order_id );
		$timestamp = time() + 120;

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				$timestamp,
				'beeoch_authnet_verify_pending',
				$args,
				'beeoch-authnet-diag'
			);
		} else {
			wp_schedule_single_event( $timestamp, 'beeoch_authnet_verify_pending', $args );
		}
	}
}
