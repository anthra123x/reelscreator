import { DurationPipe } from './duration.pipe';

describe('DurationPipe', () => {
  const pipe = new DurationPipe();

  it('should format 0 seconds to 00:00', () => {
    expect(pipe.transform(0)).toBe('00:00');
  });

  it('should format 30 seconds to 00:30', () => {
    expect(pipe.transform(30)).toBe('00:30');
  });

  it('should format 125 seconds to 02:05', () => {
    expect(pipe.transform(125)).toBe('02:05');
  });

  it('should format hours correctly (3665s -> 01:01:05)', () => {
    expect(pipe.transform(3665)).toBe('01:01:05');
  });

  it('should handle null, undefined and negative values gracefully', () => {
    expect(pipe.transform(null)).toBe('00:00');
    expect(pipe.transform(undefined)).toBe('00:00');
    expect(pipe.transform(-5)).toBe('00:00');
  });
});
