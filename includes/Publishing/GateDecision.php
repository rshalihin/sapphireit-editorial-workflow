<?php
/**
 * Publish gate decision.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Publishing;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable answer of `PublishGate::evaluate()`: the outcome of one publish
 * attempt, the mode it was judged under, the workflow status it was judged on
 * and why (E1, E2, E5).
 *
 * @since 1.1.0
 * @internal
 */
final class GateDecision {

	/**
	 * The gate does not apply (mode `off`).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const OUTCOME_NOT_APPLICABLE = 'not_applicable';

	/**
	 * The attempt passes the rule.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const OUTCOME_ALLOWED = 'allowed';

	/**
	 * The attempt fails the rule but goes ahead; see the reason.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const OUTCOME_BYPASSED = 'bypassed';

	/**
	 * The attempt fails the rule and is blocked.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const OUTCOME_REFUSED = 'refused';

	/**
	 * Every outcome.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const OUTCOMES = array( self::OUTCOME_NOT_APPLICABLE, self::OUTCOME_ALLOWED, self::OUTCOME_BYPASSED, self::OUTCOME_REFUSED );

	/**
	 * No reason (not applicable, or refused).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const REASON_NONE = '';

	/**
	 * Allowed because the workflow status is publishable.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const REASON_APPROVED = 'approved';

	/**
	 * Bypassed because the mode is `log_only`.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const REASON_LOG_ONLY = 'log_only';

	/**
	 * Bypassed because the actor holds the bypass capability.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const REASON_CAPABILITY = 'capability';

	/**
	 * Bypassed because the gate itself failed and failed open (E5).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const REASON_ERROR = 'error';

	/**
	 * One of the `OUTCOME_*` constants.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private $outcome;

	/**
	 * Gate mode the attempt was judged under.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private $mode;

	/**
	 * Workflow status the attempt was judged on.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private $workflow_status;

	/**
	 * One of the `REASON_*` constants.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private $reason;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param string $outcome         One of the `OUTCOME_*` constants.
	 * @param string $mode            Gate mode.
	 * @param string $workflow_status Workflow status judged on.
	 * @param string $reason          One of the `REASON_*` constants.
	 *
	 * @throws InvalidArgumentException When `$outcome` is not a known outcome.
	 */
	public function __construct( string $outcome, string $mode, string $workflow_status, string $reason ) {
		if ( ! in_array( $outcome, self::OUTCOMES, true ) ) {
			throw new InvalidArgumentException(
				sprintf( 'SapphireIT Editorial Workflow: unknown publish gate outcome "%s".', $outcome ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception message, never rendered as HTML.
			);
		}

		$this->outcome         = $outcome;
		$this->mode            = $mode;
		$this->workflow_status = $workflow_status;
		$this->reason          = $reason;
	}

	/**
	 * A decision for an attempt the gate does not apply to.
	 *
	 * @since 1.1.0
	 *
	 * @param string $mode Gate mode.
	 * @return self
	 */
	public static function not_applicable( string $mode ): self {
		return new self( self::OUTCOME_NOT_APPLICABLE, $mode, '', self::REASON_NONE );
	}

	/**
	 * A decision for an attempt the gate failed to judge: it goes ahead and is
	 * recorded as a bypass for reason `error` (E5).
	 *
	 * @since 1.1.0
	 *
	 * @param string $mode            Gate mode.
	 * @param string $workflow_status Workflow status, `''` when unknown.
	 * @return self
	 */
	public static function failed_open( string $mode, string $workflow_status ): self {
		return new self( self::OUTCOME_BYPASSED, $mode, $workflow_status, self::REASON_ERROR );
	}

	/**
	 * Outcome.
	 *
	 * @since 1.1.0
	 *
	 * @return string One of the `OUTCOME_*` constants.
	 */
	public function outcome(): string {
		return $this->outcome;
	}

	/**
	 * Gate mode the attempt was judged under.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * Workflow status the attempt was judged on.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function workflow_status(): string {
		return $this->workflow_status;
	}

	/**
	 * Reason.
	 *
	 * @since 1.1.0
	 *
	 * @return string One of the `REASON_*` constants.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Whether the attempt is blocked.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_refused(): bool {
		return self::OUTCOME_REFUSED === $this->outcome;
	}

	/**
	 * Whether the attempt fails the rule but goes ahead.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_bypassed(): bool {
		return self::OUTCOME_BYPASSED === $this->outcome;
	}

	/**
	 * Whether the gate applies at all.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function applies(): bool {
		return self::OUTCOME_NOT_APPLICABLE !== $this->outcome;
	}
}
