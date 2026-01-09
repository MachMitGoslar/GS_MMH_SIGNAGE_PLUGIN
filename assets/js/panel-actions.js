/**
 * Panel Actions for Signage Plugin
 *
 * Handles approve/deny actions for pending device requests
 */

window.approveDevice = function(screenSlug, uuid) {
  fetch(`/panel/pages/signage+screens+${screenSlug}/approve-device?uuid=${encodeURIComponent(uuid)}`)
    .then(response => {
      if (response.ok) {
        window.location.reload();
      } else {
        alert('Failed to approve device');
      }
    })
    .catch(error => {
      console.error('Approve error:', error);
      alert('Error approving device');
    });
};

window.denyDevice = function(screenSlug, uuid) {
  fetch(`/panel/pages/signage+screens+${screenSlug}/deny-device?uuid=${encodeURIComponent(uuid)}`)
    .then(response => {
      if (response.ok) {
        window.location.reload();
      } else {
        alert('Failed to deny device');
      }
    })
    .catch(error => {
      console.error('Deny error:', error);
      alert('Error denying device');
    });
};

console.log('Signage panel actions loaded');
