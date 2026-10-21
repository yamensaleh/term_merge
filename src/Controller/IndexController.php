<?php

declare(strict_types=1);

namespace Drupal\dar_taxonomy_merge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Drupal\dar_taxonomy_merge\Repository\MergeRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirects the index route to the first mergeable vocabulary.
 */
final class IndexController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly MergeRepository $repository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dar_taxonomy_merge.repository'),
    );
  }

  /**
   * Sends the user to the first configured vocabulary that exists.
   */
  public function index(): RedirectResponse {
    $vocabularies = $this->repository->vocabularies();
    if ($vocabularies === []) {
      $this->messenger()->addWarning($this->t('No mergeable vocabularies are configured.'));
      $url = Url::fromRoute('dar_taxonomy_merge.settings');
    }
    else {
      $url = Url::fromRoute('dar_taxonomy_merge.browse', ['taxonomy_vocabulary' => reset($vocabularies)]);
    }
    return new RedirectResponse($url->toString());
  }

}
