import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { ApiErrorResponse } from '../models/common.model';

export class AppApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly validationErrors?: Record<string, string[]>,
    public readonly originalError?: HttpErrorResponse
  ) {
    super(message);
    this.name = 'AppApiError';
  }
}

export const apiErrorInterceptor: HttpInterceptorFn = (req, next) => {
  return next(req).pipe(
    catchError((error: HttpErrorResponse) => {
      let formattedMessage = 'An unexpected server error occurred';
      let validationErrors: Record<string, string[]> | undefined;

      if (error.error && typeof error.error === 'object') {
        const payload = error.error as ApiErrorResponse;
        if (payload.message) {
          formattedMessage = payload.message;
        }
        if (payload.errors) {
          validationErrors = payload.errors;
          // Prepend first validation error if available
          const firstField = Object.keys(payload.errors)[0];
          if (firstField && payload.errors[firstField]?.length > 0) {
            formattedMessage = `${payload.message}: ${payload.errors[firstField][0]}`;
          }
        }
      } else if (error.status === 0) {
        formattedMessage = 'Unable to reach ReelForge server. Please check your network connection.';
      } else if (error.status === 404) {
        formattedMessage = 'The requested resource was not found.';
      }

      return throwError(
        () => new AppApiError(formattedMessage, error.status, validationErrors, error)
      );
    })
  );
};
