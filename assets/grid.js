// Media library grid: the same "usage" filter the list view has, next to WordPress's own filters.
(() => {
  const views = window.wp && wp.media && wp.media.view;
  if (!views || !views.AttachmentFilters || !window.mtrk) return;
  const t = window.mtrk.i18n;

  const Usage = views.AttachmentFilters.extend({
    id: 'mtrk-usage-filter',
    createFilters() {
      this.filters = {
        all: { text: t.usageAll, props: { mtrk_usage: '' }, priority: 10 },
        unused: { text: t.usageUnused, props: { mtrk_usage: 'unused' }, priority: 20 },
        used: { text: t.usageUsed, props: { mtrk_usage: 'used' }, priority: 30 },
        new: { text: t.usageNew, props: { mtrk_usage: 'new' }, priority: 40 },
      };
    },
  });

  const Browser = views.AttachmentsBrowser;
  views.AttachmentsBrowser = Browser.extend({
    createToolbar(...args) {
      Browser.prototype.createToolbar.apply(this, args);
      if (!this.options.filters) return; // only browsers that show filters (the library grid, not every picker)
      this.toolbar.set('mtrkUsageLabel', new views.Label({ value: t.usageLabel, attributes: { for: 'mtrk-usage-filter' }, priority: -70 }).render());
      this.toolbar.set('mtrkUsage', new Usage({ controller: this.controller, model: this.collection.props, priority: -70 }).render());
    },
  });
})();
