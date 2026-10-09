<?php

declare(strict_types=1);

namespace App\Application\Schema;

/**
 * Blocks every project has (created like the blocks of plugins, managed by "core" - not deleted or
 * renamed): "Columns" - 1 to 12 columns on a grid of 12, each a place for blocks. A column takes every
 * block of the project ("*"); the admin app offers in it what the field around it allows (in a form the
 * fields of the form, on a page the blocks of the page) - one column block for everything.
 */
final class CoreBlocks
{
  public const MANAGED_BY = 'core';
  public const COLUMNS = 'columns';
  public const COLUMN = 'column';

  /**
   * In the shape of PluginRegistry::blockGroups() (the group "column" before the block using it).
   *
   * @return list<array{plugin: string, name: string, label: string, kind: string, category: string, description: string, template: ?string, fields: list<array>}>
   */
  public static function definitions(): array
  {
    $spans = [['value' => '', 'label' => 'Equal']];
    foreach (range(1, 12) as $span) {
      $spans[] = ['value' => (string)$span, 'label' => $span.'/12'];
    }
    return [
      [
        'plugin' => self::MANAGED_BY,
        'name' => self::COLUMN,
        'label' => 'Column',
        'kind' => 'group',
        'category' => 'Layout',
        'description' => 'One column of the block "Columns": its width and a place for blocks.',
        'template' => null,
        'fields' => [
          ['name' => 'span', 'label' => 'Width (of 12)', 'type' => 'enum', 'options' => array_slice($spans, 1)],
          ['name' => 'content', 'label' => 'Content', 'type' => 'group', 'block_categories' => ['*']],
        ],
      ],
      [
        'plugin' => self::MANAGED_BY,
        'name' => self::COLUMNS,
        'label' => 'Columns',
        'kind' => 'block',
        'category' => 'Layout',
        'description' => '1 to 12 columns on a grid of 12, each with blocks of its own - without widths they share the row equally.',
        'template' => self::TEMPLATE,
        'fields' => [
          ['name' => 'columns', 'label' => 'Columns', 'type' => 'group', 'group' => self::COLUMN, 'repeatable' => true, 'repeat_min' => 1, 'repeat_max' => 12],
        ],
      ],
    ];
  }

  /** Works without CSS of the website (grid inline); classes to style: columns, columns__grid, column */
  private const TEMPLATE = <<<'TWIG'
{% set count = block.columns|length %}
<section class="block columns">
  <div class="columns__grid" style="display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:var(--columns-gap,24px)">
    {% for column in block.columns %}
    <div class="column" style="grid-column:span {{ column.span ?: (12 // max(count, 1)) }};min-width:0">{{ render_blocks(column.content) }}</div>
    {% endfor %}
  </div>
</section>
TWIG;
}
