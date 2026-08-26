/**
 * Normalises the different shapes the request/device props can arrive in.
 *
 * Kirby passes the data either as a ready-made array (via the field's `props`)
 * or as the raw field value, which may still be a YAML string.
 */
export function normalizeRequests(requestsProp, valueProp) {
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
