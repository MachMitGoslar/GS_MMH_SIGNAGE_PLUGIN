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
        approvedDevices: Array,
        screen: String,
      },
      data() {
        return {
          approvedItems: normalizeRequests(this.approvedDevices, this.requests || this.value),
        };
      },
      computed: {
        hasApproved() {
          return this.approvedItems.length > 0;
        },
      },
      watch: {
        approvedDevices(next) {
          this.approvedItems = normalizeRequests(next, this.value);
        },
        value(next) {
          this.approvedItems = normalizeRequests(this.approvedDevices, next);
        },
      },
      methods: {
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
          <div v-if="!hasApproved" class="k-pending-requests-empty">
            <k-icon type="check" />
            <p>Keine genehmigten Geräte</p>
          </div>

          <div v-else class="k-pending-requests-table">
            <div
              v-for="device in approvedItems"
              :key="device.uuid"
              class="k-pending-request-row"
            >
              <details class="k-pending-request-details">
                <summary class="k-pending-request-summary">
                  <span class="k-pending-request-summary-caret" aria-hidden="true"></span>
                  <div class="k-pending-request-summary-main">
                    <code class="k-pending-request-uuid" :title="device.uuid">
                      {{ device.label || device.uuid }}
                    </code>
                    <span class="k-pending-request-summary-time">
                      {{ formatDate(device.approved_at) }}
                    </span>
                  </div>
                </summary>

                <div class="k-pending-request-body">
                  <div class="k-pending-request-info">
                    <div class="k-pending-request-field">
                      <span class="k-pending-request-field-label">UUID</span>
                      <span class="k-pending-request-field-value">{{ device.uuid }}</span>
                    </div>
                    <div class="k-pending-request-field">
                      <span class="k-pending-request-field-label">IP-Adresse</span>
                      <span class="k-pending-request-field-value">{{ device.ip || 'Unknown' }}</span>
                    </div>
                    <div class="k-pending-request-field">
                      <span class="k-pending-request-field-label">Genehmigt</span>
                      <span class="k-pending-request-field-value">{{ formatDate(device.approved_at) }}</span>
                    </div>
                    <div class="k-pending-request-field">
                      <span class="k-pending-request-field-label">Genehmigt von</span>
                      <span class="k-pending-request-field-value">{{ device.approved_by || 'Unknown' }}</span>
                    </div>
                  </div>
                </div>
              </details>
            </div>
          </div>
        </k-field>
      `,
    },
    onboarding_requests: {
      props: {
        label: String,
        help: String,
        value: [Array, String, Object],
        requests: Array,
        deniedRequests: Array,
        approvedDevices: Array,
        screens: Array,
      },
      data() {
        const items = normalizeRequests(this.requests, this.value);
        const approvedItems = normalizeRequests(this.approvedDevices, []);
        return {
          items,
          deniedItems: normalizeRequests(this.deniedRequests, []),
          approvedItems,
          selectedScreens: {
            ...Object.fromEntries(
              items.map((request) => [request.uuid, this.screens?.[0]?.value || ''])
            ),
            ...Object.fromEntries(
              approvedItems.map((device) => [device.uuid, ''])
            ),
          },
          labels: Object.fromEntries(
            approvedItems.map((device) => [device.uuid, device.label || ''])
          ),
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
        hasApproved() {
          return this.approvedItems.length > 0;
        },
      },
      watch: {
        requests(next) {
          this.items = normalizeRequests(next, this.value);
          this.items.forEach((request) => {
            if (!this.selectedScreens[request.uuid]) {
              this.selectedScreens[request.uuid] = this.screens?.[0]?.value || '';
            }
          });
        },
        deniedRequests(next) {
          this.deniedItems = normalizeRequests(next, []);
          this.deniedItems.forEach((request) => {
            if (!(request.uuid in this.selectedScreens)) {
              this.selectedScreens[request.uuid] = this.screens?.[0]?.value || '';
            }
          });
        },
        approvedDevices(next) {
          this.approvedItems = normalizeRequests(next, []);
          this.approvedItems.forEach((device) => {
            if (!(device.uuid in this.selectedScreens)) {
              this.selectedScreens[device.uuid] = '';
            }
            this.labels[device.uuid] = device.label || '';
          });
        },
      },
      methods: {
        screenOptionsFor(deviceUuid, currentScreen = null) {
          return (this.screens || []).filter((screen) => screen.value !== currentScreen);
        },
        async approve(uuid, label = '') {
          const screen = this.selectedScreens[uuid];
          if (!screen) {
            this.$panel.notification.error('Bitte einen Bildschirm auswählen.');
            return;
          }

          this.processing = uuid;

          try {
            const response = await this.$api.post(
              'signage/approve-onboarding-request',
              {
                uuid,
                screen,
                label,
              }
            );

            if (response.status === 'success') {
              this.$panel.notification.success(response.message || 'Gerät zugewiesen');
              this.items = this.items.filter((request) => request.uuid !== uuid);
              if (this.$panel?.view?.reload) {
                this.$panel.view.reload();
              } else if (this.$reload) {
                this.$reload();
              }
            } else {
              this.$panel.notification.error(response.message || 'Zuweisung fehlgeschlagen');
            }
          } catch (error) {
            this.$panel.notification.error(error.message || 'Zuweisung fehlgeschlagen');
          } finally {
            this.processing = null;
          }
        },
        async deny(uuid) {
          if (this.processing === uuid) return;
          this.processing = uuid;

          try {
            const response = await this.$api.post(
              'signage/deny-onboarding-request',
              { uuid }
            );

            if (response.status === 'success') {
              this.$panel.notification.success(response.message || 'Anfrage abgelehnt');
              const deniedRequest = this.items.find((request) => request.uuid === uuid);
              this.items = this.items.filter((request) => request.uuid !== uuid);
              if (deniedRequest) {
                this.deniedItems = [
                  {
                    ...deniedRequest,
                    denied_at: new Date().toISOString(),
                  },
                  ...this.deniedItems.filter((request) => request.uuid !== uuid),
                ];
              }
              if (this.$panel?.view?.reload) {
                this.$panel.view.reload();
              } else if (this.$reload) {
                this.$reload();
              }
            } else {
              this.$panel.notification.error(response.message || 'Ablehnung fehlgeschlagen');
            }
          } catch (error) {
            this.$panel.notification.error(error.message || 'Ablehnung fehlgeschlagen');
          } finally {
            this.processing = null;
          }
        },
        async approveDenied(uuid) {
          const screen = this.selectedScreens[uuid];
          if (!screen) {
            this.$panel.notification.error('Bitte einen Bildschirm auswählen.');
            return;
          }

          this.processing = uuid;

          try {
            const response = await this.$api.post(
              'signage/approve-onboarding-request',
              {
                uuid,
                screen,
              }
            );

            if (response.status === 'success') {
              this.$panel.notification.success(response.message || 'Gerät genehmigt');
              this.deniedItems = this.deniedItems.filter((request) => request.uuid !== uuid);
              delete this.selectedScreens[uuid];
              if (this.$panel?.view?.reload) {
                this.$panel.view.reload();
              } else if (this.$reload) {
                this.$reload();
              }
            } else {
              this.$panel.notification.error(response.message || 'Genehmigung fehlgeschlagen');
            }
          } catch (error) {
            this.$panel.notification.error(error.message || 'Genehmigung fehlgeschlagen');
          } finally {
            this.processing = null;
          }
        },
        async removeDenied(uuid) {
          if (this.processing === uuid) return;
          this.processing = uuid;

          try {
            const response = await this.$api.post(
              'signage/remove-denied-onboarding-request',
              { uuid }
            );

            if (response.status === 'success') {
              this.$panel.notification.success(response.message || 'Eintrag entfernt');
              this.deniedItems = this.deniedItems.filter((request) => request.uuid !== uuid);
              delete this.selectedScreens[uuid];
              if (this.$panel?.view?.reload) {
                this.$panel.view.reload();
              } else if (this.$reload) {
                this.$reload();
              }
            } else {
              this.$panel.notification.error(response.message || 'Entfernen fehlgeschlagen');
            }
          } catch (error) {
            this.$panel.notification.error(error.message || 'Entfernen fehlgeschlagen');
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
        screenLabel(screenSlug) {
          const match = (this.screens || []).find((screen) => screen.value === screenSlug);
          return match ? match.text : screenSlug;
        },
        async reassignApproved(uuid, fromScreen, skipReload = false) {
          const toScreen = this.selectedScreens[uuid];
          if (!toScreen || toScreen === fromScreen) {
            if (!skipReload) {
              this.$panel.notification.error('Bitte einen anderen Monitor auswählen.');
            }
            return;
          }

          try {
            const response = await this.$api.post(
              'signage/reassign-approved-device',
              {
                uuid,
                fromScreen,
                toScreen,
              }
            );

            if (response.status === 'success') {
              if (!skipReload) {
                this.$panel.notification.success(response.message || 'Gerät verschoben');
                if (this.$panel?.view?.reload) {
                  this.$panel.view.reload();
                } else if (this.$reload) {
                  this.$reload();
                }
              }
            } else {
              this.$panel.notification.error(response.message || 'Verschieben fehlgeschlagen');
            }
          } catch (error) {
            this.$panel.notification.error(error.message || 'Verschieben fehlgeschlagen');
          }
        },
        async revokeApproved(uuid, screen) {
          if (!screen) {
            this.$panel.notification.error('Konnte den Monitor nicht ermitteln.');
            return;
          }

          this.processing = uuid;

          try {
            const response = await this.$api.post(
              'signage/revoke-approved-device',
              {
                uuid,
                screen,
              }
            );

            if (response.status === 'success') {
              this.$panel.notification.success(response.message || 'Freigabe entzogen');
              if (this.$panel?.view?.reload) {
                this.$panel.view.reload();
              } else if (this.$reload) {
                this.$reload();
              }
            } else {
              this.$panel.notification.error(response.message || 'Entzug fehlgeschlagen');
            }
          } catch (error) {
            this.$panel.notification.error(error.message || 'Entzug fehlgeschlagen');
          } finally {
            this.processing = null;
          }
        },
        async renameApproved(uuid, screen, skipReload = false) {
          const label = (this.labels[uuid] || '').trim();
          if (!screen) {
            if (!skipReload) {
              this.$panel.notification.error('Konnte den Monitor nicht ermitteln.');
            }
            return;
          }

          try {
            const response = await this.$api.post(
              'signage/rename-approved-device',
              {
                uuid,
                screen,
                label,
              }
            );

            if (response.status === 'success') {
              if (!skipReload) {
                this.$panel.notification.success(response.message || 'Gerätename aktualisiert');
                if (this.$panel?.view?.reload) {
                  this.$panel.view.reload();
                } else if (this.$reload) {
                  this.$reload();
                }
              }
            } else {
              this.$panel.notification.error(response.message || 'Umbenennen fehlgeschlagen');
            }
          } catch (error) {
            this.$panel.notification.error(error.message || 'Umbenennen fehlgeschlagen');
          }
        },
        async saveApprovedChanges(uuid, screen) {
          this.processing = uuid;

          const currentLabel = this.approvedItems.find((device) => device.uuid === uuid)?.label || '';
          const hasLabelChange = (this.labels[uuid] || '').trim() !== currentLabel.trim();
          const targetScreen = this.selectedScreens[uuid];
          const shouldMove = targetScreen && targetScreen !== screen;

          try {
            if (hasLabelChange) {
              await this.renameApproved(uuid, screen, true);
            }

            if (shouldMove) {
              await this.reassignApproved(uuid, screen, true);
            }

            if (!hasLabelChange && !shouldMove) {
              this.$panel.notification.success('Keine Änderungen vorhanden');
              return;
            }

            this.$panel.notification.success('Änderungen gespeichert');
            if (this.$panel?.view?.reload) {
              this.$panel.view.reload();
            } else if (this.$reload) {
              this.$reload();
            }
          } finally {
            this.processing = null;
          }
        },
      },
      template: `
        <k-field v-bind="$props" :label="label" :help="help" class="k-pending-requests-field">
          <div class="k-device-management-layout">
            <section class="k-device-management-column k-device-management-panel">
              <div class="k-device-management-header">
                <k-headline>Genehmigte Geräte</k-headline>
                <p class="k-device-management-copy">Freigegebene Displays verwalten, umbenennen und verschieben.</p>
              </div>
              <div v-if="!hasApproved" class="k-pending-requests-empty">
                <k-icon type="check" />
                <p>Keine genehmigten Geräte</p>
              </div>
              <div v-else class="k-pending-requests-table">
                <div
                  v-for="device in approvedItems"
                  :key="device.uuid"
                  class="k-pending-request-row"
                >
                  <details class="k-pending-request-details">
                    <summary class="k-pending-request-summary">
                      <span class="k-pending-request-summary-caret" aria-hidden="true"></span>
                      <div class="k-pending-request-summary-main">
                        <code class="k-pending-request-uuid" :title="device.uuid">
                          {{ device.label || device.uuid }}
                        </code>
                        <span class="k-pending-request-summary-time">
                          {{ device.screen_title || screenLabel(device.screen) }}
                        </span>
                      </div>
                    </summary>

                    <div class="k-pending-request-body">
                      <div class="k-pending-request-info k-pending-request-info-grid">
                        <div class="k-pending-request-field">
                          <span class="k-pending-request-field-label">UUID</span>
                          <span class="k-pending-request-field-value">{{ device.uuid }}</span>
                        </div>
                        <div class="k-pending-request-field">
                          <span class="k-pending-request-field-label">Monitor</span>
                          <span class="k-pending-request-field-value">{{ device.screen_title || screenLabel(device.screen) }}</span>
                        </div>
                        <div class="k-pending-request-field">
                          <span class="k-pending-request-field-label">Genehmigt</span>
                          <span class="k-pending-request-field-value">{{ formatDate(device.approved_at) }}</span>
                        </div>
                        <div class="k-pending-request-field">
                          <span class="k-pending-request-field-label">Genehmigt von</span>
                          <span class="k-pending-request-field-value">{{ device.approved_by || 'Unknown' }}</span>
                        </div>
                      </div>

                      <div class="k-device-management-formgrid">
                        <label class="k-device-management-control">
                          <span class="k-device-management-control-label">Gerätename</span>
                          <input
                            v-model="labels[device.uuid]"
                            class="k-input"
                            type="text"
                            placeholder="Gerätename"
                          >
                        </label>
                        <label class="k-device-management-control">
                          <span class="k-device-management-control-label">Monitor wechseln</span>
                          <select
                            v-model="selectedScreens[device.uuid]"
                            class="k-input"
                          >
                            <option disabled value="">Anderen Monitor wählen</option>
                            <option
                              v-for="screen in screenOptionsFor(device.uuid, device.screen)"
                              :key="screen.value"
                              :value="screen.value"
                            >
                              {{ screen.text }}
                            </option>
                          </select>
                        </label>
                      </div>

                      <div class="k-pending-request-actions k-pending-request-actions-emphasis">
                        <k-button
                          icon="check"
                          theme="positive"
                          size="sm"
                          @click="saveApprovedChanges(device.uuid, device.screen)"
                          :disabled="processing === device.uuid"
                        >
                          Änderungen speichern
                        </k-button>
                        <k-button
                          icon="off"
                          theme="negative"
                          size="sm"
                          @click="revokeApproved(device.uuid, device.screen)"
                          :disabled="processing === device.uuid"
                        >
                          Freigabe entziehen
                        </k-button>
                      </div>
                    </div>
                  </details>
                </div>
              </div>
            </section>

            <section class="k-device-management-column">
              <div class="k-device-management-stack">
                <section class="k-device-management-section k-device-management-panel">
                  <div class="k-device-management-header">
                    <k-headline>Ausstehende Anfragen</k-headline>
                    <p class="k-device-management-copy">Neue Geräte direkt einem Monitor zuordnen oder ablehnen.</p>
                  </div>
                  <div v-if="!hasRequests" class="k-pending-requests-empty">
                    <k-icon type="check" />
                    <p>Keine neuen Onboarding-Anfragen</p>
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
                          <div class="k-pending-request-info k-pending-request-info-grid">
                            <div class="k-pending-request-field">
                              <span class="k-pending-request-field-label">IP-Adresse</span>
                              <span class="k-pending-request-field-value">{{ request.ip || 'Unknown' }}</span>
                            </div>
                            <div v-if="request.backend" class="k-pending-request-field">
                              <span class="k-pending-request-field-label">Gerät</span>
                              <span class="k-pending-request-field-value">{{ request.user_agent || 'Unknown' }}</span>
                            </div>
                          </div>

                          <div class="k-device-management-formgrid">
                            <label class="k-device-management-control">
                              <span class="k-device-management-control-label">Zielmonitor</span>
                              <select
                                v-model="selectedScreens[request.uuid]"
                                class="k-input"
                              >
                                <option disabled value="">Monitor wählen</option>
                                <option
                                  v-for="screen in screens"
                                  :key="screen.value"
                                  :value="screen.value"
                                >
                                  {{ screen.text }}
                                </option>
                              </select>
                            </label>
                          </div>

                          <div class="k-pending-request-actions k-pending-request-actions-emphasis">
                            <k-button
                              icon="check"
                              theme="positive"
                              size="sm"
                              @click="approve(request.uuid)"
                              :disabled="processing === request.uuid"
                            >
                              Zuweisen
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
                </section>

                <section class="k-device-management-section k-device-management-panel">
                  <div class="k-device-management-header">
                    <k-headline>Abgelehnte Anfragen</k-headline>
                    <p class="k-device-management-copy">Bereits gesperrte Geräte wieder freigeben oder endgültig entfernen.</p>
                  </div>
                  <div v-if="!hasDenied" class="k-pending-requests-empty">
                    <k-icon type="check" />
                    <p>Keine abgelehnten Geräte</p>
                  </div>
                  <div v-else class="k-pending-requests-table">
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
                          <div class="k-pending-request-info k-pending-request-info-grid">
                            <div class="k-pending-request-field">
                              <span class="k-pending-request-field-label">Zuletzt gesehen</span>
                              <span class="k-pending-request-field-value">{{ formatDate(request.last_seen_at) }}</span>
                            </div>
                          </div>

                          <div class="k-device-management-formgrid">
                            <label class="k-device-management-control">
                              <span class="k-device-management-control-label">Erneut freigeben für</span>
                              <select
                                v-model="selectedScreens[request.uuid]"
                                class="k-input"
                              >
                                <option disabled value="">Monitor wählen</option>
                                <option
                                  v-for="screen in screens"
                                  :key="screen.value"
                                  :value="screen.value"
                                >
                                  {{ screen.text }}
                                </option>
                              </select>
                            </label>
                          </div>

                          <div class="k-pending-request-actions k-pending-request-actions-emphasis">
                            <k-button
                              icon="check"
                              theme="positive"
                              size="sm"
                              @click="approveDenied(request.uuid)"
                              :disabled="processing === request.uuid"
                            >
                              Doch genehmigen
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
                </section>
              </div>
            </section>
          </div>
        </k-field>
      `,
    },
  },
});
