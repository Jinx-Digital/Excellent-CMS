<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Entry point of a plugin (the "class" of its plugin.json). Called on every request while the
 * plugin is active: it tells the registry what it adds - event steps, panels …
 *
 *     final class Plugin implements PluginInterface
 *     {
 *         public function register(PluginRegistry $registry, PluginContext $context): void
 *         {
 *             $registry->eventStep('message', 'Chat message', fields: [...], handler: fn(array $step, StepRun $run) => …);
 *         }
 *     }
 */
interface PluginInterface
{
  public function register(PluginRegistry $registry, PluginContext $context): void;
}
