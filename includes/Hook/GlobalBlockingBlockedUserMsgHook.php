<?php

namespace MediaWiki\Extension\GlobalBlocking\Hook;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "GlobalBlockingBlockedUserMsg" to register handlers implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface GlobalBlockingBlockedUserMsgHook {

	/**
	 * Allow extensions to customise the message shown when a username is globally blocked.
	 *
	 * @param string &$errorMsg Translation key of the message shown to the user.
	 * @return bool|void True or no return value to continue or false to abort running remaining hook handlers.
	 */
	public function onGlobalBlockingBlockedUserMsg( string &$errorMsg );

}
