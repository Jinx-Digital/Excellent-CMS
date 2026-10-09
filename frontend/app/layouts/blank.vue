<template>
  <div class="relative min-h-svh flex flex-col items-center justify-center gap-8 p-4 bg-muted">
    <!-- Language of the app, out of the way in the top corner -->
    <div class="absolute top-4 end-4">
      <LocaleSwitch />
    </div>
    <AppLogo />
    <slot />
  </div>
</template>
