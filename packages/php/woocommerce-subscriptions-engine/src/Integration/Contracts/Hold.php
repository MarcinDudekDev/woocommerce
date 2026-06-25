<?php
/**
 * Hold - put an active subscription contract on hold (suspend billing).
 *
 * A focused contract-management operation (deliberately not a catch-all manager),
 * mirroring {@see Cancellation}: transition the contract ACTIVE -> ON_HOLD through the
 * Core state machine, suspend the pending renewal so no charge fires while held, and
 * announce it. The contract keeps its `next_payment_gmt` so the held duration is
 * recoverable on {@see Reactivation}. Lives under `Integration\Contracts` so contract
 * lifecycle stays separate from the renewal money-path.
 *
 * @package Automattic\WooCommerce\SubscriptionsEngine\Integration\Contracts
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsEngine\Integration\Contracts;

use RuntimeException;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Renewal\RenewalScheduler;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\ContractRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Put a contract on hold.
 */
final class Hold {

	/**
	 * Action fired after a contract is put on hold, with `( $contract )`.
	 */
	const CONTRACT_HELD_ACTION = 'woocommerce_subscriptions_engine_contract_held';

	/**
	 * Contract repository.
	 *
	 * @var ContractRepository
	 */
	private $contracts;

	/**
	 * Construct.
	 *
	 * @param ContractRepository|null $contracts Contract repository; default instance when omitted.
	 */
	public function __construct( ?ContractRepository $contracts = null ) {
		$this->contracts = $contracts ?? new ContractRepository();
	}

	/**
	 * Hold `$contract`: transition to on-hold and clear its pending renewal.
	 *
	 * Status moves through the Core state machine ({@see Contract::set_status()}), which
	 * raises a `DomainException` on an illegal transition (e.g. holding a terminal
	 * contract). The current cycle is immutable and is NOT touched; only the live
	 * contract status moves and the pending Action Scheduler row is cleared so no charge
	 * fires while held. The `next_payment_gmt` is preserved so {@see Reactivation} can
	 * recompute the schedule forward.
	 *
	 * @param Contract $contract Contract to hold. Must have an id, and be ACTIVE.
	 * @return bool True when the contract was held and persisted.
	 * @throws RuntimeException If the contract has no id.
	 */
	public function hold( Contract $contract ): bool {
		$id = $contract->get_id();
		if ( null === $id ) {
			throw new RuntimeException( 'Hold::hold(): cannot hold a contract that has no id.' );
		}

		$contract->set_status( ContractStatus::ON_HOLD );
		$this->contracts->update( $contract );

		// No charge while held: clear the pending renewal. Reactivation re-arms it.
		RenewalScheduler::unschedule( $id );

		/**
		 * Fires after a contract is put on hold and its pending renewal cleared.
		 *
		 * @param Contract $contract The held contract.
		 */
		do_action( self::CONTRACT_HELD_ACTION, $contract );

		return true;
	}
}
