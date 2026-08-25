<template>
  <k-field
    v-bind="$props"
    :label="label"
    :help="help"
    class="k-pending-requests-field"
  >
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
            <span
              class="k-pending-request-summary-caret"
              aria-hidden="true"
            ></span>
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
                <span class="k-pending-request-field-value">
                  {{ device.uuid }}
                </span>
              </div>
              <div class="k-pending-request-field">
                <span class="k-pending-request-field-label">IP-Adresse</span>
                <span class="k-pending-request-field-value">
                  {{ device.ip || 'Unknown' }}
                </span>
              </div>
              <div class="k-pending-request-field">
                <span class="k-pending-request-field-label">Genehmigt</span>
                <span class="k-pending-request-field-value">
                  {{ formatDate(device.approved_at) }}
                </span>
              </div>
              <div class="k-pending-request-field">
                <span class="k-pending-request-field-label">Genehmigt von</span>
                <span class="k-pending-request-field-value">
                  {{ device.approved_by || 'Unknown' }}
                </span>
              </div>
            </div>
          </div>
        </details>
      </div>
    </div>
  </k-field>
</template>

<script>
import { normalizeRequests } from '../../utils/requests.js';

export default {
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
      approvedItems: normalizeRequests(
        this.approvedDevices,
        this.requests || this.value
      ),
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
};
</script>
