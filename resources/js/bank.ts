export interface User {
    id: number;
    name: string;
    email: string;
    role: 'customer' | 'admin';
}
export interface Account {
    reference: string;
    currency: string;
    balance: string;
    status: 'active' | 'frozen';
    user_id?: number;
    created_at: string;
}
export interface Transaction {
    reference: string;
    type: 'deposit' | 'withdrawal' | 'transfer';
    currency: string;
    amount: string;
    source_account: string | null;
    destination_account: string | null;
    created_at: string;
}
export interface StatusChange {
    id: number;
    from_status: string;
    to_status: string;
    reason: string;
    performed_by: User;
    created_at: string;
}
export interface Page<T> {
    data: T[];
    meta: { current_page: number; last_page: number; total: number };
}
export interface Session {
    access_token: string;
    expires_at: string;
    user: User;
}
export type Operation = 'deposit' | 'withdrawal' | 'transfer';
export interface PendingOperation {
    account: string;
    kind: Operation;
    key: string;
    body: { amount: string; destination_account?: string };
}

export function minorUnits(value: string): bigint {
    if (!/^\d+(\.\d{1,2})?$/.test(value))
        throw new Error('Enter a valid amount with up to two decimal places.');
    const [whole, fraction = ''] = value.split('.');
    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}
export function normalizeAmount(value: string): string {
    const minor = minorUnits(value.trim());
    if (minor <= 0n) throw new Error('Enter an amount greater than zero.');
    if (minor > 9223372036854775807n)
        throw new Error('This amount exceeds the supported limit.');
    return `${minor / 100n}.${(minor % 100n).toString().padStart(2, '0')}`;
}
export function formatMoney(value: string): string {
    const [whole, fraction = ''] = value.split('.');
    return `${BigInt(whole).toLocaleString('en-US')}.${fraction.padEnd(2, '0')}`;
}
export function formatMinor(value: bigint): string {
    return formatMoney(
        `${value / 100n}.${(value % 100n).toString().padStart(2, '0')}`,
    );
}
export function isIncoming(
    transaction: Transaction,
    account?: Account,
): boolean {
    return account
        ? transaction.destination_account === account.reference
        : transaction.type === 'deposit';
}
export function dateLabel(value: string, withTime = false): string {
    return new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    }).format(new Date(value));
}
export function shortReference(value: string): string {
    return `${value.slice(0, 8)}…${value.slice(-4)}`;
}
export function initials(name: string): string {
    return name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}
