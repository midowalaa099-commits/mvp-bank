import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { errorMessage, request } from './api';
import { dateLabel, formatMoney } from './bank';
import type { Account, Page, Session, StatusChange, Transaction } from './bank';
import {
    Button,
    CopyButton,
    Empty,
    Icon,
    Loading,
    Modal,
    Notice,
    Pagination,
    Status,
} from './ui';

export function AdminAccount({
    account: initialAccount,
    session,
    onClose,
    onChanged,
    onReceipt,
}: {
    account: Account;
    session: Session;
    onClose: () => void;
    onChanged: () => void;
    onReceipt: (transaction: Transaction) => void;
}) {
    const [account, setAccount] = useState(initialAccount);
    const [history, setHistory] = useState<Page<StatusChange> | null>(null);
    const [transactions, setTransactions] = useState<Page<Transaction> | null>(
        null,
    );
    const [tab, setTab] = useState<'status' | 'transactions'>('status');
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState('');
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const [busy, setBusy] = useState(false);
    const [reason, setReason] = useState('');
    const lock = useRef(false);
    useEffect(() => {
        let active = true;
        setLoading(true);
        setLoadError('');
        const path =
            tab === 'status'
                ? `/admin/accounts/${account.reference}/status-changes`
                : `/accounts/${account.reference}/transactions`;
        request<Page<StatusChange> | Page<Transaction>>(
            `${path}?page=${page}`,
            session.access_token,
        )
            .then((result) => {
                if (active) {
                    if (tab === 'status')
                        setHistory(result as Page<StatusChange>);
                    else setTransactions(result as Page<Transaction>);
                }
            })
            .catch((error) => {
                if (active) setLoadError(errorMessage(error));
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => {
            active = false;
        };
    }, [account.reference, page, tab, session.access_token, revision]);
    async function change(event: FormEvent) {
        event.preventDefault();
        if (lock.current) return;
        lock.current = true;
        setBusy(true);
        setError('');
        setSuccess('');
        try {
            const result = await request<{ data: Account }>(
                `/admin/accounts/${account.reference}/${account.status === 'active' ? 'freeze' : 'unfreeze'}`,
                session.access_token,
                { reason },
            );
            setAccount(result.data);
            setReason('');
            setPage(1);
            setRevision((value) => value + 1);
            setSuccess(
                `Account ${result.data.status === 'frozen' ? 'frozen' : 'unfrozen'}. The change has been recorded.`,
            );
            onChanged();
        } catch (error) {
            setError(errorMessage(error));
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    return (
        <Modal
            title="Account workspace"
            eyebrow="ADMINISTRATION"
            onClose={onClose}
            busy={busy}
        >
            <div className="flex justify-between items-center gap-4">
                <strong className="text-xl">
                    {formatMoney(account.balance)} {account.currency}
                </strong>
                <Status value={account.status} />
            </div>
            <dl className="detail-list">
                <div>
                    <dt>Owner</dt>
                    <dd>Customer #{account.user_id}</dd>
                </div>
                <div>
                    <dt>Account reference</dt>
                    <dd className="reference">
                        {account.reference}
                        <CopyButton value={account.reference} />
                    </dd>
                </div>
                <div>
                    <dt>Opened</dt>
                    <dd>{dateLabel(account.created_at)}</dd>
                </div>
            </dl>
            {error && <Notice>{error}</Notice>}
            {success && <Notice tone="success">{success}</Notice>}
            <form onSubmit={change} className="admin-action">
                <label htmlFor="status-reason">
                    Reason for{' '}
                    {account.status === 'active' ? 'freezing' : 'unfreezing'}
                </label>
                <textarea
                    id="status-reason"
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    placeholder="Explain this change for the audit history…"
                    required
                    minLength={3}
                    maxLength={500}
                    disabled={busy}
                />
                <p className="fine-print">
                    {account.status === 'active'
                        ? 'Freezing stops money entering or leaving this account.'
                        : 'Unfreezing restores money operations.'}
                </p>
                <Button
                    variant={account.status === 'active' ? 'danger' : 'primary'}
                    disabled={busy}
                >
                    {busy
                        ? 'Saving…'
                        : account.status === 'active'
                          ? 'Freeze account'
                          : 'Unfreeze account'}
                    <Icon name="shield" size={17} />
                </Button>
            </form>
            <div className="segment-control">
                <button
                    className={tab === 'status' ? 'selected' : ''}
                    onClick={() => {
                        setTab('status');
                        setPage(1);
                    }}
                >
                    Audit history
                </button>
                <button
                    className={tab === 'transactions' ? 'selected' : ''}
                    onClick={() => {
                        setTab('transactions');
                        setPage(1);
                    }}
                >
                    Transactions
                </button>
            </div>
            {loading ? (
                <Loading />
            ) : loadError ? (
                <Notice>
                    {loadError}{' '}
                    <button
                        className="text-link"
                        onClick={() => setRevision((value) => value + 1)}
                    >
                        Retry
                    </button>
                </Notice>
            ) : tab === 'status' ? (
                <>
                    {!history?.data.length ? (
                        <Empty title="No status changes yet." icon="shield">
                            Changes and their reasons will appear here.
                        </Empty>
                    ) : (
                        <div className="audit-list">
                            {history.data.map((item) => (
                                <div key={item.id}>
                                    <div className="flex justify-between gap-3">
                                        <Status value={item.to_status} />
                                        <small>
                                            {dateLabel(item.created_at, true)}
                                        </small>
                                    </div>
                                    <p>{item.reason}</p>
                                    <small>
                                        {item.performed_by.name} ·{' '}
                                        {item.from_status} → {item.to_status}
                                    </small>
                                </div>
                            ))}
                        </div>
                    )}
                    <Pagination meta={history?.meta} onChange={setPage} />
                </>
            ) : (
                <>
                    {!transactions?.data.length ? (
                        <Empty title="No transactions yet.">
                            Activity for this account will appear here.
                        </Empty>
                    ) : (
                        <div className="audit-list">
                            {transactions.data.map((item) => (
                                <button
                                    key={item.reference}
                                    onClick={() => onReceipt(item)}
                                    className="admin-transaction"
                                >
                                    <span className="capitalize">
                                        {item.type}
                                        <small>
                                            {dateLabel(item.created_at)}
                                        </small>
                                    </span>
                                    <strong>
                                        {formatMoney(item.amount)}{' '}
                                        {item.currency}
                                    </strong>
                                    <Icon name="chevron" size={16} />
                                </button>
                            ))}
                        </div>
                    )}
                    <Pagination meta={transactions?.meta} onChange={setPage} />
                </>
            )}
        </Modal>
    );
}
