<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use OCP\App\IAppManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service to interact with the Nextcloud Circles (Teams) app.
 * Provides methods to create circles and manage membership based on OIDC organization claims.
 */
class CirclesService {

	private const CIRCLES_APP_ID = 'circles';

	/** @var \OCA\Circles\CirclesManager|null */
	private $circlesManager = null;

	private bool $circlesManagerInitialized = false;

	public function __construct(
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Check if the Circles app is installed and enabled
	 */
	public function isCirclesEnabled(): bool {
		return $this->appManager->isEnabledForUser(self::CIRCLES_APP_ID);
	}

	/**
	 * Get the CirclesManager instance, or null if Circles is not available
	 */
	private function getCirclesManager(): ?object {
		if ($this->circlesManagerInitialized) {
			return $this->circlesManager;
		}

		$this->circlesManagerInitialized = true;

		if (!$this->isCirclesEnabled()) {
			$this->logger->debug('Circles app is not enabled, Teams provisioning will be skipped');
			return null;
		}

		try {
			// Try to get the CirclesManager from the Circles app
			if (class_exists('\OCA\Circles\CirclesManager')) {
				$this->circlesManager = \OC::$server->get(\OCA\Circles\CirclesManager::class);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Failed to initialize CirclesManager', ['exception' => $e]);
		}

		return $this->circlesManager;
	}

	/**
	 * Start a super session for administrative operations
	 * This is required for creating circles and managing members
	 */
	private function startSuperSession(): bool {
		$manager = $this->getCirclesManager();
		if ($manager === null) {
			return false;
		}

		try {
			$manager->startSuperSession();
			return true;
		} catch (Throwable $e) {
			$this->logger->warning('Failed to start Circles super session', ['exception' => $e]);
			return false;
		}
	}

	/**
	 * Stop the super session
	 */
	private function stopSuperSession(): void {
		$manager = $this->getCirclesManager();
		if ($manager === null) {
			return;
		}

		try {
			$manager->stopSession();
		} catch (Throwable $e) {
			$this->logger->debug('Failed to stop Circles session', ['exception' => $e]);
		}
	}

	/**
	 * Get or create a circle (team) by name
	 *
	 * @param string $circleId Unique identifier for the circle (used for matching)
	 * @param string $displayName Display name for the circle
	 * @return object|null The circle object or null on failure
	 */
	public function getOrCreateCircle(string $circleId, string $displayName): ?object {
		$manager = $this->getCirclesManager();
		if ($manager === null) {
			return null;
		}

		if (!$this->startSuperSession()) {
			return null;
		}

		try {
			// Try to find existing circle by name
			$circle = $this->findCircleByName($circleId);
			if ($circle !== null) {
				$this->logger->debug('Found existing circle: ' . $circleId);
				return $circle;
			}

			// Create new circle
			// Circle types: https://github.com/nextcloud/circles/blob/master/lib/Model/Circle.php
			// CFG_OPEN = 1, CFG_VISIBLE = 2, CFG_REQUEST = 4, CFG_INVITE = 8, etc.
			// Using CFG_VISIBLE (2) + CFG_INVITE (8) = 10 for a visible, invite-only circle
			$circle = $manager->createCircle($displayName, null, false, false);
			$this->logger->info('Created new circle: ' . $displayName);
			return $circle;
		} catch (Throwable $e) {
			$this->logger->warning('Failed to get or create circle: ' . $circleId, ['exception' => $e]);
			return null;
		} finally {
			$this->stopSuperSession();
		}
	}

	/**
	 * Find a circle by its name
	 *
	 * @param string $name The circle name to search for
	 * @return object|null The circle object or null if not found
	 */
	private function findCircleByName(string $name): ?object {
		$manager = $this->getCirclesManager();
		if ($manager === null) {
			return null;
		}

		try {
			// Use probeCircle to find circles matching the name
			$circles = $manager->probeCircles();
			foreach ($circles as $circle) {
				if ($circle->getDisplayName() === $name || $circle->getSingleId() === $name) {
					return $circle;
				}
			}
		} catch (Throwable $e) {
			$this->logger->debug('Failed to find circle by name: ' . $name, ['exception' => $e]);
		}

		return null;
	}

	/**
	 * Add a user to a circle
	 *
	 * @param object $circle The circle to add the user to
	 * @param IUser $user The user to add
	 * @return bool True if successful
	 */
	public function addMember(object $circle, IUser $user): bool {
		$manager = $this->getCirclesManager();
		if ($manager === null) {
			return false;
		}

		if (!$this->startSuperSession()) {
			return false;
		}

		try {
			// Get federated user representation
			$federatedUser = $manager->getFederatedUser($user->getUID(), \OCA\Circles\Model\Member::TYPE_USER);

			// Check if user is already a member
			try {
				$member = $circle->getInitiator();
				if ($member !== null && $member->getUserId() === $user->getUID()) {
					$this->logger->debug('User ' . $user->getUID() . ' is already a member of circle ' . $circle->getDisplayName());
					return true;
				}
			} catch (Throwable $e) {
				// User is not a member, continue to add
			}

			// Add member to circle
			$manager->addMember($circle->getSingleId(), $federatedUser);
			$this->logger->debug('Added user ' . $user->getUID() . ' to circle ' . $circle->getDisplayName());
			return true;
		} catch (Throwable $e) {
			// Check if user is already a member (common case)
			if (str_contains($e->getMessage(), 'already') || str_contains($e->getMessage(), 'member')) {
				$this->logger->debug('User ' . $user->getUID() . ' is already a member of circle ' . $circle->getDisplayName());
				return true;
			}
			$this->logger->warning('Failed to add user ' . $user->getUID() . ' to circle', ['exception' => $e]);
			return false;
		} finally {
			$this->stopSuperSession();
		}
	}

	/**
	 * Remove a user from a circle
	 *
	 * @param object $circle The circle to remove the user from
	 * @param IUser $user The user to remove
	 * @return bool True if successful
	 */
	public function removeMember(object $circle, IUser $user): bool {
		$manager = $this->getCirclesManager();
		if ($manager === null) {
			return false;
		}

		if (!$this->startSuperSession()) {
			return false;
		}

		try {
			// Get federated user representation
			$federatedUser = $manager->getFederatedUser($user->getUID(), \OCA\Circles\Model\Member::TYPE_USER);

			// Remove member from circle
			$manager->removeMember($circle->getSingleId(), $federatedUser);
			$this->logger->debug('Removed user ' . $user->getUID() . ' from circle ' . $circle->getDisplayName());
			return true;
		} catch (Throwable $e) {
			// Check if user was not a member
			if (str_contains($e->getMessage(), 'not') && str_contains($e->getMessage(), 'member')) {
				$this->logger->debug('User ' . $user->getUID() . ' was not a member of circle ' . $circle->getDisplayName());
				return true;
			}
			$this->logger->warning('Failed to remove user ' . $user->getUID() . ' from circle', ['exception' => $e]);
			return false;
		} finally {
			$this->stopSuperSession();
		}
	}

	/**
	 * Get all circles (teams) that a user is a member of
	 *
	 * @param IUser $user The user to get circles for
	 * @return array Array of circle objects
	 */
	public function getUserCircles(IUser $user): array {
		$manager = $this->getCirclesManager();
		if ($manager === null) {
			return [];
		}

		if (!$this->startSuperSession()) {
			return [];
		}

		try {
			$federatedUser = $manager->getFederatedUser($user->getUID(), \OCA\Circles\Model\Member::TYPE_USER);
			$manager->startSession($federatedUser);
			$circles = $manager->probeCircles();
			$manager->stopSession();
			return $circles;
		} catch (Throwable $e) {
			$this->logger->debug('Failed to get user circles for ' . $user->getUID(), ['exception' => $e]);
			return [];
		} finally {
			$this->stopSuperSession();
		}
	}

	/**
	 * Get a circle by its ID or name
	 *
	 * @param string $identifier Circle ID or name
	 * @return object|null The circle object or null if not found
	 */
	public function getCircle(string $identifier): ?object {
		$manager = $this->getCirclesManager();
		if ($manager === null) {
			return null;
		}

		if (!$this->startSuperSession()) {
			return null;
		}

		try {
			// Try to get by single ID first
			try {
				return $manager->getCircle($identifier);
			} catch (Throwable $e) {
				// Not found by ID, try by name
			}

			// Try to find by name
			return $this->findCircleByName($identifier);
		} catch (Throwable $e) {
			$this->logger->debug('Failed to get circle: ' . $identifier, ['exception' => $e]);
			return null;
		} finally {
			$this->stopSuperSession();
		}
	}
}



