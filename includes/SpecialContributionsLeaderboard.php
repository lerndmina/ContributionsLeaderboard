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
    $excludeBots = $request->getBool('excludeBots', true);
    $timeFrame = $request->getText('timeFrame', 'all');

    // Set title as plain text to avoid deprecation warnings
    $output->setPageTitle($this->msg('contributionsleaderboard-title')->text());
    $output->addWikiTextAsInterface($this->msg('contributionsleaderboard-intro')->text());

    // Add filter form
    $this->addFilterForm($excludeBots, $limit, $timeFrame);

    // Display the leaderboard
    $this->displayLeaderboard($limit, $offset, $excludeBots, $timeFrame);
  }

  /**
   * Add filter form to the output
   *
   * @param bool $excludeBots Whether to exclude bots
   * @param int $limit Number of users to show
   * @param string $timeFrame Time frame for contributions
   */
  private function addFilterForm($excludeBots, $limit, $timeFrame)
  {
    $formDescriptor = [
      'excludeBots' => [
        'type' => 'check',
        'name' => 'excludeBots',
        'default' => $excludeBots,
        'label-message' => 'contributionsleaderboard-exclude-bots',
      ],
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
   */
  private function displayLeaderboard($limit, $offset, $excludeBots, $timeFrame)
  {
    try {
      // Get database service using method available in latest MediaWiki
      $dbService = MediaWikiServices::getInstance()->getDBLoadBalancer();

      // First, try with getPrimaryDatabase (MediaWiki 1.41+)
      try {
        $dbr = method_exists($dbService, 'getPrimaryDatabase') ?
          $dbService->getReplicaDatabase() :
          $dbService->getConnection(DB_REPLICA);
      } catch (Error $e) {
        // If that fails, fall back to getConnection (MediaWiki 1.39+)
        $dbr = $dbService->getConnection(DB_REPLICA);
      }

      if ($timeFrame === 'all') {
        // For 'all time', use the simple user table query
        $this->displayUserTableLeaderboard($dbr, $limit, $offset, $excludeBots);
      } else {
        // For time-limited views, we need to query the revision table
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
   * This is faster but doesn't support time filtering
   * 
   * @param IDatabase $dbr Database connection
   * @param int $limit Number of users to show
   * @param int $offset Offset for pagination
   * @param bool $excludeBots Whether to exclude bots
   */
  private function displayUserTableLeaderboard($dbr, $limit, $offset, $excludeBots)
  {
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
      $join_conds['user_groups'] = [
        'LEFT JOIN',
        [
          'user_groups.ug_user = user.user_id',
          'user_groups.ug_group' => 'bot',
        ],
      ];
      $conds[] = 'user_groups.ug_user IS NULL';
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
    // Use a simplified query for time-based leaderboards
    $tables = ['revision', 'actor', 'user'];
    $fields = [
      'user_name' => 'user.user_name',
      'user_id' => 'user.user_id',
      'edit_count' => 'COUNT(revision.rev_id)'
    ];

    $conds = [];
    $options = [
      'GROUP BY' => ['user.user_id', 'user.user_name'],
      'ORDER BY' => 'edit_count DESC',
      'LIMIT' => $limit,
      'OFFSET' => $offset
    ];

    $join_conds = [
      'actor' => ['INNER JOIN', 'revision.rev_actor = actor.actor_id'],
      'user' => ['INNER JOIN', 'actor.actor_user = user.user_id']
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
        $conds[] = 'revision.rev_timestamp >= ' . $dbr->addQuotes($timestamp);
      }
    }

    // Exclude bots if requested
    if ($excludeBots) {
      $tables[] = 'user_groups';
      $join_conds['user_groups'] = [
        'LEFT JOIN',
        [
          'user_groups.ug_user = user.user_id',
          'user_groups.ug_group' => 'bot',
        ],
      ];
      $conds[] = 'user_groups.ug_user IS NULL';
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
   * Render result set as HTML table
   *
   * @param \Wikimedia\Rdbms\IResultWrapper $res Database result
   * @param int $offset Offset for pagination
   * @param int $limit Number of users to show
   * @param bool $excludeBots Whether to exclude bots
   * @param string $timeFrame Time frame for contributions
   */
  private function renderResults($res, $offset, $limit, $excludeBots, $timeFrame)
  {
    if ($res->numRows() === 0) {
      $this->getOutput()->addWikiTextAsInterface(
        $this->msg('contributionsleaderboard-no-results')->text()
      );
      return;
    }

    // Build the table
    $html = Html::openElement('table', ['class' => 'wikitable sortable contributionsleaderboard']);

    // Table header
    $html .= Html::openElement('thead');
    $html .= Html::openElement('tr');
    $html .= Html::element('th', [], $this->msg('contributionsleaderboard-rank')->text());
    $html .= Html::element('th', [], $this->msg('contributionsleaderboard-username')->text());
    $html .= Html::element('th', [], $this->msg('contributionsleaderboard-editcount')->text());
    $html .= Html::closeElement('tr');
    $html .= Html::closeElement('thead');

    // Table body
    $html .= Html::openElement('tbody');

    $rank = $offset + 1;
    foreach ($res as $row) {
      $html .= Html::openElement('tr');
      $html .= Html::element('td', [], $rank);

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

      $html .= Html::element('td', [], $row->edit_count);
      $html .= Html::closeElement('tr');

      $rank++;
    }

    $html .= Html::closeElement('tbody');
    $html .= Html::closeElement('table');

    // Add pagination
    $html .= $this->getPaginationLinks($limit, $offset, $excludeBots, $timeFrame);

    $this->getOutput()->addHTML($html);
  }

  /**
   * Generate pagination links
   *
   * @param int $limit Number of users to show
   * @param int $offset Offset for pagination
   * @param bool $excludeBots Whether to exclude bots
   * @param string $timeFrame Time frame for contributions
   * @return string HTML for pagination links
   */
  private function getPaginationLinks($limit, $offset, $excludeBots, $timeFrame)
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
