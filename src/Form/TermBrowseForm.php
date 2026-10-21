<?php

declare(strict_types=1);

namespace Drupal\dar_taxonomy_merge\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\dar_taxonomy_merge\Repository\MergeRepository;
use Drupal\taxonomy\VocabularyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Term list with checkboxes for picking merge candidates.
 *
 * Two modes share the page: a plain paged/searchable browse of the
 * vocabulary, and a duplicates mode (?duplicates=1) that groups terms whose
 * normalized names are identical. Checking terms and submitting stashes the
 * selection in the private tempstore and moves on to the confirm screen.
 */
final class TermBrowseForm extends FormBase {

  /**
   * Tempstore key holding the pending merge selection.
   */
  public const TEMPSTORE_KEY = 'dar_taxonomy_merge';

  public function __construct(
    private readonly MergeRepository $repository,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly PagerManagerInterface $pagerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dar_taxonomy_merge.repository'),
      $container->get('tempstore.private'),
      $container->get('pager.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dar_taxonomy_merge_browse';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?VocabularyInterface $taxonomy_vocabulary = NULL): array {
    if ($taxonomy_vocabulary === NULL || !$this->repository->isMergeable($taxonomy_vocabulary->id())) {
      throw new NotFoundHttpException();
    }

    $request    = $this->getRequest();
    $vid        = $taxonomy_vocabulary->id();
    $search     = trim((string) $request->query->get('q', ''));
    $duplicates = (bool) $request->query->get('duplicates', FALSE);
    $page       = max(0, $request->query->getInt('page'));

    $form['#title']                 = $this->t('Merge terms: @vocabulary', ['@vocabulary' => $taxonomy_vocabulary->label()]);
    $form['#vid']                   = $vid;
    $form['#search']                = $search;
    $form['#attached']['library'][] = 'dar_taxonomy_merge/merge';

    $form['help'] = [
      '#markup' => '<p>' . $this->t('Check two or more terms, then choose the surviving term on the next screen. Selection does not carry across pages.') . '</p>',
    ];

    $this->buildToolbar($form, $taxonomy_vocabulary, $search, $duplicates);

    if ($duplicates) {
      $this->buildDuplicatesTable($form, $vid, $page);
      $total = $this->repository->duplicateGroupsCount($vid);
    }
    else {
      $this->buildBrowseTable($form, $vid, $search, $page);
      $total = $this->repository->termsCount($vid, $search);
    }
    $this->pagerManager->createPager($total, MergeRepository::PAGE_SIZE);

    $form['actions']['merge'] = [
      '#type'   => 'submit',
      '#value'  => $this->t('Merge selected'),
      '#submit' => ['::submitMerge'],
    ];
    return $form;
  }

  /**
   * Vocabulary switcher, name search, and the duplicates-mode toggle.
   */
  private function buildToolbar(array &$form, VocabularyInterface $vocabulary, string $search, bool $duplicates): void {
    $options = $this->repository->vocabularyOptions();

    $form['toolbar'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['dar-taxonomy-merge-toolbar']],
    ];
    $form['toolbar']['vocabulary'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Vocabulary'),
      '#options'       => $options,
      '#default_value' => $vocabulary->id(),
    ];
    $form['toolbar']['switch'] = [
      '#type'   => 'submit',
      '#value'  => $this->t('Switch'),
      '#submit' => ['::submitSwitchVocabulary'],
    ];
    $form['toolbar']['search'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Name starts with'),
      '#size'          => 30,
      '#maxlength'     => 100,
      '#default_value' => $search,
    ];
    $form['toolbar']['apply'] = [
      '#type'   => 'submit',
      '#value'  => $this->t('Search'),
      '#submit' => ['::submitSearch'],
    ];

    $toggle_query = ['duplicates' => $duplicates ? NULL : 1, 'q' => $search !== '' ? $search : NULL];
    $form['toolbar']['duplicates_toggle'] = [
      '#type'       => 'link',
      '#title'      => $duplicates ? $this->t('Back to browse') : $this->t('Show duplicate names'),
      '#url'        => Url::fromRoute('dar_taxonomy_merge.browse', ['taxonomy_vocabulary' => $vocabulary->id()], ['query' => array_filter($toggle_query)]),
      '#attributes' => ['class' => ['button', 'dar-taxonomy-merge-duplicates-toggle']],
    ];
  }

  /**
   * Checkbox table of one browse page.
   */
  private function buildBrowseTable(array &$form, string $vid, string $search, int $page): void {
    $result = $this->repository->termsPage($vid, $search, $page);
    $tids   = array_column($result['rows'], 'tid');
    $counts = $this->repository->referenceCounts($tids);
    $names  = $this->repository->translatedNames($tids);

    $options = [];
    foreach ($result['rows'] as $row) {
      $tid = $row['tid'];
      $options[$tid] = [
        'name'    => $names[$tid] ?? $row['name'],
        'tid'     => $tid,
        'records' => $counts[$tid] ?? 0,
        'status'  => $row['status'] ? $this->t('Published') : $this->t('Unpublished'),
      ];
    }

    $form['terms'] = [
      '#type'    => 'tableselect',
      '#header'  => $this->tableHeader(),
      '#options' => $options,
      '#empty'   => $this->t('No terms found.'),
    ];
    $form['pager'] = ['#type' => 'pager'];
  }

  /**
   * Checkbox tables for one page of duplicate-name groups.
   */
  private function buildDuplicatesTable(array &$form, string $vid, int $page): void {
    $result = $this->repository->duplicateGroups($vid, $page);
    if ($result['groups'] === []) {
      $form['terms'] = [
        '#markup' => '<p>' . $this->t('No duplicate names found in this vocabulary.') . '</p>',
      ];
      return;
    }

    $all_tids = [];
    foreach ($result['groups'] as $group) {
      foreach ($group['rows'] as $row) {
        $all_tids[] = $row['tid'];
      }
    }
    $counts = $this->repository->referenceCounts($all_tids);
    $names  = $this->repository->translatedNames($all_tids);

    $form['groups'] = ['#tree' => TRUE];
    foreach ($result['groups'] as $index => $group) {
      $options = [];
      foreach ($group['rows'] as $row) {
        $tid = $row['tid'];
        $options[$tid] = [
          'name'    => $names[$tid] ?? $row['name'],
          'tid'     => $tid,
          'records' => $counts[$tid] ?? 0,
          'status'  => $row['status'] ? $this->t('Published') : $this->t('Unpublished'),
        ];
      }
      $form['groups'][$index] = [
        '#type'       => 'details',
        '#title'      => $this->t('Duplicate group: @name (@count terms)', [
          '@name'  => $names[$group['rows'][0]['tid']] ?? $group['rows'][0]['name'],
          '@count' => count($group['rows']),
        ]),
        '#open'       => TRUE,
        '#attributes' => ['class' => ['dar-taxonomy-merge-group']],
      ];
      $form['groups'][$index]['terms'] = [
        '#type'    => 'tableselect',
        '#header'  => $this->tableHeader(),
        '#options' => $options,
      ];
    }
    $form['pager'] = ['#type' => 'pager'];
  }

  /**
   * Shared table header for both modes.
   */
  private function tableHeader(): array {
    return [
      'name'    => $this->t('Name'),
      'tid'     => $this->t('Term ID'),
      'records' => $this->t('Linked records'),
      'status'  => $this->t('Status'),
    ];
  }

  /**
   * Redirects to another vocabulary's merge page.
   */
  public function submitSwitchVocabulary(array &$form, FormStateInterface $form_state): void {
    $vid = (string) $form_state->getValue('vocabulary');
    $form_state->setRedirectUrl(
      Url::fromRoute('dar_taxonomy_merge.browse', ['taxonomy_vocabulary' => $vid]),
    );
  }

  /**
   * Redirects back to browse mode with the search applied.
   */
  public function submitSearch(array &$form, FormStateInterface $form_state): void {
    $vid    = $form['#vid'];
    $search = trim((string) $form_state->getValue('search'));
    $form_state->setRedirectUrl(
      Url::fromRoute('dar_taxonomy_merge.browse', ['taxonomy_vocabulary' => $vid], [
        'query' => array_filter(['q' => $search !== '' ? $search : NULL]),
      ]),
    );
  }

  /**
   * Stashes the checked terms and moves to the confirm screen.
   */
  public function submitMerge(array &$form, FormStateInterface $form_state): void {
    $tids = [];
    $selected = $form_state->getValue('terms');
    if (is_array($selected)) {
      $tids = array_keys(array_filter($selected));
    }
    $groups = $form_state->getValue('groups');
    if (is_array($groups)) {
      foreach ($groups as $group) {
        if (is_array($group['terms'] ?? NULL)) {
          $tids = array_merge($tids, array_keys(array_filter($group['terms'])));
        }
      }
    }

    $tids = array_values(array_unique(array_map('intval', $tids)));
    if (count($tids) < 2) {
      $this->messenger()->addError($this->t('Select at least two terms to merge.'));
      return;
    }

    $this->tempStoreFactory->get(self::TEMPSTORE_KEY)->set('merge', [
      'vid'  => $form['#vid'],
      'tids' => $tids,
    ]);
    $form_state->setRedirectUrl(Url::fromRoute('dar_taxonomy_merge.confirm'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // All actions carry their own #submit handlers.
  }

}
