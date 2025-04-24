<?php

/**
 * Hooks for ContributionsLeaderboard extension
 *
 * @file
 * @ingroup Extensions
 */

use MediaWiki\Installer\DatabaseUpdater;

class ContributionsLeaderboardHooks
{
  /**
   * Schema updates for the extension
   *
   * @param DatabaseUpdater $updater
   * @return bool
   */
  public static function onLoadExtensionSchemaUpdates(DatabaseUpdater $updater)
  {
    // No database changes needed for this extension
    return true;
  }
}
