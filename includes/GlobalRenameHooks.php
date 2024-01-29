<?php

namespace MediaWiki\Extension\GlobalBlocking;

use CentralIdLookup;
use MediaWiki\Extension\Renameuser\Hook\RenameUserCompleteHook;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Hooks for compatibility with the Renameuser extension
 *
 * @license GPL-2.0-or-later
 */
class GlobalRenameHooks implements RenameUserCompleteHook {
	/** @var CentralIdLookup */
	private $centralIdLookup;

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param CentralIdLookup $centralIdLookup
	 * @param ILoadBalancer $loadBalancer
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		CentralIdLookup $centralIdLookup,
		ILoadBalancer $loadBalancer,
		UserFactory $userFactory
	) {
		$this->centralIdLookup = $centralIdLookup;
		$this->loadBalancer = $loadBalancer;
		$this->userFactory = $userFactory;
	}

	/**
	 * Called after a user was renamed. Rename usernames found in
	 * the globalblocking and global_blocking_whitelist tables.
	 *
	 * @param int $uid The user ID
	 * @param string $old The old username
	 * @param string $new The new username
	 */
	public function onRenameUserComplete( int $uid, string $old, string $new ): void {
		// Check if the user is attached to a global account.
		// Since the global rename may not have happened yet, we check for both the new and the old name,
		// using the new name first should both exist centrally.
		$newUser = $this->userFactory->newFromAnyId( $uid, $new );
		$oldUser = $this->userFactory->newFromAnyId( $uid, $old );

		$centralId = $this->centralIdLookup->centralIdFromLocalUser(
			$newUser,
			CentralIdLookup::AUDIENCE_RAW,
			CentralIdLookup::READ_LATEST
		);

		if ( !$centralId ) {
			// global rename didn't complete yet?
			$centralId = $this->centralIdLookup->centralIdFromLocalUser(
				$oldUser,
				CentralIdLookup::AUDIENCE_RAW,
				CentralIdLookup::READ_LATEST
			);
		}

		// The renamed user isn't attached to any central account
		if ( !$centralId ) {
			return;
		}

		// Rename users on the local allow list
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$dbw->update(
			'global_block_whitelist',
			[ 'gbw_address' => $new ],
			[ 'gbw_central_id' => $centralId ],
			__METHOD__
		);

		// also update the shared database; this will be run once for each wiki since
		// we cannot reliably detect which wiki is the "central" wiki across every setup,
		// so ensure that this query is idempotent
		$centralDbw = GlobalBlocking::getGlobalBlockingDatabase( DB_PRIMARY );
		$centralDbw->update(
			'globalblocks',
			[ 'gb_address' => $new ],
			[ 'gb_user_central_id' => $centralId ],
			__METHOD__
		);
	}
}
