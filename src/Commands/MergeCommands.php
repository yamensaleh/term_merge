<?php

declare(strict_types=1);

namespace Drupal\dar_taxonomy_merge\Commands;

use Drupal\dar_taxonomy_merge\Repository\MergeRepository;
use Drupal\dar_taxonomy_merge\Services\TermMerger;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for merging taxonomy terms without an HTTP timeout.
 */
final class MergeCommands extends DrushCommands {

  public function __construct(
    private readonly TermMerger $termMerger,
    private readonly MergeRepository $repository,
  ) {
    parent::__construct();
  }

  /**
   * Merges branch terms into a surviving trunk term.
   *
   * All entities referencing the branch terms are relinked to the trunk,
   * then the branch terms are unpublished. Runs synchronously, so use it for
   * merges that touch many records.
   *
   * @param string $vocabulary
   *   Vocabulary id, e.g. person.
   * @param int $trunk
   *   Term id that survives the merge.
   * @param string $branches
   *   Comma-separated term ids merged into the trunk.
   *
   * @command dar-taxonomy-merge:merge
   * @aliases dtmm
   * @usage drush dar-taxonomy-merge:merge person 123 456,789
   *   Merges terms 456 and 789 into term 123.
   */
  public function merge(string $vocabulary, int $trunk, string $branches): void {
    if (!$this->repository->isMergeable($vocabulary)) {
      $this->logger()->error(dt('Vocabulary "@vid" is not in the mergeable list.', ['@vid' => $vocabulary]));
      return;
    }

    $branchTids = array_values(array_filter(array_map('intval', explode(',', $branches))));
    try {
      $terms = $this->termMerger->validate($trunk, $branchTids);
    }
    catch (\InvalidArgumentException $exception) {
      $this->logger()->error($exception->getMessage());
      return;
    }
    if ($terms['trunk']->bundle() !== $vocabulary) {
      $this->logger()->error(dt('Trunk term @tid is not in vocabulary "@vid".', [
        '@tid' => $trunk,
        '@vid' => $vocabulary,
      ]));
      return;
    }

    $this->output()->writeln(dt(
      'Merging @count terms (@tids) into "@trunk" (@tid).',
      [
        '@count' => count($branchTids),
        '@tids'  => implode(',', $branchTids),
        '@trunk' => $terms['trunk']->label(),
        '@tid'   => $trunk,
      ],
    ));

    $stats = $this->termMerger->merge($trunk, $branchTids, function (string $entityTypeId, string $fieldName, int $done, int $total): void {
      $this->output()->writeln(dt('  @type / @field: @done of @total', [
        '@type'  => $entityTypeId,
        '@field' => $fieldName,
        '@done'  => $done,
        '@total' => $total,
      ]));
    });

    $this->logger()->success(dt(
      'Done: @relinked records relinked, @unpublished terms unpublished.',
      ['@relinked' => $stats['relinked'], '@unpublished' => $stats['unpublished']],
    ));
  }

}
