import { describe, it, expect } from 'vitest';
import { formatRelativeTime } from './NotificationBell.jsx';

describe('formatRelativeTime', () => {
  const now = new Date('2026-10-30T12:00:00Z').getTime();

  it('formata o tempo real decorrido da ação', () => {
    expect(formatRelativeTime('2026-10-30T11:59:40Z', now)).toBe('agora');
    expect(formatRelativeTime('2026-10-30T11:42:00Z', now)).toBe('há 18 min');
    expect(formatRelativeTime('2026-10-30T09:00:00Z', now)).toBe('há 3h');
    expect(formatRelativeTime('2026-10-29T12:00:00Z', now)).toBe('há 1 dia');
  });

  it('não inventa tempo para datas inválidas', () => {
    expect(formatRelativeTime('não é data', now)).toBe('');
  });
});
