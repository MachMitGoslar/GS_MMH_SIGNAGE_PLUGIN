<template>
  <k-field
    v-if="pending > 0 || message"
    v-bind="$props"
    class="k-slide-migration-field"
  >
    <k-box :theme="error ? 'negative' : message ? 'positive' : 'notice'">
      <template v-if="message">{{ message }}</template>
      <template v-else>
        <strong>{{ pending }}</strong>
        {{ pending === 1 ? 'Folie liegt' : 'Folien liegen' }} noch in Kanälen
        und {{ pending === 1 ? 'muss' : 'müssen' }} in die gemeinsame
        Folien-Bibliothek verschoben werden. Die Reihenfolge in den Kanälen
        bleibt erhalten, doppelte Namen werden automatisch angepasst.
      </template>
    </k-box>
    <k-button
      v-if="!message"
      icon="refresh"
      variant="filled"
      theme="notice"
      class="k-slide-migration-button"
      :disabled="busy"
      @click="migrate"
    >
      {{ busy ? 'Migration läuft …' : 'Folien jetzt migrieren' }}
    </k-button>
  </k-field>
</template>

<script>
export default {
  props: {
    label: String,
    help: String,
    pending: { type: Number, default: 0 },
  },
  data() {
    return { busy: false, message: null, error: false };
  },
  methods: {
    async migrate() {
      if (
        !window.confirm(
          'Alle Folien aus den Kanälen in die Folien-Bibliothek verschieben? Bitte vorher ein Backup bzw. einen Commit im content-Ordner sicherstellen.'
        )
      ) {
        return;
      }
      this.busy = true;
      try {
        const result = await this.$api.post('signage/migrate-slides');
        this.message = `${result.moved} Folien aus ${result.channels} Kanälen migriert.`;
        this.$panel.notification.success(this.message);
        setTimeout(() => this.$panel.view.reload(), 1500);
      } catch (e) {
        this.error = true;
        this.message = e.message || 'Migration fehlgeschlagen.';
      } finally {
        this.busy = false;
      }
    },
  },
};
</script>
