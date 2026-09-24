import type { PendingOperation, Session } from './bank.ts';

export class ApiError extends Error {
    status: number;
    errors: Record<string, string[]>;
    constructor(
        message: string,
        status = 0,
        errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}
export async function request<T>(
    path: string,
    token?: string,
    body?: unknown,
    key?: string,
): Promise<T> {
    let response: Response;
    try {
        response = await fetch(`/api/v1${path}`, {
            method: body === undefined ? 'GET' : 'POST',
            headers: {
                Accept: 'application/json',
                ...(body === undefined
                    ? {}
                    : { 'Content-Type': 'application/json' }),
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
                ...(key ? { 'Idempotency-Key': key } : {}),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
            credentials: 'omit',
            cache: 'no-store',
            signal: AbortSignal.timeout(20000),
        });
    } catch {
        throw new ApiError(
            'We couldn’t reach the bank. Check your connection and try again.',
        );
    }
    if (response.status === 401 && token) {
        window.dispatchEvent(
            new CustomEvent('bank:session-expired', { detail: token }),
        );
    }
    if (response.status === 204) return undefined as T;
    let result;
    try {
        result = await response.json();
    } catch {
        throw new ApiError(
            'The bank returned an unexpected response. Please try again.',
            response.ok ? 0 : response.status,
        );
    }
    if (!response.ok) {
        const message =
            response.status === 429
                ? 'A little too fast. Please wait a minute and try again.'
                : response.status >= 500
                  ? 'The bank is temporarily unavailable. Please try again.'
                  : result.message || 'Something went wrong. Please try again.';
        throw new ApiError(message, response.status, result.errors || {});
    }
    return result as T;
}
export function errorMessage(error: unknown): string {
    return error instanceof Error
        ? error.message
        : 'Something went wrong. Please try again.';
}
export function readSession(): Session | null {
    try {
        const value = JSON.parse(
            sessionStorage.getItem('mvp-bank.session') || 'null',
        );
        if (
            value?.access_token &&
            value?.user?.id &&
            Date.parse(value.expires_at) > Date.now()
        )
            return value;
    } catch {
        /* Unavailable or invalid browser storage starts a fresh session. */
    }
    return null;
}
export function saveSession(session: Session | null): void {
    if (session)
        sessionStorage.setItem('mvp-bank.session', JSON.stringify(session));
    else sessionStorage.removeItem('mvp-bank.session');
}
export function readPending(userId: number): PendingOperation | null {
    try {
        return JSON.parse(
            sessionStorage.getItem(`mvp-bank.pending.${userId}`) || 'null',
        );
    } catch {
        return null;
    }
}
export function savePending(
    userId: number,
    operation: PendingOperation | null,
): void {
    if (operation)
        sessionStorage.setItem(
            `mvp-bank.pending.${userId}`,
            JSON.stringify(operation),
        );
    else sessionStorage.removeItem(`mvp-bank.pending.${userId}`);
}
