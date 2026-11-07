import { DurationPipe } from './duration.pipe';
import { RelativeTimePipe } from './relative-time.pipe';

describe('Adversarial & Edge Case Tests: Pipes', () => {
  describe('DurationPipe Adversarial', () => {
    const pipe = new DurationPipe();

    it('should return "00:00" for negative inputs', () => {
      expect(pipe.transform(-1)).toBe('00:00');
      expect(pipe.transform(-500)).toBe('00:00');
      expect(pipe.transform(-0.01)).toBe('00:00');
    });

    it('should return "00:00" for NaN, null, and undefined', () => {
      expect(pipe.transform(NaN)).toBe('00:00');
      expect(pipe.transform(null)).toBe('00:00');
      expect(pipe.transform(undefined)).toBe('00:00');
    });

    it('should format extreme durations (100+ hours) correctly without throwing', () => {
      // 100 hours
      const hundredHours = 100 * 3600;
      expect(pipe.transform(hundredHours)).toBe('100:00:00');

      // 1000 hours, 15 minutes, 9 seconds
      const extremeTime = 1000 * 3600 + 15 * 60 + 9;
      expect(pipe.transform(extremeTime)).toBe('1000:15:09');
    });

    it('should handle fractional seconds by truncating them via Math.floor', () => {
      expect(pipe.transform(59.999)).toBe('00:59');
      expect(pipe.transform(60.1)).toBe('01:00');
      expect(pipe.transform(3661.7)).toBe('01:01:01');
    });
  });

  describe('RelativeTimePipe Adversarial', () => {
    const pipe = new RelativeTimePipe();

    it('should return "N/A" for null, undefined, empty string and garbage strings', () => {
      expect(pipe.transform(null)).toBe('N/A');
      expect(pipe.transform(undefined)).toBe('N/A');
      expect(pipe.transform('')).toBe('N/A');
      expect(pipe.transform('not-a-valid-date')).toBe('N/A');
      expect(pipe.transform('2026-99-99')).toBe('N/A');
    });

    it('should format epoch zero (1970-01-01) as localized date string without crashing', () => {
      const epochZero = new Date(0);
      const result = pipe.transform(epochZero);
      expect(result).toBe(epochZero.toLocaleDateString());
      expect(result).not.toBe('N/A');
    });

    it('should handle future dates gracefully with humanized "in X" format', () => {
      const tenSecondsFuture = new Date(Date.now() + 10 * 1000);
      const fortyFiveSecondsFuture = new Date(Date.now() + 45 * 1000);
      const oneHourFuture = new Date(Date.now() + 3605 * 1000);
      const tomorrow = new Date(Date.now() + (86400 + 10) * 1000);

      expect(pipe.transform(tenSecondsFuture)).toBe('Just now');
      expect(pipe.transform(fortyFiveSecondsFuture)).toBe('in 45s');
      expect(pipe.transform(oneHourFuture)).toBe('in 1h');
      expect(pipe.transform(tomorrow)).toBe('in 1d');
    });

    it('should accurately respect elapsed time boundaries', () => {
      const now = Date.now();

      // < 30 seconds -> 'Just now'
      expect(pipe.transform(new Date(now - 25 * 1000))).toBe('Just now');

      // 45 seconds -> '45s ago'
      expect(pipe.transform(new Date(now - 45 * 1000))).toBe('45s ago');

      // 10 minutes -> '10m ago'
      expect(pipe.transform(new Date(now - 10 * 60 * 1000))).toBe('10m ago');

      // 5 hours -> '5h ago'
      expect(pipe.transform(new Date(now - 5 * 3600 * 1000))).toBe('5h ago');

      // 12 days -> '12d ago'
      expect(pipe.transform(new Date(now - 12 * 86400 * 1000))).toBe('12d ago');

      // > 30 days -> falls back to toLocaleDateString()
      const fortyDaysAgo = new Date(now - 40 * 86400 * 1000);
      expect(pipe.transform(fortyDaysAgo)).toBe(fortyDaysAgo.toLocaleDateString());
    });
  });
});
