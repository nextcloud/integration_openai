<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$appId = OCA\Watsonx\AppInfo\Application::APP_ID;
\OCP\Util::addScript($appId, $appId . '-adminSettings');
?>

<div id="openai_prefs"></div>
