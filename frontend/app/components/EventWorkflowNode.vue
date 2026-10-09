<script setup lang="ts">
import type { EventItem, EventRun } from '~/types/api'

// One event of the workflow: head (name, what it listens to, actions), last run, the steps as a
// timeline, and below a step the events it starts
interface Node { event: EventItem, children: { step: Record<string, unknown>, events: Node[] }[] }
defineProps<{
  node: Node
  sourceName: (event: EventItem) => string
  stepDetail: (step: Record<string, unknown>) => string
  statusColor: (status: string) => 'success' | 'error' | 'info' | 'warning' | 'neutral'
  percent: (run: EventRun) => number
}>()
defineEmits<{ edit: [event: EventItem], runs: [event: EventItem], remove: [event: EventItem] }>()
const format = useFormat()
const ICONS: Record<string, string> = { webhook: 'i-lucide-webhook', email: 'i-lucide-mail', create: 'i-lucide-plus', update: 'i-lucide-pencil', delete: 'i-lucide-trash-2' }
</script>

<template>
  <div class="space-y-5">
    <!-- Head -->
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
      <div class="min-w-0 flex-1 space-y-1.5">
        <div class="flex flex-wrap items-center gap-2">
          <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary/10">
            <UIcon name="i-lucide-zap" class="size-4 text-primary" />
          </span>
          <span class="text-base font-semibold">{{ node.event.name }}</span>
          <UBadge :label="$t(`events.modes.${node.event.mode}`)" color="neutral" variant="subtle" size="sm" />
          <UBadge v-if="node.event.condition" :label="$t('events.withCondition')" color="neutral" variant="subtle" size="sm" />
          <UBadge v-if="!node.event.active" :label="$t('users.inactive')" color="error" variant="subtle" size="sm" />
        </div>
        <p class="text-sm text-muted sm:ps-9">
          {{ sourceName(node.event) }}
          <span class="mx-1">·</span>
          {{ node.event.actions.map(a => $t(`events.actions.${a}`)).join(', ') }}
        </p>
      </div>
      <!-- On small screens only the icons -->
      <div class="flex shrink-0 gap-1">
        <UButton size="sm" icon="i-lucide-activity" color="neutral" variant="outline" :label="$t('events.runs')" :aria-label="$t('events.runs')" :ui="{ label: 'hidden sm:inline' }" @click="$emit('runs', node.event)" />
        <UButton size="sm" icon="i-lucide-pencil" color="neutral" variant="outline" :label="$t('common.edit')" :aria-label="$t('common.edit')" :ui="{ label: 'hidden sm:inline' }" @click="$emit('edit', node.event)" />
        <ConfirmButton size="sm" compact :label="$t('common.delete')" icon="i-lucide-trash-2" variant="ghost" :question="$t('events.deleteQuestion', { event: node.event.name })" @confirm="$emit('remove', node.event)" />
      </div>
    </div>

    <!-- Last run -->
    <div v-if="node.event.last_run" class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md bg-elevated/50 px-3 py-2 text-sm sm:ms-9">
      <span class="text-muted">{{ $t('events.lastRun') }}</span>
      <UBadge :label="$t(`events.status.${node.event.last_run.status}`)" :color="statusColor(node.event.last_run.status)" variant="subtle" />
      <UProgress :model-value="percent(node.event.last_run)" size="sm" :color="statusColor(node.event.last_run.status)" class="w-full sm:w-56" />
      <span class="text-muted">{{ $t('events.records', node.event.last_run.count) }} · {{ format.relative(node.event.last_run.created_at) }}</span>
    </div>

    <!-- Steps as timeline -->
    <ol class="ms-[13px] space-y-4 border-s-2 border-default ps-4 sm:ps-6">
      <li v-for="(child, index) in node.children" :key="index" class="relative">
        <span class="absolute -start-[29px] sm:-start-[37px] top-0 flex size-6 items-center justify-center rounded-full border-2 border-default bg-default">
          <UIcon :name="ICONS[String(child.step.type)] ?? 'i-lucide-circle'" class="size-3.5 text-muted" />
        </span>
        <div class="min-w-0">
          <div class="text-sm font-medium">{{ index + 1 }}. {{ $t(`events.steps.${child.step.type}`) }}</div>
          <div class="text-sm text-muted break-all">{{ stepDetail(child.step) }}</div>
        </div>
        <div v-for="next in child.events" :key="next.event.id" class="mt-4 rounded-lg border border-default bg-elevated/30 p-3 sm:p-5">
          <div class="mb-3 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
            <UIcon name="i-lucide-corner-down-right" class="size-3.5" />
            {{ $t('events.starts') }}
          </div>
          <EventWorkflowNode :node="next" :source-name="sourceName" :step-detail="stepDetail" :status-color="statusColor" :percent="percent" @edit="$emit('edit', $event)" @runs="$emit('runs', $event)" @remove="$emit('remove', $event)" />
        </div>
      </li>
    </ol>
  </div>
</template>
