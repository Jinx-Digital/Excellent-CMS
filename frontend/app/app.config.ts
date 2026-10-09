export default defineAppConfig({
  ui: {
    colors: {
      primary: 'emerald',
      neutral: 'zinc'
    },
    input: { slots: { root: 'w-full' } },
    select: { slots: { base: 'w-full' } },
    selectMenu: { slots: { base: 'w-full' } },
    textarea: { slots: { root: 'w-full' } },
    inputNumber: { slots: { root: 'w-full' } }
  }
})
