<?php

declare(strict_types=1);

namespace Drupal\dar_taxonomy_merge\Repository;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\taxonomy\VocabularyInterface;

/**
 * Read-side queries for the taxonomy merge pages.
 *
 * The repository never hardcodes entity types, bundles or field names: every
 * field whose storage targets taxonomy_term is discovered through the entity
 * field map, so the module works on any tenant's content model.
 */
final class MergeRepository {

  /**
   * Rows shown on one browse page, and groups shown per duplicates page.
   */
  public const PAGE_SIZE = 25;

  /**
   * Config name carrying the mergeable vocabulary list and tuning values.
   */
  private const SETTINGS = 'dar_taxonomy_merge.settings';

  public function __construct(
    private readonly Connection $database,
    private readonly LanguageManagerInterface $languageManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Configured vocabularies that exist on this instance.
   *
   * @return list<string>
   *   Vocabulary ids.
   */
  public function vocabularies(): array {
    $configured = $this->configFactory->get(self::SETTINGS)->get('vocabularies') ?? [];
    $existing   = array_keys($this->entityTypeManager
      ->getStorage('taxonomy_vocabulary')
      ->loadMultiple());
    return array_values(array_intersect($configured, $existing));
  }

  /**
   * Whether a vocabulary is mergeable under the current configuration.
   */
  public function isMergeable(string $vid): bool {
    return in_array($vid, $this->vocabularies(), TRUE);
  }

  /**
   * Vocabulary id to label options for the mergeable vocabularies.
   *
   * @return array<string, string>
   *   Vocabulary id to label.
   */
  public function vocabularyOptions(): array {
    $options = [];
    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')
      ->loadMultiple($this->vocabularies());
    uasort($vocabularies, fn (VocabularyInterface $a, VocabularyInterface $b): int => strnatcasecmp($a->label(), $b->label()));
    foreach ($vocabularies as $vid => $vocabulary) {
      $options[$vid] = $vocabulary->label();
    }
    return $options;
  }

  /**
   * One page of terms in a vocabulary, ordered by default-language name.
   *
   * @return array{rows: list<array{tid: int, name: string, status: int}>, has_next: bool}
   *   Term rows and whether another page exists.
   */
  public function termsPage(string $vid, string $search, int $page): array {
    $select = $this->database->select('taxonomy_term_field_data', 't');
    $select->fields('t', ['tid', 'name', 'status']);
    $select->condition('t.vid', $vid);
    $select->condition('t.default_langcode', 1);
    if ($search !== '') {
      $select->condition('t.name', $this->database->escapeLike($search) . '%', 'LIKE');
    }
    $select->orderBy('t.name');
    $select->orderBy('t.tid');
    $select->range(max(0, $page) * self::PAGE_SIZE, self::PAGE_SIZE + 1);

    $rows = $select->execute()->fetchAllAssoc('tid');
    $has_next = count($rows) > self::PAGE_SIZE;
    if ($has_next) {
      array_pop($rows);
    }

    $result = [];
    foreach ($rows as $tid => $row) {
      $result[] = [
        'tid'    => (int) $tid,
        'name'   => (string) $row->name,
        'status' => (int) $row->status,
      ];
    }
    return ['rows' => $result, 'has_next' => $has_next];
  }

  /**
   * Total terms in a vocabulary matching the browse filter.
   */
  public function termsCount(string $vid, string $search): int {
    $select = $this->database->select('taxonomy_term_field_data', 't');
    $select->condition('t.vid', $vid);
    $select->condition('t.default_langcode', 1);
    if ($search !== '') {
      $select->condition('t.name', $this->database->escapeLike($search) . '%', 'LIKE');
    }
    return (int) $select->countQuery()->execute()->fetchField();
  }

  /**
   * Total duplicate-name groups in a vocabulary.
   */
  public function duplicateGroupsCount(string $vid): int {
    $norm  = $this->normalizedNameExpression('t.name');
    $inner = $this->database->select('taxonomy_term_field_data', 't');
    $inner->addExpression($norm, 'norm');
    $inner->condition('t.vid', $vid);
    $inner->condition('t.default_langcode', 1);
    $inner->groupBy('norm');
    $inner->having('COUNT(t.tid) > 1');

    $count = $this->database->select($inner, 'groups');
    $count->addExpression('COUNT(groups.norm)', 'group_count');
    return (int) $count->execute()->fetchField();
  }

  /**
   * One page of duplicate-name groups in a vocabulary.
   *
   * A group is a set of terms whose normalized default-language name is
   * identical. Normalization folds Latin case, Arabic alef variants,
   * alef-maksura and tashkeel so "محمد" and "مُحَمَّد" compare equal.
   *
   * @return array{groups: list<array{norm: string, rows: list<array{tid: int, name: string, status: int}>}>, has_next: bool}
   *   Groups of term rows and whether another page exists.
   */
  public function duplicateGroups(string $vid, int $page): array {
    $norm = $this->normalizedNameExpression('t.name');

    // Find the normalized keys owned by more than one term, and pull each
    // group's member ids in the same pass.
    $keys = $this->database->select('taxonomy_term_field_data', 't');
    $keys->addExpression($norm, 'norm');
    $keys->condition('t.vid', $vid);
    $keys->condition('t.default_langcode', 1);
    $keys->groupBy('norm');
    $keys->having('COUNT(t.tid) > 1');
    $keys->orderBy('norm');
    $keys->range(max(0, $page) * self::PAGE_SIZE, self::PAGE_SIZE + 1);

    $norms = $keys->execute()->fetchCol();
    $has_next = count($norms) > self::PAGE_SIZE;
    if ($has_next) {
      array_pop($norms);
    }
    if ($norms === []) {
      return ['groups' => [], 'has_next' => $has_next];
    }

    // A derived table exposes the normalized name as a real column, so the
    // group keys can be matched with an ordinary IN condition.
    $inner = $this->database->select('taxonomy_term_field_data', 't');
    $inner->addField('t', 'tid', 'tid');
    $inner->addExpression($norm, 'norm');
    $inner->condition('t.vid', $vid);
    $inner->condition('t.default_langcode', 1);

    $members_query = $this->database->select($inner, 'x');
    $members_query->addField('x', 'tid', 'tid');
    $members_query->addField('x', 'norm', 'norm');
    $members_query->condition('x.norm', $norms, 'IN');

    $tids    = [];
    $tid_map = [];
    foreach ($members_query->execute() as $row) {
      $tid           = (int) $row->tid;
      $tids[]        = $tid;
      $tid_map[$tid] = (string) $row->norm;
    }
    if ($tids === []) {
      return ['groups' => [], 'has_next' => $has_next];
    }

    $rows = $this->database->select('taxonomy_term_field_data', 't')
      ->fields('t', ['tid', 'name', 'status'])
      ->condition('t.tid', $tids, 'IN')
      ->condition('t.default_langcode', 1)
      ->execute()
      ->fetchAllAssoc('tid');

    $groups = [];
    foreach ($tids as $tid) {
      $row = $rows[$tid] ?? NULL;
      if ($row === NULL) {
        continue;
      }
      $norm_value                    = $tid_map[$tid];
      $groups[$norm_value]['norm']   = $norm_value;
      $groups[$norm_value]['rows'][] = [
        'tid'    => $tid,
        'name'   => (string) $row->name,
        'status' => (int) $row->status,
      ];
    }
    foreach ($groups as &$group) {
      usort($group['rows'], fn (array $a, array $b): int => [$a['name'], $a['tid']] <=> [$b['name'], $b['tid']]);
    }
    $ordered = [];
    foreach ($norms as $norm_value) {
      if (isset($groups[$norm_value])) {
        $ordered[] = $groups[$norm_value];
      }
    }
    return ['groups' => $ordered, 'has_next' => $has_next];
  }

  /**
   * Distinct entities referencing each given term, across all entity types.
   *
   * @param list<int> $tids
   *   Term ids.
   *
   * @return array<int, int>
   *   Term id to referencing entity count.
   */
  public function referenceCounts(array $tids): array {
    $tids = array_values(array_filter(array_map('intval', $tids)));
    if ($tids === []) {
      return [];
    }

    $counts = [];
    foreach ($this->targetFields() as $entity_type_id => $fields) {
      $union = NULL;
      foreach ($fields as $field) {
        if ($field['table'] === NULL) {
          continue;
        }
        $part = $this->database->select($field['table'], 'ref');
        $part->addField('ref', $field['column'], 'tid');
        $part->addField('ref', 'entity_id', 'entity_id');
        $part->condition('ref.' . $field['column'], $tids, 'IN');
        $part->condition('ref.deleted', 0);
        $union = $union === NULL ? $part : $union->union($part);
      }
      if ($union === NULL) {
        continue;
      }
      $query = $this->database->select($union, 'refs');
      $query->addField('refs', 'tid');
      $query->addExpression('COUNT(DISTINCT refs.entity_id)', 'ref_count');
      $query->groupBy('refs.tid');
      foreach ($query->execute() as $row) {
        $tid = (int) $row->tid;
        $counts[$tid] = ($counts[$tid] ?? 0) + (int) $row->ref_count;
      }
    }
    return $counts;
  }

  /**
   * Field storages that point at taxonomy_term, keyed by entity type.
   *
   * Any storage whose `target_type` setting is taxonomy_term qualifies:
   * entity_reference, typed_relation, entity_reference_revisions and custom
   * types are all discovered. Fields on tenants' custom entity types are
   * picked up automatically.
   *
   * @return array<string, array<string, array{table: ?string, column: string}>>
   *   Entity type id to field name to the SQL table and target column holding
   *   the reference. Table is NULL when the field is not SQL-backed.
   */
  public function targetFields(): array {
    $map     = $this->entityFieldManager->getFieldMap();
    $targets = [];
    foreach ($map as $entity_type_id => $fields) {
      $storages = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
      foreach ($fields as $field_name => $field_info) {
        $storage = $storages[$field_name] ?? NULL;
        if ($storage === NULL || $storage->getSetting('target_type') !== 'taxonomy_term') {
          continue;
        }
        $table = $entity_type_id . '__' . $field_name;
        $targets[$entity_type_id][$field_name] = [
          'table'  => $this->database->schema()->tableExists($table) ? $table : NULL,
          'column' => $field_name . '_' . $storage->getMainPropertyName(),
        ];
      }
    }
    return $targets;
  }

  /**
   * Current-language names for the given terms, when a translation exists.
   *
   * @param list<int> $tids
   *   Term ids.
   *
   * @return array<int, string>
   *   Term id to translated name.
   */
  public function translatedNames(array $tids): array {
    if ($tids === []) {
      return [];
    }
    $names = $this->database->select('taxonomy_term_field_data', 'tr')
      ->fields('tr', ['tid', 'name'])
      ->condition('tr.tid', $tids, 'IN')
      ->condition('tr.langcode', $this->languageManager->getCurrentLanguage()->getId())
      ->execute()
      ->fetchAllKeyed();

    $translated = [];
    foreach ($names as $tid => $name) {
      $translated[(int) $tid] = (string) $name;
    }
    return $translated;
  }

  /**
   * MySQL expression producing the normalized duplicate-detection key.
   *
   * Folds alef variants to bare alef, alef-maksura to ya, strips tashkeel
   * and tatweel, collapses double spaces, then lowercases and trims.
   */
  private function normalizedNameExpression(string $column): string {
    $expr = $column;
    foreach ([['أ', 'ا'], ['إ', 'ا'], ['آ', 'ا'], ['ٱ', 'ا'], ['ى', 'ي']] as $pair) {
      $expr = "REPLACE({$expr}, '{$pair[0]}', '{$pair[1]}')";
    }
    foreach (['ً', 'ٌ', 'ٍ', 'َ', 'ُ', 'ِ', 'ّ', 'ْ', 'ٰ', 'ـ'] as $diacritic) {
      $expr = "REPLACE({$expr}, '{$diacritic}', '')";
    }
    $expr = "REPLACE({$expr}, '  ', ' ')";
    return "LOWER(TRIM({$expr}))";
  }

}
