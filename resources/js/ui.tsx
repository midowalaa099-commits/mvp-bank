import { useEffect, useId, useRef, useState } from 'react';
import type {
    ButtonHTMLAttributes,
    InputHTMLAttributes,
    ReactNode,
} from 'react';
import { dateLabel, formatMoney, shortReference } from './bank';
import type { Page, Transaction } from './bank';

const paths: Record<string, ReactNode> = {
    overview: (
        <>
            <rect x="3" y="3" width="7" height="7" rx="1.5" />
            <rect x="14" y="3" width="7" height="7" rx="1.5" />
            <rect x="3" y="14" width="7" height="7" rx="1.5" />
            <rect x="14" y="14" width="7" height="7" rx="1.5" />
        </>
    ),
    arrow: (
        <>
            <path d="M5 12h14M13 6l6 6-6 6" />
        </>
    ),
    send: (
        <>
            <path d="m5 19 14-14M5 5h14v14" />
        </>
    ),
    down: (
        <>
            <path d="M12 4v16m-6-6 6 6 6-6" />
        </>
    ),
    up: (
        <>
            <path d="M12 20V4m-6 6 6-6 6 6" />
        </>
    ),
    history: (
        <>
            <path d="M4 7h16M4 12h16M4 17h10" />
            <circle cx="19" cy="17" r="2" />
        </>
    ),
    account: (
        <>
            <rect x="3" y="5" width="18" height="14" rx="3" />
            <path d="M3 10h18M7 15h4" />
        </>
    ),
    shield: (
        <>
            <path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z" />
            <path d="m8.5 12 2.5 2.5 4.5-5" />
        </>
    ),
    logout: (
        <>
            <path d="M9 4H4v16h5M10 12h11m-4-4 4 4-4 4" />
        </>
    ),
    plus: <path d="M12 5v14M5 12h14" />,
    eye: (
        <>
            <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" />
            <circle cx="12" cy="12" r="3" />
        </>
    ),
    hidden: (
        <>
            <path d="m3 3 18 18M9.5 5.3A10 10 0 0 1 12 5c6 0 10 7 10 7a18 18 0 0 1-3 3.5M6 6.5A18 18 0 0 0 2 12s4 7 10 7c2 0 3.7-.7 5.2-1.7" />
        </>
    ),
    copy: (
        <>
            <rect x="8" y="8" width="12" height="13" rx="2" />
            <path d="M16 8V3H3v13h5" />
        </>
    ),
    check: <path d="m5 12 4 4L19 6" />,
    close: <path d="m6 6 12 12M6 18 18 6" />,
    refresh: (
        <>
            <path d="M20 8a8 8 0 1 0 0 8M20 3v5h-5" />
        </>
    ),
    lock: (
        <>
            <rect x="5" y="10" width="14" height="11" rx="2" />
            <path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3" />
        </>
    ),
    chevron: <path d="m9 5 7 7-7 7" />,
    spark: (
        <>
            <path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3Z" />
        </>
    ),
    info: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v6M12 7v.5" />
        </>
    ),
};
export function Icon({
    name,
    size = 20,
    className = '',
}: {
    name: string;
    size?: number;
    className?: string;
}) {
    return (
        <svg
            className={className}
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.65"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {paths[name] || paths.account}
        </svg>
    );
}
export function Brand({ small = false }: { small?: boolean }) {
    return (
        <div className={`brand ${small ? 'brand-small' : ''}`}>
            <span className="brand-mark">
                <svg viewBox="0 0 32 32" fill="none" aria-hidden="true">
                    <path
                        d="M5 24V9l11 10L27 9v15M16 19v9"
                        stroke="currentColor"
                        strokeWidth="3.5"
                        strokeLinejoin="round"
                    />
                </svg>
            </span>
            <span>
                mvp<span className="brand-bank">bank</span>
                <span className="brand-dot">.</span>
            </span>
        </div>
    );
}
export function Button({
    children,
    variant = 'primary',
    className = '',
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
}) {
    return (
        <button className={`btn btn-${variant} ${className}`} {...props}>
            {children}
        </button>
    );
}
export function Field({
    label,
    error,
    hint,
    ...props
}: InputHTMLAttributes<HTMLInputElement> & {
    label: string;
    error?: string;
    hint?: string;
}) {
    const id = useId();
    return (
        <div className="field">
            <label htmlFor={id}>{label}</label>
            <input
                id={id}
                aria-invalid={!!error}
                aria-describedby={error || hint ? `${id}-help` : undefined}
                {...props}
            />
            {(error || hint) && (
                <span
                    id={`${id}-help`}
                    className={error ? 'field-error' : 'field-hint'}
                >
                    {error || hint}
                </span>
            )}
        </div>
    );
}
export function Notice({
    children,
    tone = 'error',
}: {
    children: ReactNode;
    tone?: 'error' | 'info' | 'success';
}) {
    return (
        <div
            className={`notice notice-${tone}`}
            role={tone === 'error' ? 'alert' : 'status'}
        >
            <Icon name={tone === 'success' ? 'check' : 'info'} size={18} />
            <div>{children}</div>
        </div>
    );
}
export function Loading({
    label = 'Getting things ready…',
}: {
    label?: string;
}) {
    return (
        <div className="loading" role="status">
            <span className="spinner" />
            {label}
        </div>
    );
}
export function Empty({
    icon = 'history',
    title,
    children,
    action,
}: {
    icon?: string;
    title: string;
    children: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="empty">
            <span className="empty-icon">
                <Icon name={icon} size={25} />
            </span>
            <h3>{title}</h3>
            <p>{children}</p>
            {action}
        </div>
    );
}
export function Status({ value }: { value: string }) {
    return (
        <span className={`status status-${value}`}>
            <span />
            {value}
        </span>
    );
}
export function CopyButton({
    value,
    label = 'Copy account reference',
}: {
    value: string;
    label?: string;
}) {
    const [copied, setCopied] = useState(false);
    const [error, setError] = useState(false);
    useEffect(() => {
        if (!copied) return;
        const id = setTimeout(() => setCopied(false), 2000);
        return () => clearTimeout(id);
    }, [copied]);
    return (
        <>
            <button
                className="icon-button"
                aria-label={copied ? 'Copied' : label}
                title={copied ? 'Copied!' : label}
                onClick={async () => {
                    try {
                        await navigator.clipboard.writeText(value);
                        setCopied(true);
                        setError(false);
                    } catch {
                        setError(true);
                    }
                }}
            >
                <Icon name={copied ? 'check' : 'copy'} size={17} />
            </button>
            <span className={error ? 'field-error' : 'sr-only'} role="status">
                {error
                    ? 'Select the reference to copy it manually.'
                    : copied
                      ? 'Copied to clipboard'
                      : ''}
            </span>
        </>
    );
}
export function Pagination({
    meta,
    onChange,
    busy = false,
}: {
    meta?: Page<unknown>['meta'];
    onChange: (page: number) => void;
    busy?: boolean;
}) {
    if (!meta || meta.last_page <= 1) return null;
    return (
        <div className="pagination">
            <span>
                Page {meta.current_page} of {meta.last_page} · {meta.total}{' '}
                records
            </span>
            <div className="flex gap-2">
                <Button
                    variant="secondary"
                    disabled={busy || meta.current_page <= 1}
                    onClick={() => onChange(meta.current_page - 1)}
                >
                    Previous
                </Button>
                <Button
                    variant="secondary"
                    disabled={busy || meta.current_page >= meta.last_page}
                    onClick={() => onChange(meta.current_page + 1)}
                >
                    Next
                </Button>
            </div>
        </div>
    );
}
export function Modal({
    title,
    eyebrow,
    children,
    onClose,
    busy = false,
}: {
    title: string;
    eyebrow?: string;
    children: ReactNode;
    onClose: () => void;
    busy?: boolean;
}) {
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const heading = useRef<HTMLHeadingElement>(null);
    useEffect(() => {
        const previous = document.activeElement as HTMLElement | null;
        const dialog = ref.current;
        dialog?.showModal();
        heading.current?.focus();
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            dialog?.close();
            document.body.style.overflow = overflow;
            previous?.focus();
        };
    }, []);
    useEffect(() => {
        heading.current?.focus();
    }, [title]);
    return (
        <dialog
            className="modal"
            ref={ref}
            aria-labelledby={titleId}
            onCancel={(event) => {
                event.preventDefault();
                if (!busy) onClose();
            }}
        >
            <div className="modal-header">
                <div>
                    {eyebrow && <p className="eyebrow">{eyebrow}</p>}
                    <h2 id={titleId} ref={heading} tabIndex={-1}>{title}</h2>
                </div>
                <button
                    className="icon-button"
                    disabled={busy}
                    onClick={onClose}
                    aria-label="Close dialog"
                >
                    <Icon name="close" />
                </button>
            </div>
            {children}
        </dialog>
    );
}
export function Receipt({
    transaction,
    onClose,
}: {
    transaction: Transaction;
    onClose: () => void;
}) {
    return (
        <Modal
            title="All taken care of."
            eyebrow="TRANSACTION RECEIPT"
            onClose={onClose}
        >
            <div className="receipt-hero">
                <span className="success-icon">
                    <Icon name="check" size={30} />
                </span>
                <p className="receipt-amount">
                    {formatMoney(transaction.amount)}{' '}
                    <span>{transaction.currency}</span>
                </p>
                <Status value="completed" />
            </div>
            <dl className="detail-list">
                <div>
                    <dt>Transaction</dt>
                    <dd className="capitalize">{transaction.type}</dd>
                </div>
                <div>
                    <dt>Date & time</dt>
                    <dd>{dateLabel(transaction.created_at, true)}</dd>
                </div>
                {transaction.source_account && (
                    <div>
                        <dt>From account</dt>
                        <dd className="reference">
                            {transaction.source_account}
                        </dd>
                    </div>
                )}
                {transaction.destination_account && (
                    <div>
                        <dt>To account</dt>
                        <dd className="reference">
                            {transaction.destination_account}
                        </dd>
                    </div>
                )}
                <div>
                    <dt>Reference</dt>
                    <dd className="reference flex items-center gap-2">
                        <span title={transaction.reference}>
                            {shortReference(transaction.reference)}
                        </span>
                        <CopyButton
                            value={transaction.reference}
                            label="Copy transaction reference"
                        />
                    </dd>
                </div>
            </dl>
            <p className="fine-print text-center">
                Demo transaction. No real money was moved.
            </p>
            <Button className="w-full" onClick={onClose}>
                Done
                <Icon name="check" size={18} />
            </Button>
        </Modal>
    );
}
