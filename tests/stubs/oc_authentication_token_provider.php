<?php

namespace OC\Authentication\Token {
	interface IToken extends \JsonSerializable {
		public function getId(): int;

		public function getUid(): string;
	}

	interface IProvider {
		public function getToken(string $tokenId): IToken;

		public function invalidateTokenById(string $uid, int $id);

		public function getTokenById(int $tokenId): IToken;
	}
}
