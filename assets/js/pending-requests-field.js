/**
 * Pending Requests Panel Field
 *
 * Custom field component for managing pending device access requests
 * with approve/deny action buttons.
 */

console.log('Loading pending-requests-field.js');

panel.plugin('gs/mmh-signage', {
  fields: {
    pendingRequests: {
      props: {
        value: [Array, String, Object],
        screen: String,
      },
      data() {
        // Parse value if it's a string (YAML from Kirby)
        let requests = this.value || [];
        if (typeof requests === 'string') {
          try {
            requests = JSON.parse(requests);
          } catch (e) {
            requests = [];
          }
        }
        if (!Array.isArray(requests)) {
          requests = [];
        }

        return {
          requests: requests,
          processing: null,
        };
      },
      computed: {
        hasRequests() {
          return this.requests.length > 0;
        },
      },
      methods: {
        async approve(uuid) {
          if (this.processing === uuid) return;
          this.processing = uuid;

          try {
            const response = await this.$api.post(
              `${this.$panel.url}/fields/${this._uid}/approve`,
              {
                uuid: uuid,
                screen: this.screen,
              }
            );

            if (response.status === 'success') {
              this.$panel.notification.success(response.message || 'Device approved');
              // Remove from local list
              this.requests = this.requests.filter((r) => r.uuid !== uuid);
              this.$emit('input', this.requests);
              // Reload page to update whitelist display
              this.$reload();
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
          this.processing = uuid;

          try {
            const response = await this.$api.post(
              `${this.$panel.url}/fields/${this._uid}/deny`,
              {
                uuid: uuid,
                screen: this.screen,
              }
            );

            if (response.status === 'success') {
              this.$panel.notification.success(response.message || 'Request denied');
              // Remove from local list
              this.requests = this.requests.filter((r) => r.uuid !== uuid);
              this.$emit('input', this.requests);
            } else {
              this.$panel.notification.error(response.message || 'Failed to deny request');
            }
          } catch (error) {
            this.$panel.notification.error(error.message || 'Failed to deny request');
          } finally {
            this.processing = null;
          }
        },
        formatDate(dateStr) {
          if (!dateStr) return 'Unknown';
          const date = new Date(dateStr);
          return date.toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
          });
        },
        shortUuid(uuid) {
          return uuid ? uuid.substring(0, 8) + '...' : 'Unknown';
        },
      },
      template: `
        <k-field v-bind="$props" :label="label" :help="help" class="k-pending-requests-field">
          <div v-if="!hasRequests" class="k-empty">
            <k-icon type="check" />
            <p>No pending access requests</p>
          </div>

          <div v-else class="k-pending-requests">
            <div
              v-for="request in requests"
              :key="request.uuid"
              class="k-pending-request"
            >
              <div class="k-pending-request-info">
                <div class="k-pending-request-row">
                  <div class="k-pending-request-label">
                    <strong>UUID:</strong>
                    <code class="k-pending-request-uuid" :title="request.uuid">
                      {{ request.uuid }}
                    </code>
                  </div>
                </div>
                <div class="k-pending-request-row k-pending-request-meta">
                  <span><strong>IP:</strong> {{ request.ip || 'Unknown' }}</span>
                  <span><strong>Requested:</strong> {{ formatDate(request.requested_at) }}</span>
                </div>
                <div v-if="request.user_agent" class="k-pending-request-row k-pending-request-agent">
                  <strong>Device:</strong> {{ request.user_agent }}
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
                  Approve
                </k-button>
                <k-button
                  icon="cancel"
                  theme="negative"
                  size="sm"
                  @click="deny(request.uuid)"
                  :disabled="processing === request.uuid"
                >
                  Deny
                </k-button>
              </div>
            </div>
          </div>

          <style>
            .k-pending-requests-field .k-empty {
              display: flex;
              flex-direction: column;
              align-items: center;
              justify-content: center;
              padding: 2rem;
              color: var(--color-text-dimmed);
              background: var(--color-background);
              border-radius: var(--rounded);
            }

            .k-pending-requests-field .k-empty .k-icon {
              width: 3rem;
              height: 3rem;
              margin-bottom: 0.5rem;
              color: var(--color-positive);
            }

            .k-pending-requests {
              display: flex;
              flex-direction: column;
              gap: 1rem;
            }

            .k-pending-request {
              display: flex;
              gap: 1rem;
              padding: 1rem;
              background: var(--color-background);
              border: 1px solid var(--color-border);
              border-radius: var(--rounded);
            }

            .k-pending-request-info {
              flex: 1;
              display: flex;
              flex-direction: column;
              gap: 0.5rem;
            }

            .k-pending-request-row {
              display: flex;
              gap: 1rem;
              font-size: var(--text-sm);
            }

            .k-pending-request-label {
              display: flex;
              align-items: center;
              gap: 0.5rem;
            }

            .k-pending-request-uuid {
              font-family: var(--font-mono);
              font-size: 0.875rem;
              padding: 0.25rem 0.5rem;
              background: var(--color-gray-200);
              border-radius: var(--rounded-sm);
              user-select: all;
            }

            .k-pending-request-meta {
              color: var(--color-text-dimmed);
            }

            .k-pending-request-agent {
              font-size: var(--text-xs);
              color: var(--color-text-dimmed);
            }

            .k-pending-request-actions {
              display: flex;
              flex-direction: column;
              gap: 0.5rem;
              justify-content: center;
            }
          </style>
        </k-field>
      `,
    },
  },
});
