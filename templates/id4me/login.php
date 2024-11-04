<?php
declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

?>
<form method="post">
	<fieldset class="warning">
		<p>
			<label for="domain" class="infield"><?php p($l->t('Domain')); ?></label>
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']) ?>" />
			<input type="text" name="domain" id="domain"
				   placeholder="<?php p($l->t('your.domain')); ?>" value=""
				   autofocus />
			<input type="submit" id="id4me-submit"
				   class="svg icon-confirm input-button-inline" value="" />
		</p>
	</fieldset>
</form>
