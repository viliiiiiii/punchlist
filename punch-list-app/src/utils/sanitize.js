const SECTION_LOOKUP = {
  bedroom: 'Bedroom',
  bathroom: 'Bathroom',
  balcony: 'Balcony',
  living: 'Living',
  entry: 'Entry',
  other: 'Other',
};

const SEVERITY_LOOKUP = {
  low: 'low',
  medium: 'medium',
  high: 'high',
};

const STATUS_LOOKUP = {
  open: 'open',
  'in progress': 'in_progress',
  in_progress: 'in_progress',
  'in-progress': 'in_progress',
  done: 'done',
  complete: 'done',
  completed: 'done',
};

export function sanitizeText(value, fallback = '') {
  if (typeof value === 'number') {
    return String(value);
  }
  if (typeof value === 'string') {
    const trimmed = value.trim();
    return trimmed.length > 0 ? trimmed : fallback;
  }
  return fallback;
}

export function sanitizeBuilding(value) {
  const text = sanitizeText(value, '');
  return text || 'Unassigned';
}

export function sanitizeRoom(value) {
  const text = sanitizeText(value, '');
  return text || '—';
}

export function sanitizeSection(value) {
  const text = sanitizeText(value, '');
  const key = text.toLowerCase();
  return SECTION_LOOKUP[key] || 'Other';
}

export function sanitizeSeverity(value) {
  const text = sanitizeText(value, '').toLowerCase();
  return SEVERITY_LOOKUP[text] || 'medium';
}

export function sanitizeStatus(value) {
  const text = sanitizeText(value, '').toLowerCase().replace(/[\s-]+/g, '_');
  return STATUS_LOOKUP[text] || 'open';
}

export function formatStatusLabel(value) {
  const normalized = sanitizeStatus(value);
  return normalized
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

export function formatTitle(value) {
  return sanitizeText(value, 'Untitled Task');
}

export function formatDescription(value) {
  const text = sanitizeText(value, '');
  return text || 'No description provided.';
}

export function formatAssignee(value) {
  const text = sanitizeText(value, '');
  return text || 'Unassigned';
}

export function formatDueDate(value) {
  const text = sanitizeText(value, '');
  return text || 'No due date';
}

export function formatDueDateShort(value) {
  const text = sanitizeText(value, '');
  return text || '—';
}

export function toSlug(value, fallback = 'item') {
  const text = sanitizeText(value, '');
  if (!text) return fallback;
  const slug = text
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
  return slug || fallback;
}
