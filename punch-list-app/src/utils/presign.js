export function resolvePresignUrl(base, action) {
  if (!base) return '';
  let trimmed = base.trim();
  if (!trimmed) return '';
  trimmed = trimmed.replace(/\/+$/, '');
  if (trimmed.endsWith(`/presign/${action}`)) return trimmed;
  if (trimmed.endsWith('/presign')) return `${trimmed}/${action}`;
  if (trimmed.endsWith(`/${action}`)) return trimmed;
  return `${trimmed}/presign/${action}`;
}

export async function postPresign(base, action, payload) {
  const url = resolvePresignUrl(base, action);
  if (!url) {
    throw new Error('Presign endpoint is not configured.');
  }
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  });
  if (!response.ok) {
    let details = '';
    try {
      const data = await response.json();
      details = data?.error ? `: ${data.error}` : '';
    } catch (err) {
      details = '';
    }
    throw new Error(`Presign ${action} request failed${details}`);
  }
  return response.json();
}
