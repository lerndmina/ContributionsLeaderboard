<?php

/**
 * Special page for displaying the contributions leaderboard
 *
 * @file
 * @ingroup Extensions
 */

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

class SpecialContributionsLeaderboard extends SpecialPage
{
  // Constants for contribution scoring
  const SCORE_NEW_PAGE = 10;       // Creating a new page
  const SCORE_EDIT_SMALL = 1;      // Small edit (< 100 bytes)
  const SCORE_EDIT_MEDIUM = 3;     // Medium edit (100-1000 bytes)
  const SCORE_EDIT_LARGE = 5;      // Large edit (> 1000 bytes)
  const SCORE_PAGE_PATROLLED = 2;  // Bonus for edits that were patrolled
  const SCORE_CONTENT_MODEL = [    // Different weights for different content types
    'wikitext' => 1.0,
    'javascript' => 1.3,
    'css' => 1.3,
    'json' => 1.2,
    'text' => 0.8,
  ];

  public function __construct()
  {
    parent::__construct('ContributionsLeaderboard');
  }

  /**
   * Show the special page
   *
   * @param string|null $par Parameter passed to the page
   */
  public function execute($par)
  {
    $request = $this->getRequest();
    $output = $this->getOutput();
    $this->setHeaders();

    // Add CSS
    $output->addModules('ext.contributionsLeaderboard');

    // Get parameters
    $limit = $request->getInt('limit', 25);
    $offset = $request->getInt('offset', 0);
    // Remove excludeBots as a parameter but keep it true by default
    $excludeBots = true;
    $timeFrame = $request->getText('timeFrame', 'all');
    // Remove scoreMode as a parameter but keep it true by default
    $scoreMode = true;
    // Get showDebug from URL parameter without permission check
    $showDebug = $request->getBool('showDebug', false);

    // Set title as plain text to avoid deprecation warnings
    $output->setPageTitle($this->msg('contributionsleaderboard-title')->text());
    $output->addWikiTextAsInterface($this->msg('contributionsleaderboard-intro')->text());

    // Add filter form with simplified options
    $this->addFilterForm($limit, $timeFrame);

    // Display the leaderboard
    $this->displayLeaderboard($limit, $offset, $excludeBots, $timeFrame, $scoreMode, $showDebug);
  }

  /**
   * Add filter form to the output
   *
   * @param int $limit Number of users to show
   * @param string $timeFrame Time frame for contributions
   */
  private function addFilterForm($limit, $timeFrame)
  {
    $formDescriptor = [
      'limit' => [
        'type' => 'select',
        'name' => 'limit',
        'default' => $limit,
        'label-message' => 'contributionsleaderboard-limit',
        'options' => [
          $this->msg('contributionsleaderboard-limit-10')->text() => 10,
          $this->msg('contributionsleaderboard-limit-25')->text() => 25,
          $this->msg('contributionsleaderboard-limit-50')->text() => 50,
          $this->msg('contributionsleaderboard-limit-100')->text() => 100,
        ],
      ],
      'timeFrame' => [
        'type' => 'select',
        'name' => 'timeFrame',
        'default' => $timeFrame,
        'label-message' => 'contributionsleaderboard-timeframe',
        'options' => [
          $this->msg('contributionsleaderboard-timeframe-all')->text() => 'all',
          $this->msg('contributionsleaderboard-timeframe-month')->text() => 'month',
          $this->msg('contributionsleaderboard-timeframe-year')->text() => 'year',
        ],
      ],
    ];

    $htmlForm = HTMLForm::factory('ooui', $formDescriptor, $this->getContext());
    $htmlForm->setMethod('get')
      ->setSubmitText($this->msg('contributionsleaderboard-submit')->text())
      ->setWrapperLegendMsg('contributionsleaderboard-filters')
      ->prepareForm()
      ->displayForm(false);
  }

  /**
   * Display the leaderboard
   *
   * @param int $limit Number of users to show
   * @param int $offset Offset for pagination
   * @param bool $excludeBots Whether to exclude bots
   * @param string $timeFrame Time frame for contributions
   * @param bool $scoreMode Whether to use weighted scoring instead of raw edit counts
   * @param bool $showDebug Whether to show debug information
   */
  private function displayLeaderboard($limit, $offset, $excludeBots, $timeFrame, $scoreMode, $showDebug)
  {
    try {
      // Get database service using method available in latest MediaWiki
      $dbService = MediaWikiServices::getInstance()->getDBLoadBalancer();

      try {
        $dbr = method_exists($dbService, 'getPrimaryDatabase') ?
          $dbService->getReplicaDatabase() :
          $dbService->getConnection(DB_REPLICA);
      } catch (Error $e) {
        // If that fails, fall back to getConnection (MediaWiki 1.39+)
        $dbr = $dbService->getConnection(DB_REPLICA);
      }

      if ($scoreMode) {
        // Always use the detailed query for score mode
        $this->displayScoredLeaderboard($dbr, $limit, $offset, $excludeBots, $timeFrame, $showDebug);
      } else if ($timeFrame === 'all' && !$scoreMode) {
        // For 'all time' plain mode, use the simple user table query
        $this->displayUserTableLeaderboard($dbr, $limit, $offset, $excludeBots);
      } else {
        // For time-limited views with plain counting, use revision-based query
        $this->displayRevisionBasedLeaderboard($dbr, $limit, $offset, $excludeBots, $timeFrame);
      }
    } catch (Exception $e) {
      $this->getOutput()->addHTML('<div class="error">' .
        $this->msg('contributionsleaderboard-database-error')->text() .
        ' (' . htmlspecialchars($e->getMessage()) . ')</div>');
    }
  }

  /**
   * Display leaderboard using user_editcount from the user table
   * This is faster but doesn't support time filtering or score mode
   * 
   * @param IDatabase $dbr Database connection
   * @param int $limit Number of users to show
   * @param int $offset Offset for pagination
   * @param bool $excludeBots Whether to exclude bots
   */
  private function displayUserTableLeaderboard($dbr, $limit, $offset, $excludeBots)
  {
    // Get the actual table names with prefixes
    $userTable = $dbr->tableName('user');
    $userGroupsTable = $dbr->tableName('user_groups');

    $tables = ['user'];
    $fields = [
      'user_id',
      'user_name',
      'edit_count' => 'user_editcount'
    ];

    $conds = [];
    $options = [
      'ORDER BY' => 'user_editcount DESC',
      'LIMIT' => $limit,
      'OFFSET' => $offset
    ];

    $join_conds = [];

    // Exclude bots if requested
    if ($excludeBots) {
      $tables[] = 'user_groups';

      // Use fully qualified table names with prefixes
      $join_conds['user_groups'] = [
        'LEFT JOIN',
        "$userGroupsTable.ug_user = $userTable.user_id AND $userGroupsTable.ug_group = " . $dbr->addQuotes('bot')
      ];

      $conds[] = "$userGroupsTable.ug_user IS NULL";
    }

    $res = $dbr->select(
      $tables,
      $fields,
      $conds,
      __METHOD__,
      $options,
      $join_conds
    );

    $this->renderResults($res, $offset, $limit, $excludeBots, 'all');
  }

  /**
   * Display leaderboard using counts from the revision table for time-based filtering
   * 
   * @param IDatabase $dbr Database connection
   * @param int $limit Number of users to show
   * @param int $offset Offset for pagination
   * @param bool $excludeBots Whether to exclude bots
   * @param string $timeFrame Time frame for contributions
   */
  private function displayRevisionBasedLeaderboard($dbr, $limit, $offset, $excludeBots, $timeFrame)
  {
    // Get the actual table names with prefixes
    $userTable = $dbr->tableName('user');
    $userGroupsTable = $dbr->tableName('user_groups');
    $revisionTable = $dbr->tableName('revision');
    $actorTable = $dbr->tableName('actor');

    // Use a simplified query for time-based leaderboards
    $tables = ['revision', 'actor', 'user'];
    $fields = [
      'user_name' => 'user.user_name',
      'user_id' => 'user.user_id',
      'edit_count' => "COUNT($revisionTable.rev_id)"
    ];

    $conds = [];
    $options = [
      'GROUP BY' => ['user.user_id', 'user.user_name'],
      'ORDER BY' => 'edit_count DESC',
      'LIMIT' => $limit,
      'OFFSET' => $offset
    ];

    $join_conds = [
      'actor' => ['INNER JOIN', "$revisionTable.rev_actor = $actorTable.actor_id"],
      'user' => ['INNER JOIN', "$actorTable.actor_user = $userTable.user_id"]
    ];

    // Add time frame condition
    if ($timeFrame !== 'all') {
      $timestamp = null;

      if ($timeFrame === 'month') {
        $timestamp = $dbr->timestamp(time() - 30 * 24 * 60 * 60);
      } elseif ($timeFrame === 'year') {
        $timestamp = $dbr->timestamp(time() - 365 * 24 * 60 * 60);
      }

      if ($timestamp) {
        $conds[] = "$revisionTable.rev_timestamp >= " . $dbr->addQuotes($timestamp);
      }
    }

    // Exclude bots if requested
    if ($excludeBots) {
      $tables[] = 'user_groups';

      // Use fully qualified table names with prefixes
      $join_conds['user_groups'] = [
        'LEFT JOIN',
        "$userGroupsTable.ug_user = $userTable.user_id AND $userGroupsTable.ug_group = " . $dbr->addQuotes('bot')
      ];

      $conds[] = "$userGroupsTable.ug_user IS NULL";
    }

    // Set a reasonable timeout to prevent server issues
    $options['MAX_EXECUTION_TIME'] = 10000; // 10 seconds

    $res = $dbr->select(
      $tables,
      $fields,
      $conds,
      __METHOD__,
      $options,
      $join_conds
    );

    $this->renderResults($res, $offset, $limit, $excludeBots, $timeFrame);
  }

  /**
   * Display leaderboard using a weighted scoring system
   * 
   * @param IDatabase $dbr Database connection
   * @param int $limit Number of users to show
   * @param int $offset Offset for pagination
   * @param bool $excludeBots Whether to exclude bots
   * @param string $timeFrame Time frame for contributions
   * @param bool $showDebug Whether to show debug information
   */
  private function displayScoredLeaderboard($dbr, $limit, $offset, $excludeBots, $timeFrame, $showDebug)
  {
    // DEBUG: Add visible feedback to help diagnose issues
    $debug = "<div style='border:1px solid #ccc; padding:10px; margin:10px 0; background:#f8f9fa;'>";
    $debug .= "<h4>Debug Info</h4>";

    try {
      $debug .= "<p>Getting user IDs for scoring...</p>";
      // Get the IDs of users to calculate scores for
      $userIds = $this->getUserIdsForScoring($dbr, $limit * 3, $offset, $excludeBots);

      $debug .= "<p>Found " . count($userIds) . " user IDs.</p>";

      if (empty($userIds)) {
        $debug .= "<p>ERROR: No user IDs found!</p>";
        $this->getOutput()->addHTML($debug . "</div>");
        $this->getOutput()->addWikiTextAsInterface(
          $this->msg('contributionsleaderboard-no-results')->text()
        );
        return;
      }

      $debug .= "<p>Calculating scores for users...</p>";
      // Calculate contribution scores for these users
      $userScores = $this->calculateSimpleScores($dbr, $userIds, $timeFrame);

      $debug .= "<p>Found scores for " . count($userScores) . " users.</p>";

      if (empty($userScores)) {
        $debug .= "<p>ERROR: No scores calculated!</p>";
        $this->getOutput()->addHTML($debug . "</div>");
        $this->getOutput()->addWikiTextAsInterface(
          $this->msg('contributionsleaderboard-no-results')->text()
        );
        return;
      }

      // Sort by score and take the top results
      arsort($userScores);

      // When using offset, we need to first get all results before slicing
      if ($offset > 0) {
        $userScores = array_slice($userScores, $offset, $limit, true);
      } else {
        $userScores = array_slice($userScores, 0, $limit, true);
      }

      $debug .= "<p>After slicing: " . count($userScores) . " scores.</p>";

      if (empty($userScores)) {
        $debug .= "<p>ERROR: No scores after slicing!</p>";
        $this->getOutput()->addHTML($debug . "</div>");
        $this->getOutput()->addWikiTextAsInterface(
          $this->msg('contributionsleaderboard-no-results')->text()
        );
        return;
      }

      $debug .= "<p>Getting user data...</p>";
      // Get user data for display
      $userData = $this->getUsersData($dbr, array_keys($userScores));

      $debug .= "<p>Found data for " . count($userData) . " users.</p>";

      if (empty($userData)) {
        $debug .= "<p>ERROR: No user data found!</p>";
        $this->getOutput()->addHTML($debug . "</div>");
        $this->getOutput()->addWikiTextAsInterface(
          $this->msg('contributionsleaderboard-no-results')->text()
        );
        return;
      }

      $debug .= "<p>Creating result set...</p>";
      // Create a result set for display
      $res = $this->createScoreResultSet($userData, $userScores);

      $debug .= "<p>Result set created with " . $res->numRows() . " rows.</p>";

      // Close debug div
      $debug .= "</div>";

      // Show debug info
      if ($showDebug) {
        $this->getOutput()->addHTML($debug);
      }

      // Render the results
      $this->renderResults($res, $offset, $limit, $excludeBots, $timeFrame, true, $showDebug);
    } catch (Exception $e) {
      $debug .= "<p>EXCEPTION: " . htmlspecialchars($e->getMessage()) . "</p>";
      $debug .= "<p>Stack trace: <pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre></p>";
      $debug .= "</div>";

      if ($showDebug) {
        $this->getOutput()->addHTML($debug);
      }
      $this->getOutput()->addHTML('<div class="error">' .
        $this->msg('contributionsleaderboard-database-error')->text() .
        ' (' . htmlspecialchars($e->getMessage()) . ')</div>');
    }
  }

  /**
   * A simpler scoring method that's less likely to fail
   * 
   * @param IDatabase $dbr Database connection
   * @param array $userIds Array of user IDs
   * @param string $timeFrame Time frame for contributions
   * @return array Array of user IDs => scores
   */
  private function calculateSimpleScores($dbr, $userIds, $timeFrame)
  {
    if (empty($userIds)) {
      return [];
    }

    // Get table names with prefixes
    $userTable = $dbr->tableName('user');
    $revisionTable = $dbr->tableName('revision');
    $actorTable = $dbr->tableName('actor');

    $scores = [];
    $scoreDetails = []; // Store detailed breakdown of scores for debugging

    try {
      // Get base edit counts from the user table
      $res = $dbr->select(
        'user',
        ['user_id', 'user_name', 'user_editcount'],
        ['user_id' => $userIds],
        __METHOD__
      );

      foreach ($res as $row) {
        // Start with the edit count as a base score
        $baseEditCount = $row->user_editcount ?? 0;
        $scores[$row->user_id] = $baseEditCount;

        // Store details for debugging
        $scoreDetails[$row->user_id] = [
          'user_name' => $row->user_name,
          'base_edit_count' => $baseEditCount,
          'components' => [
            'raw_edit_count' => $baseEditCount,
          ],
          'calculation_steps' => ["Starting with base edit count: $baseEditCount"],
        ];
      }

      // Add wiki contributions - pages created
      try {
        // Get all revisions where rev_parent_id = 0 (indicates a new page creation)
        $tables = ['revision', 'actor'];
        $fields = [
          'user_id' => "$actorTable.actor_user", // Use fully qualified name
          'count' => 'COUNT(*)'
        ];
        $conds = [
          "$actorTable.actor_user" => $userIds, // Use fully qualified name
          "$revisionTable.rev_parent_id" => 0, // New page creation
        ];
        $options = [
          'GROUP BY' => "$actorTable.actor_user", // Use fully qualified name
        ];
        $join_conds = [
          'actor' => ['INNER JOIN', "$revisionTable.rev_actor = $actorTable.actor_id"],
        ];

        // Add time frame condition
        if ($timeFrame !== 'all') {
          if ($timeFrame === 'month') {
            $timestamp = $dbr->timestamp(time() - 30 * 24 * 60 * 60);
          } elseif ($timeFrame === 'year') {
            $timestamp = $dbr->timestamp(time() - 365 * 24 * 60 * 60);
          }

          $conds[] = "$revisionTable.rev_timestamp >= " . $dbr->addQuotes($timestamp);
        }

        $pageCreationRes = $dbr->select(
          $tables,
          $fields,
          $conds,
          __METHOD__,
          $options,
          $join_conds
        );

        foreach ($pageCreationRes as $row) {
          $pageCreationBonus = (int)$row->count * self::SCORE_NEW_PAGE;
          $scores[$row->user_id] += $pageCreationBonus;

          // Update details
          $scoreDetails[$row->user_id]['components']['page_creation'] = $pageCreationBonus;
          $scoreDetails[$row->user_id]['calculation_steps'][] = "Added " . $row->count . " new pages x " .
            self::SCORE_NEW_PAGE . " points = " . $pageCreationBonus . " points";
        }
      } catch (Exception $e) {
        // Page creation calculation failed, continue with other metrics
      }

      // Get revised totals and round to one decimal place
      foreach ($scores as $userId => $score) {
        $finalScore = round($score, 1);
        $scores[$userId] = $finalScore;

        if (isset($scoreDetails[$userId])) {
          $scoreDetails[$userId]['final_score'] = $finalScore;
          $scoreDetails[$userId]['calculation_steps'][] = "Final score: $finalScore";
        }
      }

      // Store score details for debug display
      $this->scoreDetails = $scoreDetails;
    } catch (Exception $e) {
      // Log error but return empty array
      return [];
    }

    return $scores;
  }

  /**
   * Get user IDs for detailed scoring
   *
   * @param IDatabase $dbr Database connection
   * @param int $limit Number of users to fetch
   * @param int $offset Offset for pagination
   * @param bool $excludeBots Whether to exclude bots
   * @return array Array of user IDs
   */
  private function getUserIdsForScoring($dbr, $limit, $offset, $excludeBots)
  {
    // Get the actual table names with prefixes
    $userTable = $dbr->tableName('user');
    $userGroupsTable = $dbr->tableName('user_groups');

    $tables = ['user'];
    $fields = ['user_id'];
    $conds = [];
    $options = [
      'ORDER BY' => 'user_editcount DESC',
      'LIMIT' => $limit,
      'OFFSET' => $offset
    ];
    $join_conds = [];

    // Exclude bots if requested
    if ($excludeBots) {
      $tables[] = 'user_groups';

      // Use fully qualified table names with prefixes
      $join_conds['user_groups'] = [
        'LEFT JOIN',
        "$userGroupsTable.ug_user = $userTable.user_id AND $userGroupsTable.ug_group = " . $dbr->addQuotes('bot')
      ];

      $conds[] = "$userGroupsTable.ug_user IS NULL";
    }

    $res = $dbr->select(
      $tables,
      $fields,
      $conds,
      __METHOD__,
      $options,
      $join_conds
    );

    $userIds = [];
    foreach ($res as $row) {
      $userIds[] = $row->user_id;
    }

    return $userIds;
  }

  /**
   * Calculate contribution scores for a set of users
   *
   * @param IDatabase $dbr Database connection
   * @param array $userIds Array of user IDs
   * @param string $timeFrame Time frame for contributions
   * @return array Array of user IDs => scores
   */
  private function calculateUserScores($dbr, $userIds, $timeFrame)
  {
    if (empty($userIds)) {
      return [];
    }

    // Get table names with prefixes
    $userTable = $dbr->tableName('user');
    $revisionTable = $dbr->tableName('revision');
    $actorTable = $dbr->tableName('actor');
    $pageTable = $dbr->tableName('page');

    // Initialize scores to 0
    $scores = array_fill_keys($userIds, 0);

    try {
      // Check if tables and columns exist to determine which query to use
      $hasPageContentModel = true;
      try {
        // Test if the page_content_model column exists - catches errors for older wikis
        $test = $dbr->selectField('page', 'page_content_model', [], __METHOD__, ['LIMIT' => 1]);
      } catch (Exception $e) {
        $hasPageContentModel = false;
      }

      // Base tables and conditions - simplified for compatibility
      $tables = ['revision', 'actor', 'page'];
      $fields = [
        'user_id' => 'actor.actor_user',
        'rev_len' => "$revisionTable.rev_len",
        'rev_parent_id' => "$revisionTable.rev_parent_id",
      ];

      // Add page content model if available
      if ($hasPageContentModel) {
        $fields['page_content_model'] = "$pageTable.page_content_model";
      }

      $conds = [
        'actor.actor_user' => $userIds,
      ];

      $options = [
        'LIMIT' => 10000, // Reduced limit for better performance
      ];

      $join_conds = [
        'actor' => ['INNER JOIN', "$revisionTable.rev_actor = $actorTable.actor_id"],
        'page' => ['INNER JOIN', "$revisionTable.rev_page = $pageTable.page_id"],
      ];

      // Add time frame condition
      if ($timeFrame !== 'all') {
        if ($timeFrame === 'month') {
          $timestamp = $dbr->timestamp(time() - 30 * 24 * 60 * 60);
        } elseif ($timeFrame === 'year') {
          $timestamp = $dbr->timestamp(time() - 365 * 24 * 60 * 60);
        }

        $conds[] = "$revisionTable.rev_timestamp >= " . $dbr->addQuotes($timestamp);
      }

      // Execute query
      $res = $dbr->select(
        $tables,
        $fields,
        $conds,
        __METHOD__,
        $options,
        $join_conds
      );

      // Process each revision to calculate score
      foreach ($res as $row) {
        $score = 0;

        // Base score based on edit size
        if ($row->rev_parent_id == 0) {
          // New page creation
          $score += self::SCORE_NEW_PAGE;
        } else {
          // Edit to existing page - score based on size
          $editSize = $row->rev_len;
          if ($editSize < 100) {
            $score += self::SCORE_EDIT_SMALL;
          } elseif ($editSize < 1000) {
            $score += self::SCORE_EDIT_MEDIUM;
          } else {
            $score += self::SCORE_EDIT_LARGE;
          }
        }

        // Apply content model multiplier if available
        if (
          $hasPageContentModel && isset($row->page_content_model) &&
          isset(self::SCORE_CONTENT_MODEL[$row->page_content_model])
        ) {
          $score *= self::SCORE_CONTENT_MODEL[$row->page_content_model];
        }

        // Add to user's score
        $scores[$row->user_id] += $score;
      }
    } catch (Exception $e) {
      // If scoring calculation fails, use a fallback based on edit counts
      $res = $dbr->select(
        'user',
        ['user_id', 'user_editcount'],
        ['user_id' => $userIds],
        __METHOD__
      );

      foreach ($res as $row) {
        // Simple score based on edit count - at least we show something
        $scores[$row->user_id] = $row->user_editcount;
      }
    }

    return $scores;
  }

  /**
   * Get user data (names) for the given user IDs
   *
   * @param IDatabase $dbr Database connection
   * @param array $userIds Array of user IDs
   * @return array Array of user data
   */
  private function getUsersData($dbr, $userIds)
  {
    if (empty($userIds)) {
      return [];
    }

    // Get proper table name with prefix
    $userTable = $dbr->tableName('user');

    $res = $dbr->select(
      'user',
      ['user_id', 'user_name'],
      ['user_id' => $userIds],
      __METHOD__
    );

    $userData = [];
    foreach ($res as $row) {
      $userData[$row->user_id] = [
        'user_name' => $row->user_name,
      ];
    }

    return $userData;
  }

  /**
   * Create a result set object from user data and scores
   *
   * @param array $userData Array of user data
   * @param array $userScores Array of user scores
   * @return object A result set-like object
   */
  private function createScoreResultSet($userData, $userScores)
  {
    $rows = [];

    foreach ($userScores as $userId => $score) {
      if (isset($userData[$userId])) {
        $row = new stdClass();
        $row->user_id = $userId;
        $row->user_name = $userData[$userId]['user_name'];
        $row->edit_count = round($score, 1); // Round score to one decimal place
        $rows[] = $row;
      }
    }

    // Create a result wrapper-like object implementing Iterator and Countable
    $result = new class($rows) implements Iterator, Countable {
      private $rows;
      private $position = 0;

      public function __construct($rows)
      {
        $this->rows = $rows;
      }

      public function numRows()
      {
        return count($this->rows);
      }

      public function count(): int
      {
        return count($this->rows);
      }

      public function current(): mixed
      {
        return $this->rows[$this->position] ?? null;
      }

      public function next(): void
      {
        $this->position++;
      }

      public function rewind(): void
      {
        $this->position = 0;
      }

      public function valid(): bool
      {
        return isset($this->rows[$this->position]);
      }

      public function key(): mixed
      {
        return $this->position;
      }
    };

    $result->rewind();

    // Debug - let's print the count of rows to make sure it's working
    error_log('createScoreResultSet created ' . count($rows) . ' rows');

    return $result;
  }

  /**
   * Render result set as HTML table
   *
   * @param \Wikimedia\Rdbms\IResultWrapper $res Database result
   * @param int $offset Offset for pagination
   * @param int $limit Number of users to show
   * @param bool $excludeBots Whether to exclude bots
   * @param string $timeFrame Time frame for contributions
   * @param bool $scoreMode Whether results are from score mode
   * @param bool $showDebug Whether to show debug information
   */
  private function renderResults($res, $offset, $limit, $excludeBots, $timeFrame, $scoreMode = false, $showDebug = false)
  {
    // Only show result debug if debug mode is enabled
    if ($showDebug) {
      $resultDebug = "<div style='border:1px solid #ddd; padding:10px; margin:10px 0; background:#f0f0f0;'>";
      $resultDebug .= "<h4>Results Debug</h4>";

      if ($res->numRows() === 0) {
        $resultDebug .= "<p>Result set contains 0 rows</p></div>";
        $this->getOutput()->addHTML($resultDebug);
        $this->getOutput()->addWikiTextAsInterface(
          $this->msg('contributionsleaderboard-no-results')->text()
        );
        return;
      }

      // Check the content of the first row to debug
      $res->rewind(); // Reset position to first row
      $firstRow = $res->current();
      $resultDebug .= "<p>First row data: ";
      if ($firstRow) {
        $resultDebug .= "user_id: " . htmlspecialchars($firstRow->user_id ?? 'N/A') . ", ";
        $resultDebug .= "user_name: " . htmlspecialchars($firstRow->user_name ?? 'N/A') . ", ";
        $resultDebug .= "edit_count: " . htmlspecialchars($firstRow->edit_count ?? 'N/A');
      } else {
        $resultDebug .= "No data in first row";
      }
      $resultDebug .= "</p></div>";

      $this->getOutput()->addHTML($resultDebug);

      // Reset position again for rendering
      $res->rewind();
    } else {
      // If not in debug mode, but result set is empty, show no results message
      if ($res->numRows() === 0) {
        $this->getOutput()->addWikiTextAsInterface(
          $this->msg('contributionsleaderboard-no-results')->text()
        );
        return;
      }
    }

    // Build the table
    $html = Html::openElement('table', ['class' => 'wikitable sortable contributionsleaderboard']);

    // Table header
    $html .= Html::openElement('thead');
    $html .= Html::openElement('tr');
    $html .= Html::element('th', [], $this->msg('contributionsleaderboard-rank')->text());
    $html .= Html::element('th', [], $this->msg('contributionsleaderboard-username')->text());

    // Change column header based on score mode
    if ($scoreMode) {
      $html .= Html::element('th', [], $this->msg('contributionsleaderboard-score')->text());
    } else {
      $html .= Html::element('th', [], $this->msg('contributionsleaderboard-editcount')->text());
    }

    $html .= Html::closeElement('tr');
    $html .= Html::closeElement('thead');

    // Table body
    $html .= Html::openElement('tbody');

    $rank = $offset + 1;

    // For debugging render the values directly to see what happens
    foreach ($res as $row) {
      $html .= Html::openElement('tr');
      $html .= Html::element('td', [], $rank);

      // Check if user_name exists before using it
      if (isset($row->user_name)) {
        $userPage = Title::newFromText($row->user_name, NS_USER);
        $html .= Html::rawElement(
          'td',
          [],
          Html::rawElement(
            'a',
            ['href' => $userPage->getLocalURL()],
            htmlspecialchars($row->user_name)
          )
        );
      } else {
        $html .= Html::element('td', [], 'Unknown User');
      }

      // Make sure we have an edit_count property
      $score = $row->edit_count ?? 0;
      $html .= Html::element('td', [], $score);

      $html .= Html::closeElement('tr');

      // Add detailed score breakdown if debug is enabled and this is score mode
      if ($showDebug && $scoreMode && isset($row->user_id) && isset($this->scoreDetails[$row->user_id])) {
        $details = $this->scoreDetails[$row->user_id];
        $html .= Html::openElement('tr', ['class' => 'score-details']);
        $html .= Html::openElement('td', ['colspan' => '3', 'style' => 'background-color: #f8f8f8; padding: 10px; font-size: 0.9em;']);

        $html .= '<h4>Score breakdown for ' . htmlspecialchars($row->user_name) . ':</h4>';
        $html .= '<ul>';

        // Show components
        if (isset($details['components'])) {
          foreach ($details['components'] as $component => $value) {
            $html .= '<li><strong>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $component))) .
              ':</strong> ' . htmlspecialchars($value) . ' points</li>';
          }
        }

        // Show calculation steps
        if (isset($details['calculation_steps'])) {
          $html .= '<li><strong>Calculation steps:</strong><ol>';
          foreach ($details['calculation_steps'] as $step) {
            $html .= '<li>' . htmlspecialchars($step) . '</li>';
          }
          $html .= '</ol></li>';
        }

        $html .= '</ul>';

        $html .= Html::closeElement('td');
        $html .= Html::closeElement('tr');
      }

      $rank++;
    }

    $html .= Html::closeElement('tbody');
    $html .= Html::closeElement('table');

    // Add pagination
    $html .= $this->getPaginationLinks($limit, $offset, $excludeBots, $timeFrame, $scoreMode, $showDebug);

    $this->getOutput()->addHTML($html);
  }

  /**
   * Generate pagination links
   *
   * @param int $limit Number of users to show
   * @param int $offset Offset for pagination
   * @param bool $excludeBots Whether to exclude bots
   * @param string $timeFrame Time frame for contributions
   * @param bool $scoreMode Whether to use weighted scoring instead of raw edit counts
   * @param bool $showDebug Whether to show debug information
   * @return string HTML for pagination links
   */
  private function getPaginationLinks($limit, $offset, $excludeBots, $timeFrame, $scoreMode = false, $showDebug = false)
  {
    $html = Html::openElement('div', ['class' => 'contributionsleaderboard-pagination']);

    // Previous link
    if ($offset > 0) {
      $prevOffset = max(0, $offset - $limit);
      $html .= Html::rawElement(
        'a',
        [
          'href' => $this->getPageTitle()->getLocalURL([
            'limit' => $limit,
            'offset' => $prevOffset,
            'excludeBots' => $excludeBots ? '1' : '0',
            'timeFrame' => $timeFrame,
            'scoreMode' => $scoreMode ? '1' : '0',
            'showDebug' => $showDebug ? '1' : '0',
          ]),
          'class' => 'mw-prevlink',
        ],
        $this->msg('contributionsleaderboard-prev')->text()
      );
    } else {
      $html .= Html::element(
        'span',
        ['class' => 'mw-prevlink mw-disabled'],
        $this->msg('contributionsleaderboard-prev')->text()
      );
    }

    // Next link
    $html .= ' ' . Html::rawElement(
      'a',
      [
        'href' => $this->getPageTitle()->getLocalURL([
          'limit' => $limit,
          'offset' => $offset + $limit,
          'excludeBots' => $excludeBots ? '1' : '0',
          'timeFrame' => $timeFrame,
          'scoreMode' => $scoreMode ? '1' : '0',
          'showDebug' => $showDebug ? '1' : '0',
        ]),
        'class' => 'mw-nextlink',
      ],
      $this->msg('contributionsleaderboard-next')->text()
    );

    $html .= Html::closeElement('div');

    return $html;
  }

  /**
   * Get the group name for this special page
   *
   * @return string
   */
  protected function getGroupName()
  {
    return 'wiki';
  }
}
