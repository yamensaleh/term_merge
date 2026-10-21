<?php

declare(strict_types=1);

namespace Drupal\dar_taxonomy_merge\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Relinks every taxonomy_term reference from merged terms to the survivor.
 *
 * Fields are discovered generically: any storage whose `target_type` is
 * taxonomy_term is rewritten, whatever the entity type or field plugin
 * (entity_reference, typed_relation, entity_reference_revisions, custom).
 * Only the target column is touched, so sub-values such as a typed relation's
 * rel_type survive the move; items that become identical are deduplicated.
 *
 * The service offers a synchronous merge() for CLI use and static batch
 * callbacks for the UI. Batch operations each handle a small chunk of
 * entities so no HTTP request approaches the proxy timeout.
 */
final class TermMerger {

  use StringTranslationTrait;

  /**
   * Logger channel name.
   */
  private const CHANNEL = 'dar_taxonomy_merge';

  /**
   * Config name carrying the tuning values.
   */
  private const SETTINGS = 'dar_taxonomy_merge.settings';

  /**
   * Chunk size used when settings do not provide one.
   */
  private const FALLBACK_CHUNK = 10;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly MessengerInterface $messenger,
    private readonly ConfigFactoryInterface $configFactory,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Loads and validates the trunk and branch terms of a merge.
   *
   * @param int $trunkTid
   *   Surviving term id.
   * @param list<int> $branchTids
   *   Term ids merged into the trunk.
   *
   * @return array{trunk: \Drupal\taxonomy\TermInterface, branches: list<\Drupal\taxonomy\TermInterface>}
   *   The loaded terms.
   *
   * @throws \InvalidArgumentException
   *   When a term is missing, duplicated, or spans vocabularies.
   */
  public function validate(int $trunkTid, array $branchTids): array {
    $branchTids = array_values(array_unique(array_map('intval', $branchTids)));
    if ($branchTids === [] || in_array($trunkTid, $branchTids, TRUE)) {
      throw new \InvalidArgumentException('Branch terms must differ from the trunk term.');
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $trunk   = $storage->load($trunkTid);
    if (!$trunk instanceof TermInterface) {
      throw new \InvalidArgumentException(sprintf('Trunk term %d does not exist.', $trunkTid));
    }

    $branches = [];
    foreach ($branchTids as $tid) {
      $term = $storage->load($tid);
      if (!$term instanceof TermInterface) {
        throw new \InvalidArgumentException(sprintf('Branch term %d does not exist.', $tid));
      }
      if ($term->bundle() !== $trunk->bundle()) {
        throw new \InvalidArgumentException('All merged terms must belong to the trunk vocabulary.');
      }
      $branches[] = $term;
    }
    return ['trunk' => $trunk, 'branches' => $branches];
  }

  /**
   * Merges branch terms into the trunk synchronously.
   *
   * Intended for CLI (Drush) where no HTTP timeout applies; the UI uses the
   * batch callbacks over the same internals.
   *
   * @param int $trunkTid
   *   Surviving term id.
   * @param list<int> $branchTids
   *   Term ids merged into the trunk and then unpublished.
   * @param ?callable $progress
   *   Optional callback receiving (entity_type_id, field_name, done, total).
   *
   * @return array{relinked: int, unpublished: int}
   *   Entities resaved and terms unpublished.
   */
  public function merge(int $trunkTid, array $branchTids, ?callable $progress = NULL): array {
    $this->validate($trunkTid, $branchTids);

    $relinked = 0;
    foreach ($this->targetFieldNames() as $entity_type_id => $field_names) {
      foreach ($field_names as $field_name) {
        $ids = $this->referencingEntityIds($entity_type_id, $field_name, $branchTids);
        $done = 0;
        foreach (array_chunk($ids, $this->chunkSize()) as $chunk) {
          $relinked += $this->relinkChunk($entity_type_id, $field_name, $chunk, $trunkTid, $branchTids);
          $done += count($chunk);
          if ($progress !== NULL) {
            $progress($entity_type_id, $field_name, $done, count($ids));
          }
        }
      }
    }

    $unpublished = $this->unpublishBranches($branchTids);
    $this->loggerFactory->get(self::CHANNEL)->info(
      'Merged terms @branches into @trunk: @count entities relinked.',
      ['@branches' => implode(',', $branchTids), '@trunk' => $trunkTid, '@count' => $relinked],
    );
    return ['relinked' => $relinked, 'unpublished' => $unpublished];
  }

  /**
   * Configured number of entities handled per batch operation.
   */
  public function chunkSize(): int {
    $size = (int) ($this->configFactory->get(self::SETTINGS)->get('batch_chunk_size') ?? self::FALLBACK_CHUNK);
    return max(1, $size);
  }

  /**
   * Field names that target taxonomy_term, keyed by entity type.
   *
   * @return array<string, list<string>>
   *   Entity type id to field names.
   */
  public function targetFieldNames(): array {
    $targets = [];
    foreach ($this->entityFieldManager->getFieldMap() as $entity_type_id => $fields) {
      $storages = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
      foreach ($fields as $field_name => $field_info) {
        $storage = $storages[$field_name] ?? NULL;
        if ($storage !== NULL && $storage->getSetting('target_type') === 'taxonomy_term') {
          $targets[$entity_type_id][] = $field_name;
        }
      }
    }
    return $targets;
  }

  /**
   * Entity ids of one type whose field references any of the terms.
   *
   * @param string $entityTypeId
   *   Entity type id.
   * @param string $fieldName
   *   Field machine name.
   * @param list<int> $tids
   *   Term ids.
   *
   * @return list<int|string>
   *   Referencing entity ids.
   */
  public function referencingEntityIds(string $entityTypeId, string $fieldName, array $tids): array {
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    return array_values($storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($fieldName . '.target_id', $tids, 'IN')
      ->execute());
  }

  /**
   * Rewrites branch term references to the trunk on a set of entities.
   *
   * @param string $entityTypeId
   *   Entity type id.
   * @param string $fieldName
   *   Field machine name.
   * @param list<int|string> $entityIds
   *   Entity ids to inspect.
   * @param int $trunkTid
   *   Surviving term id.
   * @param list<int> $branchTids
   *   Merged term ids.
   *
   * @return int
   *   Entities resaved because their field changed.
   */
  public function relinkChunk(string $entityTypeId, string $fieldName, array $entityIds, int $trunkTid, array $branchTids): int {
    // Strict matching below needs ints; entity ids arrive as strings.
    $branchTids = array_map('intval', $branchTids);
    $moved      = 0;
    $entities   = $this->entityTypeManager->getStorage($entityTypeId)->loadMultiple($entityIds);
    foreach ($entities as $entity) {
      if ($entity instanceof EntityInterface && $this->relinkEntity($entity, $fieldName, $branchTids, $trunkTid)) {
        $moved++;
      }
    }
    return $moved;
  }

  /**
   * Rewrites one entity's field items from branch terms to the trunk.
   *
   * Only the target column is rewritten. Values that become identical after
   * the swap (including an item already pointing at the trunk) are removed,
   * keeping the first occurrence.
   *
   * @return bool
   *   TRUE when the entity was resaved.
   */
  private function relinkEntity(EntityInterface $entity, string $fieldName, array $branchTids, int $trunkTid): bool {
    if (!$entity->hasField($fieldName)) {
      return FALSE;
    }

    $values  = $entity->get($fieldName)->getValue();
    $changed = FALSE;
    $seen    = [];
    $kept    = [];
    foreach ($values as $item) {
      if (isset($item['target_id'])) {
        $item['target_id'] = (int) $item['target_id'];
        if (in_array($item['target_id'], $branchTids, TRUE)) {
          $item['target_id'] = $trunkTid;
          $changed           = TRUE;
        }
      }
      $signature = serialize($item);
      if (isset($seen[$signature])) {
        $changed = TRUE;
        continue;
      }
      $seen[$signature] = TRUE;
      $kept[]           = $item;
    }

    if (!$changed) {
      return FALSE;
    }
    $entity->set($fieldName, $kept);
    $entity->save();
    return TRUE;
  }

  /**
   * Unpublishes merged terms when the platform supports term status.
   *
   * @param list<int> $branchTids
   *   Merged term ids.
   *
   * @return int
   *   Terms unpublished.
   */
  public function unpublishBranches(array $branchTids): int {
    $count   = 0;
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach ($branchTids as $tid) {
      $term = $storage->load($tid);
      if ($term instanceof TermInterface && $term->hasField('status') && $term->isPublished()) {
        $term->setUnpublished();
        $term->save();
        $count++;
      }
    }
    return $count;
  }

  /**
   * Batch operation: relinks one chunk of entities for one field.
   *
   * Each call resaves at most $chunkSize entities and reports progress
   * through the batch sandbox, keeping every HTTP request short enough to
   * stay under the Cloudflare proxy timeout.
   */
  public static function batchRelinkChunk(string $entityTypeId, string $fieldName, string $vid, int $trunkTid, array $branchTids, int $chunkSize, array &$context): void {
    /** @var self $merger */
    $merger = \Drupal::service('dar_taxonomy_merge.term_merger');

    if (!isset($context['sandbox']['ids'])) {
      $context['sandbox']['ids']      = $merger->referencingEntityIds($entityTypeId, $fieldName, $branchTids);
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['total']    = count($context['sandbox']['ids']);
    }
    $context['results']['vid'] = $vid;

    $chunk = array_slice(
      $context['sandbox']['ids'],
      $context['sandbox']['progress'],
      max(1, $chunkSize),
    );
    $moved = $merger->relinkChunk($entityTypeId, $fieldName, $chunk, $trunkTid, $branchTids);

    $context['sandbox']['progress'] += count($chunk);
    $context['results']['relinked']  = ($context['results']['relinked'] ?? 0) + $moved;
    $context['message']              = t('Relinking references in @field on @type (@done/@total).', [
      '@field' => $fieldName,
      '@type'  => $entityTypeId,
      '@done'  => $context['sandbox']['progress'],
      '@total' => $context['sandbox']['total'],
    ]);
    $context['finished']             = $context['sandbox']['total'] > 0
      ? $context['sandbox']['progress'] / $context['sandbox']['total']
      : 1;
  }

  /**
   * Batch operation: unpublishes the merged terms.
   */
  public static function batchUnpublish(string $vid, int $trunkTid, array $branchTids, array &$context): void {
    /** @var self $merger */
    $merger = \Drupal::service('dar_taxonomy_merge.term_merger');

    $context['results']['vid']         = $vid;
    $context['results']['unpublished'] = $merger->unpublishBranches($branchTids);
    $context['message']                = t('Unpublishing merged terms.');
    $context['finished']               = 1;
  }

  /**
   * Batch finished callback: reports the outcome and returns to the browser.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|null
   *   Redirect to the vocabulary merge page on success.
   */
  public static function batchFinished(bool $success, array $results, array $operations): ?RedirectResponse {
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(t('The merge did not complete. Review the logs, then retry.'));
      return NULL;
    }

    $messenger->addStatus(t('Merge complete: @count records now point at the surviving term; @terms merged terms were unpublished.', [
      '@count' => $results['relinked'] ?? 0,
      '@terms' => $results['unpublished'] ?? 0,
    ]));
    $vid = $results['vid'] ?? NULL;
    if ($vid !== NULL) {
      return new RedirectResponse(
        Url::fromRoute('dar_taxonomy_merge.browse', ['taxonomy_vocabulary' => $vid])->toString(),
      );
    }
    return new RedirectResponse(Url::fromRoute('dar_taxonomy_merge.index')->toString());
  }

}
