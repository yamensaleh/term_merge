<?php

declare(strict_types=1);

namespace Drupal\dar_taxonomy_merge\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\VocabularyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Chooses which vocabularies may be merged and tunes the batch sizes.
 */
final class VocabSettingsForm extends ConfigFormBase {

  /**
   * Config name this form edits.
   */
  private const SETTINGS = 'dar_taxonomy_merge.settings';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->setConfigFactory($configFactory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dar_taxonomy_merge_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::SETTINGS);

    $options = [];
    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
    uasort($vocabularies, fn (VocabularyInterface $a, VocabularyInterface $b): int => strnatcasecmp($a->label(), $b->label()));
    foreach ($vocabularies as $vid => $vocabulary) {
      $options[$vid] = $vocabulary->label();
    }

    $form['vocabularies'] = [
      '#type'          => 'checkboxes',
      '#title'         => $this->t('Mergeable vocabularies'),
      '#description'   => $this->t('Terms of the selected vocabularies can be browsed and merged. Only vocabularies that exist on this site are listed.'),
      '#options'       => $options,
      '#default_value' => $config->get('vocabularies') ?? [],
    ];
    $form['batch_chunk_size'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Entities per batch step'),
      '#description'   => $this->t('Lower values keep each batch request short — important when the site sits behind a proxy timeout such as Cloudflare.'),
      '#default_value' => $config->get('batch_chunk_size') ?? 10,
      '#min'           => 1,
      '#max'           => 100,
      '#required'      => TRUE,
    ];
    $form['drush_threshold'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Drush recommendation threshold'),
      '#description'   => $this->t('When a merge affects more records than this, the confirm screen recommends running the Drush command instead.'),
      '#default_value' => $config->get('drush_threshold') ?? 200,
      '#min'           => 0,
      '#required'      => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (array_filter($form_state->getValue('vocabularies') ?? []) === []) {
      $form_state->setErrorByName('vocabularies', $this->t('Select at least one vocabulary.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::SETTINGS)
      ->set('vocabularies', array_values(array_filter($form_state->getValue('vocabularies'))))
      ->set('batch_chunk_size', (int) $form_state->getValue('batch_chunk_size'))
      ->set('drush_threshold', (int) $form_state->getValue('drush_threshold'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
