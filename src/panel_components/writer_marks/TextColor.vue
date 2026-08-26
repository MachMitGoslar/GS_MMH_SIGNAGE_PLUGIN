<template>
  <k-writer-mark
    :attrs="attrs"
    icon="palette"
    :name="name"
    :options="options"
    @command="command"
  >
    <k-form :fields="fields" v-model="attrs" @submit="$emit('submit', attrs)" />
  </k-writer-mark>
</template>

<script>
export default {
  props: {
    attrs: {
      type: Object,
      default: () => ({
        color: 'white',
        customColor: '#ffffff',
      }),
    },
    name: String,
    options: Object,
  },
  computed: {
    fields() {
      return {
        color: {
          label: 'Color',
          type: 'select',
          default: 'white',
          options: [
            { value: 'white', text: 'White' },
            { value: 'black', text: 'Black' },
            { value: 'ripe-mango', text: 'Yellow (Ripe Mango)' },
            { value: 'dead-pixel', text: 'Gray (Dead Pixel)' },
            { value: 'custom', text: 'Custom Color' },
          ],
          width: '1/2',
        },
        customColor: {
          label: 'Custom Color',
          type: 'color',
          default: '#ffffff',
          when: {
            color: 'custom',
          },
          width: '1/2',
        },
      };
    },
  },
  methods: {
    command() {
      this.$panel.dialog.open({
        component: 'k-form-dialog',
        props: {
          fields: this.fields,
          value: this.attrs,
          submitButton: 'Apply Color',
        },
        on: {
          submit: values => {
            this.$emit('submit', values);
            this.$panel.dialog.close();
          },
        },
      });
    },
  },
};
</script>
