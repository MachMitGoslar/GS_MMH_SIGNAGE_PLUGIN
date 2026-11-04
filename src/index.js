// Field imports
import OnboardingRequests from './panel_components/fields/OnboardingRequests.vue';
import PendingRequests from './panel_components/fields/PendingRequests.vue';
import SignagePreview from './panel_components/fields/SignagePreview.vue';
import SlideMigration from './panel_components/fields/SlideMigration.vue';

// Writer marks imports
import TextColor from './panel_components/writer_marks/TextColor.vue';

panel.plugin('gs-mmh/mmh-signage-plugin', {
  writerMarks: {
    textcolor: TextColor,
  },
  fields: {
    pending_requests: PendingRequests,
    onboarding_requests: OnboardingRequests,
    signage_preview: SignagePreview,
    slide_migration: SlideMigration,
  },
});
