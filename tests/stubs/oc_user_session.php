<?php

namespace OC\User {
	class Session {
		public function completeLogin(\OCP\IUser $user, array $loginDetails, $regenerateSessionId = true): bool {
		}

		public function createRememberMeToken(\OCP\IUser $user) {
		}

		public function createSessionToken(\OCP\IRequest $request, $uid, $loginName, $password = null, $remember = \OC\Authentication\Token\IToken::DO_NOT_REMEMBER) {
		}
	}
}
