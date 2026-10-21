<?php

declare(strict_types=1);

namespace Drupal\dar_taxonomy_merge\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\dar_taxonomy_merge\Repository\MergeRepository;
use Drupal\dar_taxonomy_merge\Services\TermMerger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation screen: pick the surviving term and start the merge batch.
 */
final class MergeConfirmForm extends ConfirmFormBase {

  public function __construct(
    private readonly MergeRepository $repository,
    private readonly TermMerger $termMerger,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dar_taxonomy_merge.repository'),
      $container->get('dar_taxonomy_merge.term_merger'),
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dar_taxonomy_merge_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Merge the selected terms?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    $pending = $this->pending();
    $vid     = $pending['vid'] ?? NULL;
    return $vid !== NULL
      ? Url::fromRoute('dar_taxonomy_merge.browse', ['taxonomy_vocabulary' => $vid])
      : Url::fromRoute('dar_taxonomy_merge.index');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Merge');
  }

  /**
   * Reads the pending selection stashed by the browse form.
   */
  private function pending(): ?array {
    return $this->tempStoreFactory->get(TermBrowseForm::TEMPSTORE_KEY)->get('merge');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $pending = $this->pending();
    if ($pending === NULL || count($pending['tids'] ?? []) < 2 || !$this->repository->isMergeable((string) ($pending['vid'] ?? ''))) {
      throw new NotFoundHttpException();
    }

    $tids   = array_map('intval', $pending['tids']);
    $vid    = (string) $pending['vid'];
    $terms  = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
    $counts = $this->repository->referenceCounts($tids);
    $names  = $this->repository->translatedNames($tids);
    $total  = array_sum($counts);

    $form['#vid']   = $vid;
    $form['#tids']  = $tids;
    $form['#title'] = $this->getQuestion();

    $form['warning'] = [
      '#markup' => '<p class="messages messages--warning">' . $this->t(
        'Records referencing the merged terms will be relinked to the surviving term. The other terms will be unpublished — they are not deleted, and can be republished later. About @count records will be updated.',
        ['@count' => $total],
      ) . '</p>',
    ];

    $threshold = (int) ($this->configFactory->get('dar_taxonomy_merge.settings')->get('drush_threshold') ?? 200);
    if ($total > $threshold) {
      $form['drush_hint'] = [
        '#markup' => '<p>' . $this->t(
          'This merge touches many records and may take a while. For a faster, timeout-free run use: drush dar-taxonomy-merge:merge @vid TRUNK_TID BRANCH_TID1,BRANCH_TID2,...',
          ['@vid' => $vid],
        ) . '</p>',
      ];
    }

    $options = [];
    $default = NULL;
    foreach ($tids as $tid) {
      $term = $terms[$tid] ?? NULL;
      if ($term === NULL) {
        continue;
      }
      $name = $names[$tid] ?? $term->label();
      $options[$tid] = $this->t('@name (term @tid, @count linked records@status)', [
        '@name'   => $name,
        '@tid'    => $tid,
        '@count'  => $counts[$tid] ?? 0,
        '@status' => $term->hasField('status') && !$term->isPublished() ? ', unpublished' : '',
      ]);
      if ($default === NULL || ($counts[$tid] ?? 0) > ($counts[$default] ?? 0)) {
        $default = $tid;
      }
    }

    $form['trunk'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Surviving term'),
      '#description'   => $this->t('All other selected terms are merged into this one.'),
      '#options'       => $options,
      '#default_value' => $default,
      '#required'      => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trunk    = (int) $form_state->getValue('trunk');
    $tids     = $form['#tids'];
    $vid      = $form['#vid'];
    $branches = array_values(array_diff($tids, [$trunk]));

    try {
      $this->termMerger->validate($trunk, $branches);
    }
    catch (\InvalidArgumentException $exception) {
      $this->messenger()->addError($this->t('The merge could not start: @reason', ['@reason' => $exception->getMessage()]));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $chunk      = $this->termMerger->chunkSize();
    $operations = [];
    foreach ($this->termMerger->targetFieldNames() as $entity_type_id => $field_names) {
      foreach ($field_names as $field_name) {
        $operations[] = [
          [TermMerger::class, 'batchRelinkChunk'],
          [$entity_type_id, $field_name, $vid, $trunk, $branches, $chunk],
        ];
      }
    }
    $operations[] = [[TermMerger::class, 'batchUnpublish'], [$vid, $trunk, $branches]];

    batch_set([
      'title'            => $this->t('Merging taxonomy terms'),
      'init_message'     => $this->t('Starting the merge.'),
      'progress_message' => $this->t('Processed @current of @total passes.'),
      'error_message'    => $this->t('An error occurred during the merge.'),
      'operations'       => $operations,
      'finished'         => [TermMerger::class, 'batchFinished'],
    ]);

    $this->tempStoreFactory->get(TermBrowseForm::TEMPSTORE_KEY)->delete('merge');
    $form_state->setRedirectUrl(
      Url::fromRoute('dar_taxonomy_merge.browse', ['taxonomy_vocabulary' => $vid]),
    );
  }

}
