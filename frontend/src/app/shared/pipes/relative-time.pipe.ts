import { Pipe, PipeTransform } from '@angular/core';

@Pipe({
  name: 'relativeTime',
  standalone: true,
})
export class RelativeTimePipe implements PipeTransform {
  transform(value: string | Date | null | undefined): string {
    if (!value) {
      return 'N/A';
    }

    const date = typeof value === 'string' ? new Date(value) : value;
    if (isNaN(date.getTime())) {
      return 'N/A';
    }

    const now = new Date();
    const diff = (now.getTime() - date.getTime()) / 1000;

    // Past timestamps
    if (diff >= 0) {
      const elapsedSeconds = Math.floor(diff);
      if (elapsedSeconds < 30) {
        return 'Just now';
      }
      if (elapsedSeconds < 60) {
        return `${elapsedSeconds}s ago`;
      }

      const elapsedMinutes = Math.floor(elapsedSeconds / 60);
      if (elapsedMinutes < 60) {
        return `${elapsedMinutes}m ago`;
      }

      const elapsedHours = Math.floor(elapsedMinutes / 60);
      if (elapsedHours < 24) {
        return `${elapsedHours}h ago`;
      }

      const elapsedDays = Math.floor(elapsedHours / 24);
      if (elapsedDays < 30) {
        return `${elapsedDays}d ago`;
      }

      return date.toLocaleDateString();
    }

    // Future timestamps (diff < 0)
    const futureSeconds = Math.round(Math.abs(diff));
    if (futureSeconds < 30) {
      return 'Just now';
    }
    if (futureSeconds < 60) {
      return `in ${futureSeconds}s`;
    }

    const futureMinutes = Math.floor(futureSeconds / 60);
    if (futureMinutes < 60) {
      return `in ${futureMinutes}m`;
    }

    const futureHours = Math.floor(futureMinutes / 60);
    if (futureHours < 24) {
      return `in ${futureHours}h`;
    }

    const futureDays = Math.floor(futureHours / 24);
    if (futureDays < 30) {
      return `in ${futureDays}d`;
    }

    return date.toLocaleDateString();
  }
}
