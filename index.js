function normalizeRequests(requestsProp, valueProp) {
  if (Array.isArray(requestsProp)) {
    return requestsProp.slice();
  }

  if (Array.isArray(valueProp)) {
    return valueProp.slice();
  }

  if (typeof valueProp === 'string') {
    if (window.yaml && typeof window.yaml.load === 'function') {
      try {
        const parsed = window.yaml.load(valueProp);
        return Array.isArray(parsed) ? parsed : [];
      } catch (error) {
        return [];
      }
    }
  }

  return [];
}

panel.plugin('gs/mmh-signage', {
  writerMarks: {
    textcolor: {
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
              submit: (values) => {
                this.$emit('submit', values);
                this.$panel.dialog.close();
              },
            },
          });
        },
      },
      template: `
        <k-writer-mark
          :attrs="attrs"
          icon="palette"
          :name="name"
          :options="options"
          @command="command"
        >
          <k-form
            :fields="fields"
            v-model="attrs"
            @submit="$emit('submit', attrs)"
          />
        </k-writer-mark>
      `,
    },
  },
  fields: {
    pending_requests: {
      props: {
        label: String,
        help: String,
        name: String,
        value: [Array, String, Object],
        requests: Array,
        deniedRequests: Array,
        screen: String,
      },
      data() {
        return {
          items: normalizeRequests(this.requests, this.value),
          deniedItems: normalizeRequests(this.deniedRequests, []),
          processing: null,
        };
      },
      computed: {
        hasRequests() {
          return this.items.length > 0;
        },
        hasDenied() {
          return this.deniedItems.length > 0;
        },
      },
      watch: {
        requests(next) {
          this.items = normalizeRequests(next, this.value);
        },
        deniedRequests(next) {
          this.deniedItems = normalizeRequests(next, []);
        },
        value(next) {
          if (!this.requests || this.requests.length === 0) {
            this.items = normalizeRequests(this.requests, next);
          }
        },
      },
      methods: {
        getFieldApiBase() {
          const panelPath = this.$panel?.view?.path;
          if (panelPath) {
            return panelPath;
          }

          if (this.$parent && this.$parent.path) {
            return this.$parent.path;
          }

          const storePath = this.$store?.getters?.['content/path'];
          if (storePath) {
            return storePath;
          }

          return null;
        },
        getScreenSlug() {
          return this.screen || null;
        },
        async approve(uuid) {
          if (this.processing === uuid) return;

          this.$panel.dialog.open({
            component: 'k-form-dialog',
            props: {
              fields: {
                label: {
                  label: 'Gerätename',
                  type: 'text',
                  placeholder: 'z.B. Lobby-Tablet',
                },
              },
              value: {
                label: '',
              },
              submitButton: 'Genehmigen',
            },
            on: {
              submit: (values) => {
                this.$panel.dialog.close();
                this.submitApprove(uuid, values.label || '');
              },
              cancel: () => {
                this.$panel.dialog.close();
              },
            },
          });
        },
        async submitApprove(uuid, label) {
          const screenSlug = this.getScreenSlug();
          if (!screenSlug) {
            this.$panel.notification.error('Konnte den Screen nicht ermitteln.');
            return;
          }
          this.processing = uuid;

          try {
            const response = await this.$api.post(
              `signage/approve-request`,
              {
                uuid: uuid,
                screen: screenSlug,
                label: label,
              }
            );

            if (response.status === 'success') {
              this.$panel.notification.success(response.message || 'Device approved');
              this.items = this.items.filter((request) => request.uuid !== uuid);
              this.$emit('input', this.items);
              if (this.$panel?.view?.reload) {
                this.$panel.view.reload();
              } else if (this.$reload) {
                this.$reload();
              } else {
                window.location.reload();
              }
            } else {
              this.$panel.notification.error(response.message || 'Failed to approve device');
            }
          } catch (error) {
            this.$panel.notification.error(error.message || 'Failed to approve device');
          } finally {
            this.processing = null;
          }
        },
        async deny(uuid) {
          if (this.processing === uuid) return;
          const screenSlug = this.getScreenSlug();
          if (!screenSlug) {
            this.$panel.notification.error('Konnte den Screen nicht ermitteln.');
            return;
          }
          this.processing = uuid;

          try {
            const response = await this.$api.post(
              `signage/deny-request`,
              {
                uuid: uuid,
                screen: screenSlug,
              }
            );

            if (response.status === 'success') {
              this.$panel.notification.success(response.message || 'Request denied');
              this.items = this.items.filter((request) => request.uuid !== uuid);
              this.$emit('input', this.items);
              if (this.$panel?.view?.reload) {
                this.$panel.view.reload();
              } else if (this.$reload) {
                this.$reload();
              }
            } else {
              this.$panel.notification.error(response.message || 'Failed to deny request');
            }
          } catch (error) {
            this.$panel.notification.error(error.message || 'Failed to deny request');
          } finally {
            this.processing = null;
          }
        },
        async allowDenied(uuid) {
          if (this.processing === uuid) return;

          this.$panel.dialog.open({
            component: 'k-form-dialog',
            props: {
              fields: {
                label: {
                  label: 'Geraetename',
                  type: 'text',
                  placeholder: 'z.B. Lobby-Tablet',
                },
              },
              value: {
                label: '',
              },
              submitButton: 'Genehmigen',
            },
            on: {
              submit: (values) => {
                this.$panel.dialog.close();
                this.submitApprove(uuid, values.label || '');
              },
              cancel: () => {
                this.$panel.dialog.close();
              },
            },
          });
        },
        async removeDenied(uuid) {
          if (this.processing === uuid) return;
          const screenSlug = this.getScreenSlug();
          if (!screenSlug) {
            this.$panel.notification.error('Konnte den Screen nicht ermitteln.');
            return;
          }

          this.processing = uuid;

          try {
            const response = await this.$api.post(
              `signage/remove-denied`,
              {
                uuid: uuid,
                screen: screenSlug,
              }
            );

            if (response.status === 'success') {
              this.$panel.notification.success(response.message || 'Eintrag entfernt');
              this.deniedItems = this.deniedItems.filter((request) => request.uuid !== uuid);
              if (this.$panel?.view?.reload) {
                this.$panel.view.reload();
              } else if (this.$reload) {
                this.$reload();
              }
            } else {
              this.$panel.notification.error(response.message || 'Failed to remove denied request');
            }
          } catch (error) {
            this.$panel.notification.error(error.message || 'Failed to remove denied request');
          } finally {
            this.processing = null;
          }
        },
        formatDate(dateStr) {
          if (!dateStr) return 'Unknown';
          const date = new Date(dateStr);
          return date.toLocaleString('de-DE', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
          });
        },
      },
      template: `
        <k-field v-bind="$props" :label="label" :help="help" class="k-pending-requests-field">
          <div v-if="!hasRequests" class="k-pending-requests-empty">
            <k-icon type="check" />
            <p>Keine ausstehenden Zugriffsanfragen</p>
          </div>

          <div v-else class="k-pending-requests-table">
            <div
              v-for="request in items"
              :key="request.uuid"
              class="k-pending-request-row"
            >
              <details class="k-pending-request-details">
                <summary class="k-pending-request-summary">
                  <span class="k-pending-request-summary-caret" aria-hidden="true"></span>
                  <div class="k-pending-request-summary-main">
                    <code class="k-pending-request-uuid" :title="request.uuid">
                      {{ request.uuid }}
                    </code>
                    <span class="k-pending-request-summary-time">
                      {{ formatDate(request.requested_at) }}
                    </span>
                  </div>
                </summary>

                <div class="k-pending-request-body">
                  <div class="k-pending-request-info">
                    <div class="k-pending-request-field">
                      <span class="k-pending-request-field-label">IP-Adresse</span>
                      <span class="k-pending-request-field-value">{{ request.ip || 'Unknown' }}</span>
                    </div>
                    <div class="k-pending-request-field">
                      <span class="k-pending-request-field-label">Angemeldet</span>
                      <span class="k-pending-request-field-value">{{ formatDate(request.requested_at) }}</span>
                    </div>
                    <div v-if="request.user_agent" class="k-pending-request-field">
                      <span class="k-pending-request-field-label">Gerät</span>
                      <span class="k-pending-request-field-value">{{ request.user_agent }}</span>
                    </div>
                  </div>

                  <div class="k-pending-request-actions">
                    <k-button
                      icon="check"
                      theme="positive"
                      size="sm"
                      @click="approve(request.uuid)"
                      :disabled="processing === request.uuid"
                    >
                      Genehmigen
                    </k-button>
                    <k-button
                      icon="cancel"
                      theme="negative"
                      size="sm"
                      @click="deny(request.uuid)"
                      :disabled="processing === request.uuid"
                    >
                      Ablehnen
                    </k-button>
                  </div>
                </div>
              </details>
            </div>
          </div>

          <div v-if="hasDenied" class="k-denied-requests">
            <k-headline>Abgelehnte Anfragen</k-headline>
            <div class="k-pending-requests-table">
              <div
                v-for="request in deniedItems"
                :key="request.uuid"
                class="k-pending-request-row"
              >
                <details class="k-pending-request-details">
                  <summary class="k-pending-request-summary">
                    <span class="k-pending-request-summary-caret" aria-hidden="true"></span>
                    <div class="k-pending-request-summary-main">
                      <code class="k-pending-request-uuid" :title="request.uuid">
                        {{ request.uuid }}
                      </code>
                      <span class="k-pending-request-summary-time">
                        {{ formatDate(request.denied_at) }}
                      </span>
                    </div>
                  </summary>

                  <div class="k-pending-request-body">
                    <div class="k-pending-request-info">
                      <div class="k-pending-request-field">
                        <span class="k-pending-request-field-label">IP-Adresse</span>
                        <span class="k-pending-request-field-value">{{ request.ip || 'Unknown' }}</span>
                      </div>
                      <div v-if="request.user_agent" class="k-pending-request-field">
                        <span class="k-pending-request-field-label">Geraet</span>
                        <span class="k-pending-request-field-value">{{ request.user_agent }}</span>
                      </div>
                    </div>

                    <div class="k-pending-request-actions">
                      <k-button
                        icon="check"
                        theme="positive"
                        size="sm"
                        @click="allowDenied(request.uuid)"
                        :disabled="processing === request.uuid"
                      >
                        Genehmigen
                      </k-button>
                      <k-button
                        icon="trash"
                        theme="negative"
                        size="sm"
                        @click="removeDenied(request.uuid)"
                        :disabled="processing === request.uuid"
                      >
                        Entfernen
                      </k-button>
                    </div>
                  </div>
                </details>
              </div>
            </div>
          </div>
        </k-field>
      `,
    },
  },
});
