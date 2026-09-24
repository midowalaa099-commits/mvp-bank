import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { ApiError, errorMessage, request, savePending } from './api';
import {
    formatMoney,
    minorUnits,
    normalizeAmount,
    shortReference,
} from './bank';
import type {
    Account,
    Operation,
    PendingOperation,
    Session,
    Transaction,
} from './bank';
import { Button, Field, Icon, Modal, Notice } from './ui';

const labels = {
    deposit: 'Add money',
    withdrawal: 'Withdraw money',
    transfer: 'Send money',
};
const endpoints = {
    deposit: 'deposits',
    withdrawal: 'withdrawals',
    transfer: 'transfers',
};

export function MoneyDialog({
    session,
    account,
    kind,
    pending,
    onPending,
    onClose,
    onComplete,
}: {
    session: Session;
    account: Account;
    kind: Operation;
    pending: PendingOperation | null;
    onPending: (value: PendingOperation | null) => void;
    onClose: () => void;
    onComplete: (transaction: Transaction) => void;
}) {
    const [amount, setAmount] = useState(pending?.body.amount || '');
    const [destination, setDestination] = useState(
        pending?.body.destination_account || '',
    );
    const [review, setReview] = useState(!!pending);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const lock = useRef(false);
    const attempt = useRef<PendingOperation | null>(pending);
    function prepare(event: FormEvent) {
        event.preventDefault();
        setError('');
        setErrors({});
        try {
            const normalized = normalizeAmount(amount);
            if (
                kind !== 'deposit' &&
                minorUnits(normalized) > minorUnits(account.balance)
            )
                throw new Error(
                    'Your available balance is too low for this amount.',
                );
            if (kind === 'transfer') {
                if (
                    !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
                        destination.trim(),
                    )
                )
                    throw new Error(
                        'Enter the recipient’s full account reference.',
                    );
                if (
                    destination.trim().toLowerCase() ===
                    account.reference.toLowerCase()
                )
                    throw new Error(
                        'Choose a different account to send money to.',
                    );
            }
            setAmount(normalized);
            setDestination(destination.trim());
            setReview(true);
        } catch (error) {
            setError(errorMessage(error));
        }
    }
    async function confirm() {
        if (lock.current) return;
        lock.current = true;
        setBusy(true);
        setError('');
        try {
            const operation = attempt.current || {
                account: account.reference,
                kind,
                key: crypto.randomUUID(),
                body: {
                    amount,
                    ...(kind === 'transfer'
                        ? { destination_account: destination }
                        : {}),
                },
            };
            savePending(session.user.id, operation);
            attempt.current = operation;
            onPending(operation);
            const result = await request<{ data: Transaction }>(
                `/accounts/${encodeURIComponent(operation.account)}/${endpoints[operation.kind]}`,
                session.access_token,
                operation.body,
                operation.key,
            );
            savePending(session.user.id, null);
            attempt.current = null;
            onPending(null);
            onComplete(result.data);
        } catch (error) {
            const definitive =
                error instanceof ApiError &&
                error.status >= 400 &&
                error.status < 500 &&
                error.status !== 401;
            if (definitive) {
                savePending(session.user.id, null);
                attempt.current = null;
                onPending(null);
                setReview(false);
                setErrors(error.errors);
            }
            setError(
                definitive
                    ? errorMessage(error)
                    : 'We couldn’t confirm the result. Retry this same operation to check it safely; it will not move money twice.',
            );
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    return (
        <Modal
            title={review ? 'Make sure it’s right.' : labels[kind]}
            eyebrow={review ? 'REVIEW & CONFIRM' : 'YOUR MONEY, IN MOTION'}
            onClose={onClose}
            busy={busy}
        >
            {error && <Notice>{error}</Notice>}
            {attempt.current && !error && (
                <Notice tone="info">
                    An earlier operation needs confirmation. Retry it safely
                    using its original details.
                </Notice>
            )}
            {review ? (
                <>
                    <div className="review-amount">
                        <span>{labels[kind]}</span>
                        <strong>
                            {formatMoney(amount)}{' '}
                            <small>{account.currency}</small>
                        </strong>
                    </div>
                    <dl className="detail-list">
                        <div>
                            <dt>
                                {kind === 'deposit'
                                    ? 'To account'
                                    : 'From account'}
                            </dt>
                            <dd>{shortReference(account.reference)}</dd>
                        </div>
                        {kind === 'transfer' && (
                            <div>
                                <dt>Recipient account</dt>
                                <dd className="reference">{destination}</dd>
                            </div>
                        )}
                        <div>
                            <dt>Operation</dt>
                            <dd>Simulated {kind}</dd>
                        </div>
                    </dl>
                    <Notice tone="info">
                        {kind === 'transfer'
                            ? 'Check the full recipient reference carefully. Completed transfers cannot be undone here.'
                            : 'This is a demo. No real money will move.'}
                    </Notice>
                    <div className="modal-actions">
                        {!attempt.current && (
                            <Button
                                variant="secondary"
                                disabled={busy}
                                onClick={() => {
                                    setReview(false);
                                    setError('');
                                }}
                            >
                                Edit details
                            </Button>
                        )}
                        <Button
                            className="flex-1"
                            disabled={busy}
                            onClick={confirm}
                        >
                            {busy
                                ? 'Processing…'
                                : attempt.current
                                  ? 'Retry safely'
                                  : 'Confirm ' +
                                    (kind === 'transfer' ? 'transfer' : kind)}
                            <Icon name="arrow" size={18} />
                        </Button>
                    </div>
                </>
            ) : (
                <form onSubmit={prepare} className="flex flex-col gap-5">
                    <div className="source-account">
                        <span className="account-mini-icon">
                            <Icon name="account" />
                        </span>
                        <div>
                            <strong>Everyday account</strong>
                            <small>
                                {formatMoney(account.balance)}{' '}
                                {account.currency} available
                            </small>
                        </div>
                        <span className="currency-tag">{account.currency}</span>
                    </div>
                    {kind === 'transfer' && (
                        <Field
                            label="Recipient account reference"
                            value={destination}
                            onChange={(event) =>
                                setDestination(event.target.value)
                            }
                            placeholder="Paste their full account reference"
                            required
                            error={errors.destination_account?.[0]}
                            hint="Ask the recipient to copy it from their Account page."
                        />
                    )}
                    <Field
                        label={`Amount (${account.currency})`}
                        value={amount}
                        onChange={(event) => setAmount(event.target.value)}
                        inputMode="decimal"
                        placeholder="0.00"
                        required
                        maxLength={22}
                        className="amount-input"
                        error={errors.amount?.[0]}
                    />
                    <p className="fine-print">
                        {kind === 'deposit'
                            ? 'Add simulated funds to explore your account.'
                            : kind === 'withdrawal'
                              ? 'Remove simulated funds from your account.'
                              : 'Transfers are available between accounts in the same currency.'}
                    </p>
                    <Button disabled={account.status === 'frozen'}>
                        Review {kind}
                        <Icon name="arrow" size={18} />
                    </Button>
                </form>
            )}
        </Modal>
    );
}
