<?php
/**
 * The Payzum payment processor: creates the hosted-checkout invoice on
 * submission and settles the transaction from the signed IPN.
 *
 * The browser return is handled by BaseProcessor::handleSessionRedirectBack,
 * which reads the transaction status and never sets it — crypto confirms
 * asynchronously, so a buyer back before settlement is the normal case.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FluentForm\App\Modules\Payments\PaymentMethods\BaseProcessor;
use Payzum\Errors\PayzumException;
use Payzum\Errors\SignatureException;
use Payzum\Payzum;
use Payzum\PaymentStatus;
use Payzum\Webhooks\Verifier;

class Payzum_FF_Processor extends BaseProcessor {

	public $method = 'payzum';

	/** Submission meta holding recently seen IPN event ids — retries reuse the id. */
	const EVENT_IDS_META = '_payzum_ipn_event_ids';

	/** How many past event ids to keep per submission for deduplication. */
	const EVENT_IDS_KEEP = 20;

	/**
	 * How far the settled amount may fall short of the submission total before
	 * it is held. Half a cent: price_amount arrives as a JSON number and the
	 * two sides round differently, so `==` on floats would reject good payments.
	 */
	const AMOUNT_TOLERANCE = 0.005;

	/** Seconds to wait for the per-submission IPN lock before asking for a retry. */
	const LOCK_TIMEOUT = 10;

	public function init() {
		add_action( 'fluentform/process_payment_' . $this->method, array( $this, 'handlePaymentAction' ), 10, 6 );
		add_action( 'fluentform/payment_frameless_' . $this->method, array( $this, 'handleSessionRedirectBack' ) );
	}

	public function getPaymentMode() {
		$settings = Payzum_FF_Settings::getSettings( false );
		return 'staging' === $settings['environment'] ? 'test' : 'live';
	}

	protected function client() {
		$settings = Payzum_FF_Settings::getSettings();
		return 'staging' === $settings['environment']
			? Payzum::sandbox( $settings['api_key'] )
			: new Payzum( $settings['api_key'] );
	}

	/* ------------------------------------------------------------------ checkout */

	public function handlePaymentAction( $submissionId, $submissionData, $form, $methodSettings, $hasSubscriptions, $totalPayable = 0 ) {
		$this->setSubmissionId( $submissionId );
		$submission = $this->getSubmission();

		if ( $hasSubscriptions ) {
			// Crypto has no card on file to pull from — recurring subscription
			// payments cannot work, and pretending otherwise strands the form.
			wp_send_json(
				array(
					'errors' => __( 'Crypto payment is not available for subscription items. Please choose another payment method.', 'payzum-fluent-forms' ),
				),
				423
			);
		}

		if ( ! $this->getAmountTotal() ) {
			return false;
		}

		$transaction = $this->createInitialPendingTransaction( $submission, $hasSubscriptions );

		$settings = Payzum_FF_Settings::getSettings();

		$returnUrl = add_query_arg(
			array(
				'fluentform_payment' => $submission->id,
				'payment_method'     => $this->method,
				'transaction_hash'   => $transaction->transaction_hash,
				'type'               => 'success',
			),
			site_url( 'index.php' )
		);

		$cancelUrl = $submission->source_url;
		if ( ! wp_http_validate_url( $cancelUrl ) ) {
			$cancelUrl = site_url( $cancelUrl );
		}

		try {
			$invoice = $this->client()->payments->create(
				// Fluent Forms stores every amount as integer hundredths,
				// whatever the currency — converted with integer math, never
				// through a float.
				priceAmount:      self::centsToDecimal( (int) $transaction->payment_total ),
				priceCurrency:    strtolower( $transaction->currency ),
				payCurrency:      $settings['pay_currency'],
				// The submission id plus a slice of the unguessable
				// transaction hash: resolvable on the IPN, not forgeable from
				// a guessed id, and stable after completion.
				orderId:          self::orderReference( $submission->id, $transaction->transaction_hash ),
				orderDescription: mb_substr( $form->title . ' — ' . get_bloginfo( 'name' ), 0, 2000 ),
				ipnCallbackUrl:   Payzum_FF_Settings::ipnUrl(),
				successUrl:       wp_sanitize_redirect( $returnUrl ),
				cancelUrl:        wp_sanitize_redirect( $cancelUrl ),
				// The API does not enforce order_id uniqueness; without this
				// key a retried create could mint a second real invoice. The
				// site-hash prefix keeps two sites on one merchant apart.
				idempotencyKey:   'ffpz-' . substr( md5( home_url( '/' ) ), 0, 8 ) . '-' . $submission->id,
			);
		} catch ( PayzumException $e ) {
			Payzum_FF_Settings::log( 'invoice creation failed for submission ' . $submission->id . ': ' . $e->getMessage() );
			wp_send_json(
				array(
					'errors' => __( 'Unable to start the crypto payment. Please try again or pick another payment method.', 'payzum-fluent-forms' ),
				),
				423
			);
		}

		// Older docs used `id`; keep the fallback when reading the payment id.
		$paymentId  = isset( $invoice['payment_id'] ) ? $invoice['payment_id'] : ( isset( $invoice['id'] ) ? $invoice['id'] : '' );
		$invoiceUrl = isset( $invoice['invoice_url'] ) ? $invoice['invoice_url'] : '';

		if ( '' === $invoiceUrl ) {
			// invoice_url is null when the gateway has no checkout base
			// configured for the merchant.
			Payzum_FF_Settings::log( 'no invoice_url for submission ' . $submission->id . ' (payment ' . $paymentId . ')' );
			wp_send_json(
				array(
					'errors' => __( 'The payment provider did not return a checkout URL. Please try again later.', 'payzum-fluent-forms' ),
				),
				423
			);
		}

		$this->updateTransaction(
			$transaction->id,
			array(
				'charge_id'      => $paymentId,
				'payment_method' => $this->method,
			)
		);

		do_action(
			'fluentform/log_data',
			array(
				'parent_source_id' => $submission->form_id,
				'source_type'      => 'submission_item',
				'source_id'        => $submission->id,
				'component'        => 'Payment',
				'status'           => 'info',
				'title'            => __( 'Redirect to Payzum', 'payzum-fluent-forms' ),
				'description'      => __( 'User redirected to the Payzum hosted checkout to complete the payment', 'payzum-fluent-forms' ),
			)
		);

		wp_send_json_success(
			array(
				'nextAction'   => 'payment',
				'actionName'   => 'normalRedirect',
				'redirect_url' => $invoiceUrl,
				'message'      => __( 'You are redirecting to the Payzum secure checkout. Please wait…', 'payzum-fluent-forms' ),
				'result'       => array(
					'insert_id' => $submission->id,
				),
			),
			200
		);
	}

	/* ----------------------------------------------------------------------- IPN */

	/**
	 * Handle a signed payment notification. The only place a submission is
	 * marked paid. Payzum retries a delivery several times whatever the
	 * response code and re-delivers settled invoices, so this handler is
	 * idempotent and the rejection paths stay cheap and side-effect-free.
	 */
	public function handleIpn() {
		$raw = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '' === $raw || false === $raw ) {
			$this->respond( 400, 'empty body' );
		}

		$settings = Payzum_FF_Settings::getSettings();
		if ( '' === $settings['webhook_secret'] ) {
			Payzum_FF_Settings::log( 'IPN received but no webhook secret configured' );
			$this->respond( 500, 'not configured' );
		}

		$headers  = $this->requestHeaders();
		$verifier = new Verifier( $settings['webhook_secret'] );

		try {
			// Signature (HMAC-SHA-512 over the raw bytes) and the replay
			// window are checked before any field of the payload is read.
			$data = $verifier->verifyPaymentIpn( $raw, $headers );
		} catch ( SignatureException $e ) {
			Payzum_FF_Settings::log( 'IPN rejected: ' . $e->getMessage() );
			$this->respond( 401, 'bad signature' );
			return; // respond() exits; this keeps static analysis honest.
		} catch ( PayzumException $e ) {
			$this->respond( 400, 'bad json' );
			return;
		}

		$reference    = isset( $data['order_id'] ) ? (string) $data['order_id'] : '';
		$submissionId = $this->resolveSubmissionId( $reference );
		if ( ! $submissionId ) {
			Payzum_FF_Settings::log( 'IPN for unknown reference: ' . $reference );
			$this->respond( 404, 'submission not found' );
		}

		$this->setSubmissionId( $submissionId );
		$transaction = $this->getLastTransaction( $submissionId );
		if ( ! $transaction ) {
			$this->respond( 404, 'transaction not found' );
		}

		$wireStatus = isset( $data['payment_status'] ) ? (string) $data['payment_status'] : '';
		try {
			$status = PaymentStatus::fromMerchant( $wireStatus );
		} catch ( PayzumException $e ) {
			// A value from a future API version: acknowledge rather than 500.
			Payzum_FF_Settings::log( 'IPN carried unknown payment_status "' . $wireStatus . '" — contract change?' );
			$this->respond( 200, 'ignored' );
			return;
		}

		// Everything from here to the release is one critical section: two
		// deliveries carrying different event ids must not both read "still
		// pending" and settle the submission twice.
		$lock = $this->acquireSubmissionLock( $submissionId );
		if ( false === $lock ) {
			// Another delivery is mid-transition. 503 rather than 200: this
			// IPN is only late, not duplicate — a retry is the right outcome.
			$this->respond( 503, 'busy' );
		}

		$eventId = (string) $verifier->eventId( $headers );
		if ( '' !== $eventId && $this->isDuplicateEvent( $eventId ) ) {
			$this->releaseSubmissionLock( $lock );
			$this->respond( 200, 'duplicate' );
		}

		// Re-read under the lock: the cached copies predate the wait.
		$transaction = $this->getTransaction( $transaction->id );
		$submission  = $this->getSubmission();

		if ( 'paid' === $transaction->status && 'paid' === $submission->payment_status ) {
			// A redelivered terminal event must never downgrade a settled submission.
			if ( '' !== $eventId ) {
				$this->rememberEvent( $eventId );
			}
			$this->releaseSubmissionLock( $lock );
			$this->respond( 200, 'already processed' );
		}

		if ( $status->isPaid() ) {
			$mismatch = $this->settlementMismatch( $transaction, $data );
			if ( null !== $mismatch ) {
				// Acknowledged but never fulfilled: a retry would deliver the
				// same figures. The transaction stays pending for review.
				Payzum_FF_Settings::log( 'IPN finished for submission ' . $submissionId . ' rejected: ' . $mismatch );
				$this->releaseSubmissionLock( $lock );
				$this->respond( 200, 'amount mismatch' );
			}

			$paymentId = isset( $data['payment_id'] ) ? (string) $data['payment_id'] : $transaction->charge_id;
			$this->updateTransaction(
				$transaction->id,
				array(
					'charge_id'    => $paymentId,
					'payment_note' => maybe_serialize( $data ),
				)
			);
			$this->changeTransactionStatus( $transaction->id, 'paid' );
			$this->changeSubmissionPaymentStatus( 'paid' );
			// Fires the form's own confirmations/notifications exactly once.
			$this->completePaymentSubmission( false );
			$this->recalculatePaidTotal();
		} elseif ( $status->isTerminal() ) { // expired or failed
			if ( 'pending' === $transaction->status ) {
				$this->changeTransactionStatus( $transaction->id, 'failed' );
				$this->changeSubmissionPaymentStatus( 'failed' );
			}
		}
		// waiting / partially_paid: no state change. A partial payment is
		// underpaid and must not fulfil anything.

		if ( '' !== $eventId ) {
			$this->rememberEvent( $eventId );
		}
		$this->releaseSubmissionLock( $lock );
		$this->respond( 200, 'ok' );
	}

	/**
	 * The verified payload's amount and currency must match the transaction.
	 *
	 * Fails closed: price_amount is required in the contract, so a payload
	 * without a readable amount cannot be shown to cover this submission and
	 * must never settle it. Only a SHORTFALL counts — an overpayment is still
	 * a paid submission.
	 *
	 * @return string|null a human description of the mismatch, or null on match.
	 */
	protected function settlementMismatch( $transaction, array $data ) {
		$expected_amount   = (float) self::centsToDecimal( (int) $transaction->payment_total );
		$expected_currency = strtolower( (string) $transaction->currency );

		if ( ! isset( $data['price_amount'] ) || ! is_numeric( $data['price_amount'] ) ) {
			return 'no readable price_amount in the payload';
		}
		$paid_amount   = (float) $data['price_amount'];
		$paid_currency = isset( $data['price_currency'] ) ? strtolower( trim( (string) $data['price_currency'] ) ) : '';

		if ( ( $expected_amount - $paid_amount ) > self::AMOUNT_TOLERANCE ) {
			return 'settled ' . $paid_amount . ' while the submission is for ' . $expected_amount . ' ' . $expected_currency;
		}
		if ( '' !== $paid_currency && $paid_currency !== $expected_currency ) {
			return 'settled in ' . $paid_currency . ' while invoiced in ' . $expected_currency;
		}
		return null;
	}

	/* ------------------------------------------------------------------- helpers */

	/** "1234-a1b2c3d4": submission id plus the tail of the unguessable hash. */
	public static function orderReference( $submissionId, $transactionHash ) {
		return (int) $submissionId . '-' . substr( (string) $transactionHash, -8 );
	}

	/**
	 * Integer hundredths → exact decimal string ("2999" → "29.99"), never
	 * through a float.
	 */
	public static function centsToDecimal( $cents ) {
		$cents = (int) $cents;
		$sign  = $cents < 0 ? '-' : '';
		$cents = abs( $cents );
		return $sign . intdiv( $cents, 100 ) . '.' . str_pad( (string) ( $cents % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/** @return int 0 when the reference resolves to nothing. */
	protected function resolveSubmissionId( $reference ) {
		$submissionId = (int) $reference;
		if ( ! $submissionId ) {
			return 0;
		}
		$transaction = $this->getLastTransaction( $submissionId );
		if ( ! $transaction || $this->method !== $transaction->payment_method ) {
			return 0;
		}
		if ( $reference !== self::orderReference( $submissionId, $transaction->transaction_hash ) ) {
			return 0;
		}
		return $submissionId;
	}

	/**
	 * Cross-request lock for this submission's IPN transition.
	 *
	 * GET_LOCK is the only lock WordPress can count on here: wp_cache_add()
	 * is request-local unless a persistent object cache is installed, so it
	 * would look like a lock and protect nothing. The name carries install,
	 * table prefix, plugin tag and record id — GET_LOCK names are scoped to
	 * the whole MySQL server, and unrelated stores must not block each other.
	 *
	 * @return string|null|false name when acquired; false on timeout; null
	 *                           when the database offers no lock (proceed
	 *                           unlocked rather than refuse the payment).
	 */
	protected function acquireSubmissionLock( $submissionId ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}
		$name = 'payzum_' . substr( md5( DB_NAME . '|' . $wpdb->prefix . '|ff-submission|' . $submissionId ), 0, 32 );

		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT ) );
		if ( '1' === (string) $got ) {
			return $name;
		}
		if ( null === $got ) {
			Payzum_FF_Settings::log( 'GET_LOCK unavailable — processing IPN for submission ' . $submissionId . ' without a lock' );
			return null;
		}
		return false;
	}

	protected function releaseSubmissionLock( $name ) {
		global $wpdb;

		if ( ! is_string( $name ) || '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	protected function isDuplicateEvent( $eventId ) {
		$seen = $this->getMetaData( self::EVENT_IDS_META );
		return is_array( $seen ) && in_array( $eventId, $seen, true );
	}

	protected function rememberEvent( $eventId ) {
		$seen   = $this->getMetaData( self::EVENT_IDS_META );
		$seen   = is_array( $seen ) ? $seen : array();
		$seen[] = $eventId;
		// BaseProcessor::setMetaData INSERTS a new row on every call while
		// getMetaData reads the oldest one — delete-then-insert is the only
		// way to actually update the list. Safe under the submission lock.
		$this->deleteMetaData( self::EVENT_IDS_META );
		$this->setMetaData( self::EVENT_IDS_META, array_slice( $seen, -self::EVENT_IDS_KEEP ) );
	}

	/**
	 * Request headers for the Verifier: getallheaders() when the SAPI has it,
	 * otherwise $_SERVER — the Verifier accepts the CGI form directly.
	 */
	protected function requestHeaders() {
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				return $headers;
			}
		}
		return array_filter( $_SERVER, 'is_string' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- raw bytes needed for HMAC lookup; never echoed.
	}

	protected function respond( $code, $message ) {
		status_header( $code );
		nocache_headers();
		echo esc_html( $message );
		exit;
	}
}
