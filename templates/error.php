<?php
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
?>
<div class="guest-box">
    <h2><?php p($_['title']); ?></h2>
    <ul>
        <li>
            <p><?php p($_['message']); ?></p>
        </li>
    </ul>
    <br>
    <p>
        <a class="button primary" href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkTo('', 'index.php')) ?>">
            <?php p($l->t('Back to %s', [$theme->getName()])); ?>
        </a>
    </p>
</div>
