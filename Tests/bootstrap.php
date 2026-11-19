<?php

declare(strict_types=1);

/*
 * Unit/integration test bootstrap.
 *
 * TYPO3 creates directories with $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'];
 * without it GeneralUtility::mkdir_deep() uses mode 0000 and nested directories cannot be
 * created (unnoticed when the tests run as root, failing in CI). Set the TYPO3 defaults.
 */
$GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'] ??= '2775';
$GLOBALS['TYPO3_CONF_VARS']['SYS']['fileCreateMask'] ??= '0664';

require dirname(__DIR__) . '/vendor/autoload.php';
