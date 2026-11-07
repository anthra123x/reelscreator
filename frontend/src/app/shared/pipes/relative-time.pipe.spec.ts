import { RelativeTimePipe } from './relative-time.pipe';

describe('RelativeTimePipe', () => {
  const pipe = new RelativeTimePipe();

  it('should return N/A for null or invalid date', () => {
    expect(pipe.transform(null)).toBe('N/A');
    expect(pipe.transform('invalid-date')).toBe('N/A');
  });

  it('should return "Just now" for recent timestamps', () => {
    const now = new Date();
    expect(pipe.transform(now.toISOString())).toBe('Just now');
  });

  it('should return "Xm ago" for minutes elapsed', () => {
    const fiveMinAgo = new Date(Date.now() - 5 * 60 * 1000);
    expect(pipe.transform(fiveMinAgo.toISOString())).toBe('5m ago');
  });

  it('should return "Xh ago" for hours elapsed', () => {
    const twoHoursAgo = new Date(Date.now() - 2 * 3600 * 1000);
    expect(pipe.transform(twoHoursAgo.toISOString())).toBe('2h ago');
  });

  it('should return "Xd ago" for days elapsed', () => {
    const threeDaysAgo = new Date(Date.now() - 3 * 86400 * 1000);
    expect(pipe.transform(threeDaysAgo.toISOString())).toBe('3d ago');
  });
});
