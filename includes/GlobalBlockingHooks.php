<?php

namespace MediaWiki\Extension\GlobalBlocking;

use CentralIdLookup;
use Config;
use DatabaseUpdater;
use Html;
use LogicException;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Extension\GlobalBlocking\Maintenance\PopulateCentralId;
use MediaWiki\Extension\GlobalBlocking\Special\GlobalBlockListPager;
use MediaWiki\Hook\ContributionsToolLinksHook;
use MediaWiki\Hook\GetLogTypesOnUserHook;
use MediaWiki\Hook\OtherBlockLogLinkHook;
use MediaWiki\Hook\SpecialContributionsBeforeMainOutputHook;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsExpensiveHook;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\Hook\SpecialPasswordResetOnSubmitHook;
use MediaWiki\User\Hook\UserIsBlockedGloballyHook;
use MWException;
use RequestContext;
use SpecialPage;
use Title;
use User;
use Wikimedia\IPUtils;

/**
 * MediaWiki hook handlers for the GlobalBlocking extension
 *
 * @license GPL-2.0-or-later
 */
class GlobalBlockingHooks implements
	GetUserPermissionsErrorsExpensiveHook,
	UserIsBlockedGloballyHook,
	SpecialPasswordResetOnSubmitHook,
	OtherBlockLogLinkHook,
	SpecialContributionsBeforeMainOutputHook,
	GetLogTypesOnUserHook,
	ContributionsToolLinksHook
{
	/** @var PermissionManager */
	private $permissionManager;

	/** @var Config */
	private $config;

	/** @var CentralIdLookup */
	private $centralIdLookup;

	/**
	 * @param PermissionManager $permissionManager
	 * @param Config $mainConfig
	 * @param CentralIdLookup $centralIdLookup
	 */
	public function __construct(
		PermissionManager $permissionManager,
		Config $mainConfig,
		CentralIdLookup $centralIdLookup
	) {
		$this->permissionManager = $permissionManager;
		$this->config = $mainConfig;
		$this->centralIdLookup = $centralIdLookup;
	}

	/**
	 * Extension registration callback
	 */
	public static function onRegistration() {
		global $wgWikimediaJenkinsCI, $wgGlobalBlockingDatabase, $wgDBname;

		// Override $wgGlobalBlockingDatabase for Wikimedia Jenkins.
		if ( isset( $wgWikimediaJenkinsCI ) && $wgWikimediaJenkinsCI ) {
			$wgGlobalBlockingDatabase = $wgDBname;
		}
	}

	/**
	 * This is static since LoadExtensionSchemaUpdates does not allow service dependencies
	 * @param DatabaseUpdater $updater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$base = __DIR__ . '/..';
		$type = $updater->getDB()->getType();

		$updater->addExtensionTable(
			'globalblocks',
			"$base/sql/$type/tables-generated-globalblocks.sql"
		);

		$updater->addExtensionTable(
			'global_block_whitelist',
			"$base/sql/$type/tables-generated-global_block_whitelist.sql"
		);

		switch ( $type ) {
			case 'sqlite':
			case 'mysql':
				// 1.34
				$updater->modifyExtensionField(
					'globalblocks',
					'gb_reason',
					"$base/sql/patch-globalblocks-reason-length.sql"
				);
				$updater->modifyExtensionField(
					'global_block_whitelist',
					'gbw_reason',
					"$base/sql/patch-global_block_whitelist-reason-length.sql"
				);
				$updater->modifyExtensionField(
					'global_block_whitelist',
					'gbw_by_text',
					"$base/sql/patch-global_block_whitelist-use-varbinary.sql"
				);
				break;
		}

		// 1.38
		$updater->addExtensionField(
			'globalblocks',
			'gb_by_central_id',
			"$base/sql/$type/patch-add-gb_by_central_id.sql"
		);
		$updater->addPostDatabaseUpdateMaintenance( PopulateCentralId::class );
		$updater->modifyExtensionField(
			'globalblocks',
			'gb_anon_only',
			"$base/sql/$type/patch-globalblocks-gb_anon_only.sql"
		);

		// 1.39
		$updater->modifyExtensionField(
			'globalblocks',
			'gb_expiry',
			"$base/sql/$type/patch-globalblocks-timestamps.sql"
		);
		if ( $type === 'postgres' ) {
			$updater->modifyExtensionField(
				'global_block_whitelist',
				'gbw_expiry',
				"$base/sql/$type/patch-global_block_whitelist-timestamps.sql"
			);
		}

		$updater->addExtensionField(
			'globalblocks',
			'gb_user_central_id',
			"$base/sql/$type/patch-add-gb_user_central_id.sql"
		);

		$updater->addExtensionField(
			'global_block_whitelist',
			'gbw_central_id',
			"$base/sql/$type/patch-add-gbw_central_id.sql"
		);

		return true;
	}

	/**
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param mixed &$result
	 *
	 * @return bool
	 */
	public function onGetUserPermissionsErrorsExpensive(
		$title, $user, $action, &$result
	) {
		global $wgRequest;
		if ( $action === 'read' || !$this->config->get( 'ApplyGlobalBlocks' ) ) {
			return true;
		}

		$ip = $wgRequest->getIP();
		$blockError = GlobalBlocking::getUserBlockErrors( $user, $ip );
		if ( !empty( $blockError ) ) {
			$result = [ $blockError ];
			return false;
		}
		return true;
	}

	/**
	 * @param User $user
	 * @param string $ip
	 * @param bool &$blocked
	 * @param DatabaseBlock|null &$block
	 *
	 * @return bool
	 */
	public function onUserIsBlockedGlobally( $user, $ip, &$blocked, &$block ) {
		$block = GlobalBlocking::getUserBlock( $user, $ip );
		if ( $block !== null ) {
			$blocked = true;
			return false;
		}
		return true;
	}

	/**
	 * @param array &$users
	 * @param array $data
	 * @param string &$error
	 *
	 * @return bool
	 */
	public function onSpecialPasswordResetOnSubmit( &$users, $data, &$error ) {
		$requestContext = RequestContext::getMain();

		if ( GlobalBlocking::getUserBlockErrors(
			$requestContext->getUser(),
			$requestContext->getRequest()->getIP()
		) ) {
			$error = 'globalblocking-blocked-nopassreset';
			return false;
		}
		return true;
	}

	/**
	 * Creates a link to the global block log
	 * @param array &$msg Message with a link to the global block log
	 * @param string $ip The IP address or username to be checked
	 *
	 * @return bool true
	 */
	public function onOtherBlockLogLink( &$msg, $ip ) {
		$centralId = 0;
		$flags = 0;

		if ( !IPUtils::isIPAddress( $ip ) && $this->config->get( 'GlobalBlockingApplyUsernameBlocks' ) ) {
			$centralId = $this->centralIdLookup->centralIdFromName( $ip );
			$flags |= GlobalBlocking::BLOCK_FLAG_NO_IP;
		}

		$block = GlobalBlocking::getGlobalBlockingBlock( $ip, $centralId, $flags );
		if ( !$block ) {
			// Fast return if not globally blocked
			return true;
		}

		$msg[] = Html::rawElement(
			'span',
			[ 'class' => 'mw-globalblock-loglink plainlinks' ],
			wfMessage( 'globalblocking-loglink', $ip )->parse()
		);
		return true;
	}

	/**
	 * Show global block notice on Special:Contributions.
	 * @param int $userId
	 * @param User $user
	 * @param SpecialPage $sp
	 *
	 * @return bool
	 */
	public function onSpecialContributionsBeforeMainOutput(
		$userId, $user, $sp
	) {
		$name = $user->getName();
		$centralId = 0;
		$flags = 0;

		if ( !IPUtils::isIPAddress( $name ) && $this->config->get( 'GlobalBlockingApplyUsernameBlocks' ) ) {
			$centralId = $this->centralIdLookup->centralIdFromLocalUser( $user );
			$flags |= GlobalBlocking::BLOCK_FLAG_NO_IP;
		}

		$block = GlobalBlocking::getGlobalBlockingBlock( $name, $centralId, $flags );

		if ( $block !== null ) {
			$conds = GlobalBlocking::getGlobalBlockCondition( $block->gb_address, $centralId, $flags );
			$pager = new GlobalBlockListPager( $sp->getContext(), $conds, $sp->getLinkRenderer() );
			$body = $pager->formatRow( $block );

			$out = $sp->getOutput();
			$out->addHTML(
				Html::warningBox(
					$sp->msg( 'globalblocking-contribs-notice', $name )->parseAsBlock() .
					Html::rawElement( 'ul', [], $body ),
					'mw-warning-with-logexcerpt'
				)
			);
		}

		return true;
	}

	/**
	 * Adds a link on Special:Contributions to Special:GlobalBlock for privileged users.
	 * @param int $id User ID
	 * @param Title $title User page title
	 * @param array &$tools Tool links
	 * @param SpecialPage $sp Special page
	 * @return bool|void
	 * @throws MWException
	 */
	public function onContributionsToolLinks(
		$id, $title, &$tools, $sp
	) {
		$user = $sp->getUser();
		$linkRenderer = $sp->getLinkRenderer();
		$ip = $title->getText();
		$centralId = 0;

		if ( $this->permissionManager->userHasRight( $user, 'globalblock' ) ) {
		if ( IPUtils::isIPAddress( $ip ) ) {
			if ( IPUtils::isValidRange( $ip ) ) {
				$target = IPUtils::sanitizeRange( $ip );
			} else {
				$target = IPUtils::sanitizeIP( $ip );
			}

			if ( $target === null ) {
				throw new LogicException( 'IPUtils::sanitizeIP returned null for a valid IP' );
			}
		} elseif ( $this->config->get( 'GlobalBlockingApplyUsernameBlocks' ) ) {
			$target = $title->getDBkey();
			$centralId = $this->centralIdLookup->centralIdFromName( $target );
		} else {
			// Not an IP and this wiki doesn't apply global username blocks
			return;
		}

		if ( GlobalBlocking::getGlobalBlockId( $ip, $centralId ) === 0 ) {
			$tools['globalblock'] = $linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'GlobalBlock', $target ),
				$sp->msg( 'globalblocking-contribs-block' )->text()
			);
		} else {
			$tools['globalblock'] = $linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'GlobalBlock', $target ),
				$sp->msg( 'globalblocking-contribs-modify' )->text()
			);

			$tools['globalunblock'] = $linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'GlobalUnblock', $target ),
				$sp->msg( 'globalblocking-contribs-remove' )->text()
			);
		}
	}
}

	/**
	 * @param array &$updateFields
	 *
	 * @return bool
	 */
	public static function onUserMergeAccountFields( array &$updateFields ) {
		$updateFields[] = [ 'global_block_whitelist', 'gbw_by', 'gbw_by_text' ];

		return true;
	}

	/**
	 * So users can just type in a username for target and it'll work
	 * @param array &$types
	 * @return bool
	 */
	public function onGetLogTypesOnUser( &$types ) {
		$types[] = 'gblblock';

		return true;
	}
}