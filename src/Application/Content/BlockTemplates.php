<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldGroup;
use App\Repository\EntityRepository;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Psr\Log\LoggerInterface;
use Throwable;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * HTML of blocks from their templates (Twig, edited in the admin app or brought by plugins), so
 * websites need no template of their own: the content API adds "_html" to every item of a blocks
 * field (?render=html), the PHP SDK uses it where the website has no template.
 *
 * The templates run in Twig's sandbox: only the tags, filters and functions below, no methods or
 * properties of objects (the block is an array), output escaped. In a template:
 *
 *   block              the item as the content API delivers it (block.title, block.image.url …)
 *   api_url            the content API of the project (https://cms.example.com/api/v1/<project>)
 *   render_blocks(x)   the HTML of a nested block list (e.g. block.content of a column)
 *   |markdown          Markdown as HTML (HTML inside the Markdown is escaped)
 */
final class BlockTemplates
{
  private const TAGS = ['if', 'for', 'set', 'apply', 'spaceless', 'with'];
  private const FILTERS = [
    'escape', 'e', 'raw', 'markdown', 'nl2br', 'upper', 'lower', 'capitalize', 'title', 'trim', 'striptags', 'length', 'join',
    'split', 'slice', 'first', 'last', 'keys', 'default', 'date', 'number_format', 'replace', 'url_encode', 'json_encode', 'abs',
    'round', 'sort', 'reverse', 'merge', 'filter', 'map', 'column', 'batch', 'format', 'spaceless',
  ];
  private const FUNCTIONS = ['range', 'cycle', 'min', 'max', 'random', 'date', 'render_blocks', 'attribute'];
  private const MAX_DEPTH = 8;

  private ?Environment $twig = null;
  private ?GithubFlavoredMarkdownConverter $markdown = null;
  private int $depth = 0;
  /** @var array<string, mixed> available in every template */
  private array $globals = [];

  /**
   * The address of the project's API (templates: api_url) - set from the request.
   */
  public function setApiUrl(string $url): void
  {
    $this->globals['api_url'] = rtrim($url, '/');
  }

  public function __construct(
    private EntityRepository $entities,
    private ?LoggerInterface $logger = null,
    private ?string $cachePath = null,
  ) {
  }

  /**
   * "_html" on every item of the blocks fields of the records (as the content API presents them).
   *
   * @param list<array<string, mixed>> $records
   * @return list<array<string, mixed>>
   */
  public function decorate(EntityDefinition $entity, array $records): array
  {
    $fields = array_filter($entity->fields, static fn(FieldDefinition $field): bool => $field->isBlocks());
    foreach ($records as $i => $record) {
      foreach ($fields as $field) {
        if (is_array($record[$field->name] ?? null)) {
          $records[$i][$field->name] = $this->items($field, $record[$field->name]);
        }
      }
    }
    return $records;
  }

  /**
   * The items of a blocks field with their "_html" ("" without a template).
   *
   * @param list<mixed> $items
   * @return list<mixed>
   */
  public function items(FieldDefinition $field, array $items): array
  {
    foreach ($items as $i => $item) {
      if (is_array($item)) {
        $group = $field->groupFor($item);
        $items[$i]['_html'] = null !== $group ? $this->render($group, $item) : '';
      }
    }
    return $items;
  }

  /**
   * The HTML of one block - "" without a template; errors are logged ("" too), unless $strict.
   *
   * @param array<string, mixed> $block
   */
  public function render(FieldGroup $group, array $block, bool $strict = false): string
  {
    if (null === $group->template || '' === trim($group->template)) {
      return '';
    }
    if ($this->depth >= self::MAX_DEPTH) {
      return '';
    }
    $this->depth++;
    try {
      $name = 'block_'.$group->id.'_'.md5($group->template);
      $twig = $this->twig();
      /** @var ArrayLoader $loader */
      $loader = $twig->getLoader();
      if (!$loader->exists($name)) {
        $loader->setTemplate($name, $group->template);
      }
      return trim($twig->render($name, ['block' => $block] + $this->globals + ['api_url' => '']));
    } catch (Throwable $e) {
      if ($strict) {
        throw $e;
      }
      $this->logger?->warning(sprintf('Template of block "%s": %s', $group->name, $e->getMessage()));
      return '';
    } finally {
      $this->depth--;
    }
  }

  /**
   * Is the template valid Twig (within the sandbox)? The message of the first problem - null: fine.
   */
  public function check(string $template): ?string
  {
    try {
      $twig = $this->twig();
      $name = 'check_'.md5($template);
      /** @var ArrayLoader $loader */
      $loader = $twig->getLoader();
      $loader->setTemplate($name, $template);
      // The sandbox checks tags, filters and functions when the template runs
      $twig->render($name, ['block' => []]);
      return null;
    } catch (\Twig\Error\SyntaxError | \Twig\Sandbox\SecurityError $e) {
      return $e->getRawMessage().(0 < $e->getTemplateLine() ? sprintf(' (line %d)', $e->getTemplateLine()) : '');
    } catch (Throwable) {
      // Errors of the empty sample (missing values) do not count
      return null;
    }
  }

  private function twig(): Environment
  {
    if (null !== $this->twig) {
      return $this->twig;
    }
    $twig = new Environment(new ArrayLoader(), ['autoescape' => 'html', 'cache' => $this->cachePath ?? false, 'strict_variables' => false]);
    $policy = new SecurityPolicy(self::TAGS, self::FILTERS, [], [], self::FUNCTIONS);
    $twig->addExtension(new SandboxExtension($policy, true));
    $twig->addFilter(new TwigFilter('markdown', fn(?string $text): string => $this->markdown((string)$text), ['is_safe' => ['html']]));
    $twig->addFunction(new TwigFunction('render_blocks', fn(mixed $items): string => $this->renderList($items), ['is_safe' => ['html']]));
    return $this->twig = $twig;
  }

  /**
   * Nested blocks: each with the template of its type (found by name in the current project).
   */
  private function renderList(mixed $items): string
  {
    $html = '';
    foreach (is_array($items) ? $items : [] as $item) {
      if (!is_array($item) || !is_string($item['_type'] ?? null)) {
        continue;
      }
      if (is_string($item['_html'] ?? null) && '' !== $item['_html']) {
        $html .= $item['_html'];
        continue;
      }
      $group = $this->entities->findGroup($item['_type']);
      $html .= null !== $group && $group->isBlock() ? $this->render($group, $item) : '';
    }
    return $html;
  }

  private function markdown(string $text): string
  {
    $this->markdown ??= new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
    return (string)$this->markdown->convert($text);
  }
}
