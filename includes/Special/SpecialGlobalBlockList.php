<?php

namespace MediaWiki\Extension\GlobalBlocking\Special;

use CentralIdLookup;
use DerivativeContext;
use Html;
use HTMLForm;
use MediaWiki\Block\BlockUtils;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Extension\GlobalBlocking\GlobalBlocking;
use MediaWiki\User\UserNameUtils;
use SpecialPage;
use Wikimedia\IPUtils;

class SpecialGlobalBlockList extends SpecialPage {
	/** @var string|null */
	protected $target;

	/** @var array */
	protected $options;

	/** @var BlockUtils */
	private $blockUtils;

	/** @var CentralIdLookup */
	private $centralIdLookup;

	/** @var UserNameUtils */
	private $userNameUtils;

	/**
	 * @param BlockUtils $blockUtils
	 * @param CentralIdLookup $centralIdLookup
	 * @param UserNameUtils $userNameUtils
	 */
	public function __construct(
		BlockUtils $blockUtils,
		CentralIdLookup $centralIdLookup,
		UserNameUtils $userNameUtils
	) {
		parent::__construct( 'GlobalBlockList' );

		$this->blockUtils = $blockUtils;
		$this->centralIdLookup = $centralIdLookup;
		$this->userNameUtils = $userNameUtils;
	}

	/**
	 * @param string $par Parameters of the URL, probably the IP being actioned
	 * @return void
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader( 'globalblocking-list-intro' );
		$this->addHelpLink( 'Extension:GlobalBlocking' );

		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'globalblocking-list' ) );
		$out->setSubtitle( GlobalBlocking::buildSubtitleLinks( $this ) );
		$out->setArticleRelated( false );
		$out->disableClientCache();

		$this->loadParameters( $par );
		$this->showForm();

		// Validate search target. If it is invalid, no need to build the pager.
		$userTarget = $this->userNameUtils->getCanonical( $this->target );
		if ( $this->target && !IPUtils::isIPAddress( $this->target ) && !$userTarget ) {
			$out->wrapWikiMsg(
				"<div class='error'>\n$1\n</div>",
				[ 'globalblocking-list-ipinvalid', $this->target ]
			);
			return;
		}

		if ( $userTarget ) {
			$this->target = $userTarget;
		}

		$this->showList();
	}

	/**
	 * @param string|null $par Parameter from the URL, may be null or a string (probably an IP)
	 * that was inserted
	 */
	protected function loadParameters( $par ) {
		$request = $this->getRequest();
		$ip = trim( $request->getText( 'target', $par ) );
		if ( $ip !== '' ) {
			$ip = IPUtils::isIPAddress( $ip )
				? IPUtils::sanitizeRange( $ip )
				: $ip;
		}
		$this->target = $ip;

		$this->options = $request->getArray( 'wpOptions', [] );
	}

	protected function showForm() {
		$fields = [
			'target' => [
				'type' => 'text',
				'name' => 'target',
				'id' => 'mw-globalblocking-search-target',
				'label-message' => 'globalblocking-search-ip',
				'default' => $this->target,
			],
			'Options' => [
				'type' => 'multiselect',
				'options-messages' => [
					'globalblocking-list-tempblocks' => 'tempblocks',
					'globalblocking-list-indefblocks' => 'indefblocks',
					'globalblocking-list-addressblocks' => 'addressblocks',
					'globalblocking-list-rangeblocks' => 'rangeblocks',
					'globalblocking-list-userblocks' => 'userblocks',
				],
				'flatlist' => true,
			],
		];
		$context = new DerivativeContext( $this->getContext() );
		// remove subpage
		$context->setTitle( $this->getPageTitle() );

		$form = HTMLForm::factory( 'ooui', $fields, $context );
		$form->setMethod( 'get' )
			->setName( 'globalblocklist-search' )
			->setSubmitTextMsg( 'globalblocking-search-submit' )
			->setWrapperLegendMsg( 'globalblocking-search-legend' )
			->prepareForm()
			->displayForm( false );
	}

	protected function showList() {
		$out = $this->getOutput();
		$dbr = GlobalBlocking::getGlobalBlockingDatabase( DB_REPLICA );

		// Build a list of blocks.
		$conds = [];

		if ( $this->target !== '' ) {
			[ $target, $type ] = $this->blockUtils->parseBlockTarget( $this->target );

			switch ( $type ) {
				case DatabaseBlock::TYPE_USER:
					// $target might be a UserIdentityValue, but we need a string for all of these calls
					$target = (string)$target;
					$centralId = $this->centralIdLookup->centralIdFromName( $target );
					$conds = GlobalBlocking::getGlobalBlockCondition( $target, $centralId );
					break;
				case DatabaseBlock::TYPE_IP:
					$conds = GlobalBlocking::getGlobalBlockCondition( $target, 0 );
					break;
				case DatabaseBlock::TYPE_RANGE:
					$conds = [ 'gb_address' => $target ];
					break;
			}
		}

		if ( $conds === false ) {
			$this->noResults();
			return;
		}

		$hideUser = in_array( 'userblocks', $this->options );
		$hideIP = in_array( 'addressblocks', $this->options );
		$hideRange = in_array( 'rangeblocks', $this->options );

		if ( $hideUser && $hideIP && $hideRange ) {
			$this->noResults();
			return;
		}

		if ( $hideUser ) {
			$conds[] = 'gb_user_central_id IS NULL';
		}

		if ( $hideIP ) {
			$conds[] = '(gb_user_central_id IS NOT NULL OR gb_range_end > gb_range_start)';
		}

		if ( $hideRange ) {
			$conds[] = 'gb_range_end = gb_range_start';
		}

		$hideTemp = in_array( 'tempblocks', $this->options );
		$hideIndef = in_array( 'indefblocks', $this->options );
		if ( $hideTemp && $hideIndef ) {
			$this->noResults();
			return;
		} elseif ( $hideTemp ) {
			$conds[] = 'gb_expiry = ' . $dbr->addQuotes( $dbr->getInfinity() );
		} elseif ( $hideIndef ) {
			$conds[] = 'gb_expiry != ' . $dbr->addQuotes( $dbr->getInfinity() );
		}

		$pager = new GlobalBlockListPager( $this->getContext(), $conds, $this->getLinkRenderer() );
		$body = $pager->getBody();
		if ( $body != '' ) {
			$out->addHTML(
				$pager->getNavigationBar() .
				Html::rawElement( 'ul', [], $body ) .
				$pager->getNavigationBar()
			);
		} else {
			$this->noResults();
		}
	}

	/**
	 * Display an error when no results are found for those parameters
	 * @return void
	 */
	private function noResults() {
		$this->getOutput()->wrapWikiMsg(
			"<div class='mw-globalblocking-noresults'>\n$1</div>\n",
			[ 'globalblocking-list-noresults' ]
		);
	}

	protected function getGroupName() {
		return 'users';
	}
}
