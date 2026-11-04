<template>
  <k-field v-bind="$props" class="k-signage-preview-field">
    <p v-if="!src" class="k-signage-preview-empty">
      Für die Vorschau wird mindestens ein Bildschirm benötigt.
    </p>

    <template v-else>
      <div class="k-signage-preview-toolbar">
        <k-button-group>
          <k-button
            v-for="option in orientations"
            :key="option.value"
            :icon="option.icon"
            :variant="current === option.value ? 'filled' : null"
            size="sm"
            @click="current = option.value"
          >
            {{ option.label }}
          </k-button>
        </k-button-group>
        <k-button-group>
          <k-button icon="refresh" size="sm" @click="reload">
            Neu laden
          </k-button>
          <k-button icon="open" size="sm" :link="frameSrc" target="_blank">
            Vollbild
          </k-button>
        </k-button-group>
      </div>

      <div
        ref="stage"
        class="k-signage-preview-stage"
        :data-orientation="current"
      >
        <div class="k-signage-preview-scaler" :style="scalerStyle">
          <iframe
            :key="reloadKey"
            :src="frameSrc"
            class="k-signage-preview-frame"
            :style="frameStyle"
            title="Signage-Vorschau"
            allow="autoplay"
          ></iframe>
        </div>
      </div>
    </template>
  </k-field>
</template>

<script>
export default {
  props: {
    label: String,
    help: String,
    src: String,
    orientation: { type: String, default: 'horizontal' },
    selectable: Boolean,
  },
  data() {
    return {
      current: this.orientation,
      stageWidth: 0,
      reloadKey: 0,
      orientations: [
        { value: 'horizontal', label: 'Quer', icon: 'display' },
        { value: 'vertical', label: 'Hoch', icon: 'mobile' },
      ],
    };
  },
  computed: {
    // The player is designed for full-HD screens, so render it at that size
    // and scale the whole frame down to the available width.
    baseSize() {
      return this.current === 'vertical'
        ? { width: 1080, height: 1920 }
        : { width: 1920, height: 1080 };
    },
    scale() {
      return this.stageWidth ? this.stageWidth / this.baseSize.width : 0;
    },
    scalerStyle() {
      return {
        width: `${this.baseSize.width * this.scale}px`,
        height: `${this.baseSize.height * this.scale}px`,
      };
    },
    frameStyle() {
      return {
        width: `${this.baseSize.width}px`,
        height: `${this.baseSize.height}px`,
        transform: `scale(${this.scale})`,
      };
    },
    frameSrc() {
      if (!this.src) return null;
      const url = new URL(this.src, window.location.origin);
      url.searchParams.set('orientation', this.current);
      return url.toString();
    },
  },
  mounted() {
    this.observer = new ResizeObserver(() => this.measure());
    window.addEventListener('resize', this.measure);
    this.$nextTick(() => {
      if (this.$refs.stage) {
        this.observer.observe(this.$refs.stage);
        this.measure();
      }
    });
  },
  beforeUnmount() {
    this.observer?.disconnect();
    window.removeEventListener('resize', this.measure);
  },
  methods: {
    measure() {
      this.stageWidth = this.$refs.stage?.clientWidth || 0;
    },
    reload() {
      this.reloadKey++;
    },
  },
};
</script>
