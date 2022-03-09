<?php
$appId = OCA\UserOIDC\AppInfo\Application::APP_ID;
OCP\Util::addscript($appId, $appId . '-silentLoginResult');
?>
<div id="dummyContent">SILENT LOGIN</div>
