/**
 * Pending Requests Section
 *
 * Custom panel section for managing pending device access requests
 */

panel.plugin('gs/mmh-signage', {
  sections: {
    pendingRequests: {
      props: {
        headline: String,
        requests: Array,
        screen: String,
      },
      data() {
        return {
          processing: null,
        };
      },
      computed: {
        hasRequests() {
          return this.requests && this.requests.length > 0;
        },
      },
      methods: {
        async approve(uuid) {
          if (this.processing === uuid) return;
          this.processing = uuid;

          try {
            const response = await this.$api.post(this.$parent.path + '/sections/' + this.name + '/approve', {
              uuid: uuid,
            });

            if (response.status === 'success') {
              this.$panel.notification.success(response.message);
              this.$reload();
            } else {
              this.$panel.notification.error(response.message);
            }
          } catch (error) {
            this.$panel.notification.error('Failed to approve device');
          } finally {
            this.processing = null;
          }
        },
        async deny(uuid) {
          if (this.processing === uuid) return;
          this.processing = uuid;

          try {
            const response = await this.$api.post(this.$parent.path + '/sections/' + this.name + '/deny', {
              uuid: uuid,
            });

            if (response.status === 'success') {
              this.$panel.notification.success(response.message);
              this.$reload();
            } else {
              this.$panel.notification.error(response.message);
            }
          } catch (error) {
            this.$panel.notification.error('Failed to deny request');
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
      },
      template: `
        <section class="k-pending-requests-section">
          <header class="k-section-header">
            <k-headline>{{ headline }}</k-headline>
          </header>

          <div v-if="!hasRequests" class="k-empty">
            <k-icon type="check" />
            <p>No pending access requests</p>
          </div>

          <div v-else class="k-pending-list">
            <div
              v-for="request in requests"
              :key="request.uuid"
              class="k-pending-item"
            >
              <div class="k-pending-info">
                <div class="k-pending-uuid">
                  <strong>UUID:</strong>
                  <code>{{ request.uuid }}</code>
                </div>
                <div class="k-pending-meta">
                  <span><strong>IP:</strong> {{ request.ip }}</span>
                  <span><strong>Requested:</strong> {{ formatDate(request.requested_at) }}</span>
                </div>
                <div v-if="request.user_agent" class="k-pending-agent">
                  <strong>Device:</strong> {{ request.user_agent }}
                </div>
              </div>

              <div class="k-pending-actions">
                <k-button
                  icon="check"
                  theme="positive"
                  @click="approve(request.uuid)"
                  :disabled="processing === request.uuid"
                >
                  Approve
                </k-button>
                <k-button
                  icon="cancel"
                  theme="negative"
                  @click="deny(request.uuid)"
                  :disabled="processing === request.uuid"
                >
                  Deny
                </k-button>
              </div>
            </div>
          </div>

          <style>
            .k-pending-requests-section .k-empty {
              display: flex;
              flex-direction: column;
              align-items: center;
              justify-content: center;
              padding: 2rem;
              color: var(--color-text-dimmed);
              background: var(--color-background);
              border-radius: var(--rounded);
            }

            .k-pending-requests-section .k-empty .k-icon {
              width: 3rem;
              height: 3rem;
              margin-bottom: 0.5rem;
              color: var(--color-positive);
            }

            .k-pending-list {
              display: flex;
              flex-direction: column;
              gap: 1rem;
            }

            .k-pending-item {
              display: flex;
              gap: 1rem;
              padding: 1rem;
              background: var(--color-background);
              border: 1px solid var(--color-border);
              border-radius: var(--rounded);
            }

            .k-pending-info {
              flex: 1;
              display: flex;
              flex-direction: column;
              gap: 0.5rem;
            }

            .k-pending-uuid code {
              font-family: var(--font-mono);
              font-size: 0.875rem;
              padding: 0.25rem 0.5rem;
              background: var(--color-gray-200);
              border-radius: var(--rounded-sm);
              user-select: all;
              margin-left: 0.5rem;
            }

            .k-pending-meta {
              display: flex;
              gap: 1rem;
              font-size: var(--text-sm);
              color: var(--color-text-dimmed);
            }

            .k-pending-agent {
              font-size: var(--text-xs);
              color: var(--color-text-dimmed);
            }

            .k-pending-actions {
              display: flex;
              flex-direction: column;
              gap: 0.5rem;
              justify-content: center;
            }
          </style>
        </section>
      `,
    },
  },
});
