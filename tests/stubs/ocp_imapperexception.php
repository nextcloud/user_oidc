<?php

// IMapperException didn't extend Throwable until 27
// so we need this to test against stable25 and stable26
namespace OCP\AppFramework\Db {
	interface IMapperException extends \Throwable {
	}
}
