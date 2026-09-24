import { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Auth } from './auth';
import { AdminAccount } from './admin';
import {
    ApiError,
    errorMessage,
    readPending,
    readSession,
    request,
    saveSession,
} from './api';
import {
    dateLabel,
    formatMinor,
    formatMoney,
    initials,
    isIncoming,
    minorUnits,
    shortReference,
} from './bank';
import type {
    Account,
    Operation,
    Page,
    PendingOperation,
    Session,
    Transaction,
    User,
} from './bank';
import { MoneyDialog } from './operations';
import {
    Brand,
    Button,
    CopyButton,
    Empty,
    Icon,
    Loading,
    Notice,
    Pagination,
    Receipt,
    Status,
} from './ui';

type Screen = 'overview' | 'transactions' | 'account';
function currentScreen(): Screen {
    const value = location.hash.slice(1);
    return value === 'transactions' || value === 'account' ? value : 'overview';
}
function navigate(screen: Screen) {
    location.hash = screen;
}

function App() {
    const [session, setSession] = useState<Session | null>(readSession);
    const [checking, setChecking] = useState(!!session);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [retry, setRetry] = useState(0);
    const token = session?.access_token;
    useEffect(() => {
        function expire(event: Event) {
            if ((event as CustomEvent<string>).detail !== token) return;
            saveSession(null);
            setSession(null);
            setChecking(false);
            setMessage('Your session has ended. Sign in again to continue.');
        }
        window.addEventListener('bank:session-expired', expire);
        return () => window.removeEventListener('bank:session-expired', expire);
    }, [token]);
    useEffect(() => {
        if (!token) return;
        let active = true;
        setChecking(true);
        setError('');
        request<{ data: User }>('/auth/me', token)
            .then((result) => {
                if (active)
                    setSession((previous) =>
                        previous ? { ...previous, user: result.data } : null,
                    );
            })
            .catch((error) => {
                if (
                    active &&
                    !(error instanceof ApiError && error.status === 401)
                )
                    setError(errorMessage(error));
            })
            .finally(() => {
                if (active) setChecking(false);
            });
        return () => {
            active = false;
        };
    }, [token, retry]);
    if (!session)
        return (
            <Auth
                message={message}
                onSession={(value) => {
                    saveSession(value);
                    setChecking(true);
                    setSession(value);
                    setMessage('');
                    navigate('overview');
                }}
            />
        );
    if (checking)
        return (
            <div className="boot">
                <Brand />
                <Loading label="Opening your space…" />
            </div>
        );
    if (error)
        return (
            <div className="boot">
                <Brand />
                <Notice>{error}</Notice>
                <Button onClick={() => setRetry((value) => value + 1)}>
                    Try again
                </Button>
            </div>
        );
    return (
        <Dashboard
            key={session.user.id}
            session={session}
            onLogout={() => {
                saveSession(null);
                setSession(null);
                navigate('overview');
            }}
        />
    );
}

function Dashboard({
    session,
    onLogout,
}: {
    session: Session;
    onLogout: () => void;
}) {
    const admin = session.user.role === 'admin';
    const [screen, setScreen] = useState<Screen>(currentScreen);
    const [accounts, setAccounts] = useState<Page<Account> | null>(null);
    const [transactions, setTransactions] = useState<Page<Transaction> | null>(
        null,
    );
    const [accountPage, setAccountPage] = useState(1);
    const [transactionPage, setTransactionPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [loadingAccounts, setLoadingAccounts] = useState(true);
    const [loadingTransactions, setLoadingTransactions] = useState(true);
    const [accountError, setAccountError] = useState('');
    const [transactionError, setTransactionError] = useState('');
    const [actionError, setActionError] = useState('');
    const [busy, setBusy] = useState(false);
    const [hidden, setHidden] = useState(false);
    const [operation, setOperation] = useState<Operation | null>(null);
    const [pending, setPending] = useState<PendingOperation | null>(() =>
        readPending(session.user.id),
    );
    const [receipt, setReceipt] = useState<Transaction | null>(null);
    const [adminAccount, setAdminAccount] = useState<Account | null>(null);
    const lock = useRef(false);
    const account =
        accounts?.data.find((item) => item.currency === 'EGP') ||
        accounts?.data[0];
    useEffect(() => {
        const changed = () => {
            setScreen(currentScreen());
            setTransactionPage(1);
        };
        window.addEventListener('hashchange', changed);
        return () => window.removeEventListener('hashchange', changed);
    }, []);
    useEffect(() => {
        let active = true;
        setLoadingAccounts(true);
        setAccountError('');
        request<Page<Account>>(
            `/accounts?page=${accountPage}`,
            session.access_token,
        )
            .then((result) => {
                if (active) setAccounts(result);
            })
            .catch((error) => {
                if (active) setAccountError(errorMessage(error));
            })
            .finally(() => {
                if (active) setLoadingAccounts(false);
            });
        return () => {
            active = false;
        };
    }, [session.access_token, accountPage, revision]);
    useEffect(() => {
        let active = true;
        setLoadingTransactions(true);
        setTransactionError('');
        request<Page<Transaction>>(
            `/transactions?page=${screen === 'transactions' ? transactionPage : 1}`,
            session.access_token,
        )
            .then((result) => {
                if (active) setTransactions(result);
            })
            .catch((error) => {
                if (active) setTransactionError(errorMessage(error));
            })
            .finally(() => {
                if (active) setLoadingTransactions(false);
            });
        return () => {
            active = false;
        };
    }, [session.access_token, transactionPage, screen, revision]);
    async function openAccount() {
        if (lock.current) return;
        lock.current = true;
        setBusy(true);
        setActionError('');
        try {
            await request('/accounts', session.access_token, {
                currency: 'EGP',
            });
            setRevision((value) => value + 1);
        } catch (error) {
            setActionError(errorMessage(error));
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    async function logout() {
        if (lock.current) return;
        lock.current = true;
        setBusy(true);
        setActionError('');
        try {
            await request('/auth/logout', session.access_token, {});
            onLogout();
        } catch (error) {
            setActionError(errorMessage(error));
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    const navigation: { screen: Screen; label: string; icon: string }[] = [
        {
            screen: 'overview',
            label: admin ? 'Accounts' : 'Overview',
            icon: 'overview',
        },
        { screen: 'transactions', label: 'Transactions', icon: 'history' },
        {
            screen: 'account',
            label: admin ? 'Profile' : 'My account',
            icon: 'account',
        },
    ];
    const actionsDisabled =
        !account ||
        loadingAccounts ||
        !!accountError ||
        account.status === 'frozen' ||
        !!pending;
    const refresh = () => setRevision((value) => value + 1);
    const greeting =
        new Date().getHours() < 12
            ? 'Good morning'
            : new Date().getHours() < 18
              ? 'Good afternoon'
              : 'Good evening';
    return (
        <div className="app-shell">
            <a
                href="#main-content"
                className="skip-link"
                onClick={(event) => {
                    event.preventDefault();
                    document.getElementById('main-content')?.focus();
                }}
            >
                Skip to content
            </a>
            <aside className="sidebar">
                <a
                    href="#overview"
                    className="brand-link"
                    aria-label="MVP Bank home"
                >
                    <Brand small />
                </a>
                <div className="workspace-label">
                    {admin ? 'BANK WORKSPACE' : 'YOUR PERSONAL SPACE'}
                </div>
                <nav aria-label="Main navigation">
                    {navigation.map((item) => (
                        <a
                            key={item.screen}
                            href={`#${item.screen}`}
                            aria-current={
                                screen === item.screen ? 'page' : undefined
                            }
                            className={`nav-item ${screen === item.screen ? 'active' : ''}`}
                        >
                            <Icon name={item.icon} />
                            <span>{item.label}</span>
                            {screen === item.screen && (
                                <span className="nav-dot" />
                            )}
                        </a>
                    ))}
                </nav>
                <div className="sidebar-bottom">
                    <div className="sidebar-note">
                        <span className="sidebar-note-icon">
                            <Icon name="spark" size={23} />
                        </span>
                        <h3>
                            A little more
                            <br />
                            possibility.
                        </h3>
                        <p>
                            Your everyday money.
                            <br />A fresh perspective.
                        </p>
                        <span className="mini-wordmark">mvp.</span>
                    </div>
                    <div className="sidebar-demo">
                        <span className="demo-badge">
                            <span /> Demo banking
                        </span>
                        <p>Explore freely. No real money.</p>
                    </div>
                    <button
                        className="signout"
                        onClick={logout}
                        disabled={busy}
                    >
                        <Icon name="logout" size={18} />
                        <span>{busy ? 'Please wait…' : 'Sign out'}</span>
                    </button>
                </div>
            </aside>
            <div className="main-shell">
                <header className="topbar">
                    <div className="topbar-location">
                        <span>MVP Bank</span>
                        <span className="text-stone-300">/</span>
                        <strong>
                            {
                                navigation.find(
                                    (item) => item.screen === screen,
                                )?.label
                            }
                        </strong>
                    </div>
                    <div className="topbar-right">
                        <span className="topbar-date">
                            {new Intl.DateTimeFormat('en-GB', {
                                day: 'numeric',
                                month: 'long',
                                year: 'numeric',
                            }).format(new Date())}
                        </span>
                        <span className="topbar-divider" />
                        <button
                            className="profile-button"
                            onClick={() => navigate('account')}
                            aria-label="View your profile"
                        >
                            <span className="avatar">
                                {initials(session.user.name)}
                            </span>
                            <span className="profile-name">
                                {session.user.name}
                                <small>
                                    {admin
                                        ? 'Administrator'
                                        : 'Personal account'}
                                </small>
                            </span>
                            <Icon name="chevron" size={15} />
                        </button>
                    </div>
                </header>
                <main className="main-content" id="main-content" tabIndex={-1}>
                    <div className="page-heading">
                        <div>
                            <p className="eyebrow">
                                {screen === 'overview'
                                    ? `${greeting}, ${session.user.name.split(' ')[0]}`
                                    : screen === 'transactions'
                                      ? 'EVERY MOVE, IN ONE PLACE'
                                      : 'THE DETAILS THAT MATTER'}
                            </p>
                            <h1>
                                {screen === 'overview'
                                    ? admin
                                        ? 'A clear view of the bank.'
                                        : 'Your money, at a glance.'
                                    : screen === 'transactions'
                                      ? 'Your money in motion.'
                                      : admin
                                        ? 'Your profile.'
                                        : 'Your everyday account.'}
                            </h1>
                            <p>
                                {screen === 'overview'
                                    ? admin
                                        ? 'Manage accounts and keep every change accountable.'
                                        : 'A little clarity for whatever comes next.'
                                    : screen === 'transactions'
                                      ? 'The ins, the outs, and everything in between.'
                                      : 'Everything you need, right where you need it.'}
                            </p>
                        </div>
                        <button
                            className="icon-button refresh-button"
                            onClick={refresh}
                            disabled={loadingAccounts || loadingTransactions}
                            title="Refresh account data"
                            aria-label="Refresh account data"
                        >
                            <Icon name="refresh" />
                        </button>
                    </div>
                    {actionError && <Notice>{actionError}</Notice>}
                    {pending && !admin && (
                        <Notice tone="info">
                            A money operation still needs confirmation.{' '}
                            <button
                                className="text-link"
                                onClick={() => setOperation(pending.kind)}
                                disabled={
                                    !account ||
                                    account.reference !== pending.account
                                }
                            >
                                Review and retry safely{' '}
                                <span aria-hidden="true">↗</span>
                            </button>
                        </Notice>
                    )}
                    {!admin && account?.status === 'frozen' && (
                        <Notice tone="info">
                            Your account is frozen. You can still view your
                            balance and receipts, but money operations are
                            paused.
                        </Notice>
                    )}
                    {screen === 'overview' &&
                        (admin ? (
                            <section className="panel">
                                <div className="panel-heading">
                                    <div>
                                        <h2>Customer accounts</h2>
                                        <p>
                                            {accounts?.meta.total ?? '—'}{' '}
                                            accounts across the bank
                                        </p>
                                    </div>
                                    <span className="soft-icon">
                                        <Icon name="shield" />
                                    </span>
                                </div>
                                {loadingAccounts ? (
                                    <Loading />
                                ) : accountError ? (
                                    <Notice>
                                        {accountError}{' '}
                                        <button
                                            className="text-link"
                                            onClick={refresh}
                                        >
                                            Retry
                                        </button>
                                    </Notice>
                                ) : !accounts?.data.length ? (
                                    <Empty
                                        title="No accounts yet."
                                        icon="account"
                                    >
                                        Customer accounts will appear here once
                                        opened.
                                    </Empty>
                                ) : (
                                    <div className="table-scroll">
                                        <table className="account-table">
                                            <thead>
                                                <tr>
                                                    <th>Account reference</th>
                                                    <th>Customer</th>
                                                    <th>Balance</th>
                                                    <th>Status</th>
                                                    <th>
                                                        <span className="sr-only">
                                                            Manage
                                                        </span>
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {accounts.data.map((item) => (
                                                    <tr key={item.reference}>
                                                        <td>
                                                            <button
                                                                className="text-link reference"
                                                                onClick={() =>
                                                                    setAdminAccount(
                                                                        item,
                                                                    )
                                                                }
                                                            >
                                                                {shortReference(
                                                                    item.reference,
                                                                )}
                                                            </button>
                                                        </td>
                                                        <td>#{item.user_id}</td>
                                                        <td className="tabular-nums">
                                                            {formatMoney(
                                                                item.balance,
                                                            )}{' '}
                                                            {item.currency}
                                                        </td>
                                                        <td>
                                                            <Status
                                                                value={
                                                                    item.status
                                                                }
                                                            />
                                                        </td>
                                                        <td>
                                                            <Button
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    setAdminAccount(
                                                                        item,
                                                                    )
                                                                }
                                                            >
                                                                Manage
                                                                <Icon
                                                                    name="chevron"
                                                                    size={16}
                                                                />
                                                            </Button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                                <Pagination
                                    meta={accounts?.meta}
                                    onChange={setAccountPage}
                                    busy={loadingAccounts}
                                />
                            </section>
                        ) : (
                            <>
                                <div className="overview-top">
                                    <section className="balance-panel">
                                        <div
                                            className="balance-orbit"
                                            aria-hidden="true"
                                        />
                                        <div className="balance-top">
                                            <span className="flex items-center gap-2">
                                                <span className="balance-currency">
                                                    E£
                                                </span>
                                                Everyday account
                                            </span>
                                            <span className="balance-label">
                                                EGYPTIAN POUND
                                            </span>
                                        </div>
                                        {loadingAccounts ? (
                                            <Loading label="Loading your balance…" />
                                        ) : accountError ? (
                                            <Notice>
                                                {accountError}
                                                <button
                                                    className="text-link"
                                                    onClick={refresh}
                                                >
                                                    Retry
                                                </button>
                                            </Notice>
                                        ) : (
                                            <>
                                                <div className="balance-caption">
                                                    Available balance
                                                    <button
                                                        className="icon-button"
                                                        onClick={() =>
                                                            setHidden(
                                                                (value) =>
                                                                    !value,
                                                            )
                                                        }
                                                        aria-label={
                                                            hidden
                                                                ? 'Show balance'
                                                                : 'Hide balance'
                                                        }
                                                    >
                                                        <Icon
                                                            name={
                                                                hidden
                                                                    ? 'hidden'
                                                                    : 'eye'
                                                            }
                                                            size={17}
                                                        />
                                                    </button>
                                                </div>
                                                <div className="balance-number">
                                                    {hidden
                                                        ? '••••••'
                                                        : formatMoney(
                                                              account?.balance ||
                                                                  '0.00',
                                                          )}
                                                    <span>EGP</span>
                                                </div>
                                                <div className="balance-footer">
                                                    <span>
                                                        {account ? (
                                                            <>
                                                                <span className="balance-reference">
                                                                    {shortReference(
                                                                        account.reference,
                                                                    )}
                                                                </span>
                                                                <CopyButton
                                                                    value={
                                                                        account.reference
                                                                    }
                                                                />
                                                            </>
                                                        ) : (
                                                            'Your next chapter starts here.'
                                                        )}
                                                    </span>
                                                    {account ? (
                                                        <Status
                                                            value={
                                                                account.status
                                                            }
                                                        />
                                                    ) : (
                                                        <span className="balance-ready">
                                                            <span /> Ready when
                                                            you are
                                                        </span>
                                                    )}
                                                </div>
                                            </>
                                        )}
                                    </section>
                                    <section className="quick-actions">
                                        <p className="eyebrow">
                                            MAKE YOUR NEXT MOVE
                                        </p>
                                        <h2>
                                            A little more
                                            <br />
                                            at your fingertips.
                                        </h2>
                                        <div className="action-buttons">
                                            <button
                                                disabled={actionsDisabled}
                                                onClick={() =>
                                                    setOperation('transfer')
                                                }
                                            >
                                                <span className="action-icon action-lime">
                                                    <Icon
                                                        name="send"
                                                        size={22}
                                                    />
                                                </span>
                                                <span>Send money</span>
                                            </button>
                                            <button
                                                disabled={actionsDisabled}
                                                onClick={() =>
                                                    setOperation('deposit')
                                                }
                                            >
                                                <span className="action-icon">
                                                    <Icon
                                                        name="plus"
                                                        size={22}
                                                    />
                                                </span>
                                                <span>Add money</span>
                                            </button>
                                            <button
                                                disabled={actionsDisabled}
                                                onClick={() =>
                                                    setOperation('withdrawal')
                                                }
                                            >
                                                <span className="action-icon">
                                                    <Icon
                                                        name="down"
                                                        size={22}
                                                    />
                                                </span>
                                                <span>Withdraw</span>
                                            </button>
                                        </div>
                                    </section>
                                </div>
                                {!loadingAccounts &&
                                !accountError &&
                                !account ? (
                                    <section className="onboarding-panel">
                                        <span className="onboarding-icon">
                                            <Icon name="spark" size={32} />
                                        </span>
                                        <div>
                                            <p className="eyebrow">
                                                A FRESH START
                                            </p>
                                            <h2>
                                                Your first account. Your next
                                                possibility.
                                            </h2>
                                            <p>
                                                Open an EGP account, add some
                                                demo money, and make yourself at
                                                home.
                                            </p>
                                        </div>
                                        <Button
                                            onClick={openAccount}
                                            disabled={busy}
                                        >
                                            {busy
                                                ? 'Opening…'
                                                : 'Open your account'}
                                            <Icon name="arrow" size={18} />
                                        </Button>
                                    </section>
                                ) : (
                                    <div className="overview-bottom">
                                        <Activity
                                            transactions={transactions}
                                            account={account}
                                            admin={false}
                                            loading={loadingTransactions}
                                            error={transactionError}
                                            onRetry={refresh}
                                            onReceipt={setReceipt}
                                            compact
                                        />
                                        <section className="panel account-focus">
                                            <div className="panel-heading">
                                                <h2>A little perspective</h2>
                                                <Icon name="spark" size={19} />
                                            </div>
                                            <ActivitySummary
                                                transactions={
                                                    transactionError ||
                                                    loadingTransactions ||
                                                    accountError ||
                                                    loadingAccounts
                                                        ? []
                                                        : transactions?.data ||
                                                          []
                                                }
                                                account={account}
                                                hidden={hidden}
                                                unavailable={
                                                    !!transactionError ||
                                                    loadingTransactions ||
                                                    !!accountError ||
                                                    loadingAccounts
                                                }
                                            />
                                            <div className="focus-footer">
                                                <Icon name="info" size={17} />
                                                <p>
                                                    Based on your latest{' '}
                                                    {transactions?.data
                                                        .length || 0}{' '}
                                                    transactions, not your full
                                                    account history.
                                                </p>
                                            </div>
                                            <button
                                                className="focus-link"
                                                onClick={() =>
                                                    navigate('account')
                                                }
                                            >
                                                Get to know your account
                                                <Icon name="arrow" size={17} />
                                            </button>
                                        </section>
                                    </div>
                                )}
                            </>
                        ))}
                    {screen === 'transactions' && (
                        <Activity
                            transactions={transactions}
                            account={account}
                            admin={admin}
                            loading={
                                loadingTransactions ||
                                (!admin && loadingAccounts)
                            }
                            error={
                                transactionError || (!admin ? accountError : '')
                            }
                            onRetry={refresh}
                            onReceipt={setReceipt}
                            onPage={setTransactionPage}
                        />
                    )}
                    {screen === 'account' && (
                        <div className="account-layout">
                            <section className="panel profile-panel">
                                <span className="avatar avatar-large">
                                    {initials(session.user.name)}
                                </span>
                                <h2>{session.user.name}</h2>
                                <p>{session.user.email}</p>
                                <span className="profile-role">
                                    {admin
                                        ? 'Bank administrator'
                                        : 'Personal banking'}
                                </span>
                                <dl className="detail-list">
                                    <div>
                                        <dt>Session</dt>
                                        <dd>Signed in</dd>
                                    </div>
                                    <div>
                                        <dt>Access expires</dt>
                                        <dd>
                                            {dateLabel(
                                                session.expires_at,
                                                true,
                                            )}
                                        </dd>
                                    </div>
                                </dl>
                                <Button
                                    variant="secondary"
                                    className="w-full"
                                    disabled={busy}
                                    onClick={logout}
                                >
                                    Sign out
                                    <Icon name="logout" size={17} />
                                </Button>
                            </section>
                            <section className="panel account-details">
                                <div className="panel-heading">
                                    <div>
                                        <h2>
                                            {admin
                                                ? 'Your workspace'
                                                : 'Account details'}
                                        </h2>
                                        <p>
                                            {admin
                                                ? 'A clear record of every decision.'
                                                : 'A home for your everyday money.'}
                                        </p>
                                    </div>
                                    <Icon
                                        name={admin ? 'shield' : 'account'}
                                        size={23}
                                    />
                                </div>
                                {admin ? (
                                    <>
                                        <Notice tone="info">
                                            You can inspect customer accounts
                                            and transactions, freeze or unfreeze
                                            accounts, and review the audit
                                            trail.
                                        </Notice>
                                        <p className="subtitle">
                                            Every account status change needs a
                                            reason. Customer money operations
                                            are reserved for account owners.
                                        </p>
                                        <Button
                                            onClick={() => navigate('overview')}
                                        >
                                            Manage accounts
                                            <Icon name="arrow" />
                                        </Button>
                                    </>
                                ) : loadingAccounts ? (
                                    <Loading />
                                ) : accountError ? (
                                    <Notice>
                                        {accountError}{' '}
                                        <button
                                            className="text-link"
                                            onClick={refresh}
                                        >
                                            Retry
                                        </button>
                                    </Notice>
                                ) : !account ? (
                                    <Empty
                                        title="Make room for your next move."
                                        icon="account"
                                        action={
                                            <Button
                                                onClick={openAccount}
                                                disabled={busy}
                                            >
                                                {busy
                                                    ? 'Opening…'
                                                    : 'Open EGP account'}
                                            </Button>
                                        }
                                    >
                                        Your account details will appear here
                                        once you open an account.
                                    </Empty>
                                ) : (
                                    <>
                                        <div className="account-detail-banner">
                                            <span>Everyday account</span>
                                            <Status value={account.status} />
                                        </div>
                                        <dl className="detail-list">
                                            <div>
                                                <dt>Available balance</dt>
                                                <dd>
                                                    {hidden
                                                        ? '••••••'
                                                        : formatMoney(
                                                              account.balance,
                                                          )}{' '}
                                                    {account.currency}
                                                    <button
                                                        className="icon-button"
                                                        onClick={() =>
                                                            setHidden(
                                                                (value) =>
                                                                    !value,
                                                            )
                                                        }
                                                        aria-label={
                                                            hidden
                                                                ? 'Show balance'
                                                                : 'Hide balance'
                                                        }
                                                    >
                                                        <Icon
                                                            name={
                                                                hidden
                                                                    ? 'hidden'
                                                                    : 'eye'
                                                            }
                                                            size={16}
                                                        />
                                                    </button>
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>Currency</dt>
                                                <dd>Egyptian pound · EGP</dd>
                                            </div>
                                            <div>
                                                <dt>Opened</dt>
                                                <dd>
                                                    {dateLabel(
                                                        account.created_at,
                                                    )}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>Account reference</dt>
                                                <dd className="reference">
                                                    {account.reference}
                                                    <CopyButton
                                                        value={
                                                            account.reference
                                                        }
                                                    />
                                                </dd>
                                            </div>
                                        </dl>
                                        <Notice tone="info">
                                            Share your account reference with
                                            another MVP Bank customer to receive
                                            a transfer.
                                        </Notice>
                                        <Button
                                            variant="secondary"
                                            onClick={() =>
                                                navigate('transactions')
                                            }
                                        >
                                            View your transactions
                                            <Icon name="arrow" size={17} />
                                        </Button>
                                    </>
                                )}
                            </section>
                        </div>
                    )}
                    <footer className="app-footer">
                        <span>
                            <Icon name="shield" size={15} /> A space for your
                            everyday money.
                        </span>
                        <span>MVP Bank · Demo banking. No real money.</span>
                    </footer>
                </main>
            </div>
            {operation && account && (
                <MoneyDialog
                    session={session}
                    account={account}
                    kind={operation}
                    pending={pending}
                    onPending={setPending}
                    onClose={() => setOperation(null)}
                    onComplete={(transaction) => {
                        setOperation(null);
                        setReceipt(transaction);
                        refresh();
                    }}
                />
            )}{' '}
            {receipt && (
                <Receipt
                    transaction={receipt}
                    onClose={() => setReceipt(null)}
                />
            )}{' '}
            {adminAccount && (
                <AdminAccount
                    session={session}
                    account={adminAccount}
                    onClose={() => setAdminAccount(null)}
                    onChanged={refresh}
                    onReceipt={(transaction) => {
                        setAdminAccount(null);
                        setReceipt(transaction);
                    }}
                />
            )}
        </div>
    );
}

function Activity({
    transactions,
    account,
    admin,
    loading,
    error,
    onRetry,
    onReceipt,
    compact = false,
    onPage,
}: {
    transactions: Page<Transaction> | null;
    account?: Account;
    admin: boolean;
    loading: boolean;
    error: string;
    onRetry: () => void;
    onReceipt: (transaction: Transaction) => void;
    compact?: boolean;
    onPage?: (page: number) => void;
}) {
    const [filter, setFilter] = useState('all');
    const [search, setSearch] = useState('');
    const rows = (transactions?.data || []).filter((item) => {
        const direction = isIncoming(item, account) ? 'in' : 'out';
        return (
            (filter === 'all' || (admin ? item.type : direction) === filter) &&
            (!search ||
                [
                    item.reference,
                    item.type,
                    item.source_account,
                    item.destination_account,
                    item.amount,
                ].some((value) =>
                    value?.toLowerCase().includes(search.toLowerCase()),
                ))
        );
    });
    return (
        <section
            className={`panel activity-panel ${compact ? 'activity-compact' : ''}`}
        >
            <div className="panel-heading">
                <div>
                    <h2>
                        {compact ? 'Recent activity' : 'Transaction history'}
                    </h2>
                    {!compact && (
                        <p>
                            {transactions?.meta.total ?? '—'} transactions ·
                            Newest first
                        </p>
                    )}
                </div>
                {compact ? (
                    <button
                        className="text-link flex items-center gap-2"
                        onClick={() => navigate('transactions')}
                    >
                        View all
                        <Icon name="arrow" size={16} />
                    </button>
                ) : (
                    <span className="soft-icon">
                        <Icon name="history" />
                    </span>
                )}
            </div>
            {!compact && (
                <div className="activity-filters">
                    <div
                        className="segment-control"
                        aria-label="Filter this page"
                    >
                        {(admin
                            ? [
                                  ['all', 'All types'],
                                  ['deposit', 'Deposits'],
                                  ['withdrawal', 'Withdrawals'],
                                  ['transfer', 'Transfers'],
                              ]
                            : [
                                  ['all', 'All activity'],
                                  ['in', 'Money in'],
                                  ['out', 'Money out'],
                              ]
                        ).map(([value, label]) => (
                            <button
                                key={value}
                                className={filter === value ? 'selected' : ''}
                                onClick={() => setFilter(value)}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <input
                        className="search-input"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search this page…"
                        aria-label="Search transactions on this page"
                    />
                </div>
            )}
            {!compact && (filter !== 'all' || search) && (
                <p className="filter-note">
                    Filtering the {transactions?.data.length || 0} transactions
                    on this page.{' '}
                    <button
                        className="text-link"
                        onClick={() => {
                            setFilter('all');
                            setSearch('');
                        }}
                    >
                        Clear filters
                    </button>
                </p>
            )}
            {loading ? (
                <Loading label="Loading your activity…" />
            ) : error ? (
                <div className="p-6">
                    <Notice>
                        {error}{' '}
                        <button className="text-link" onClick={onRetry}>
                            Retry
                        </button>
                    </Notice>
                </div>
            ) : !rows.length ? (
                <Empty
                    title={
                        search || filter !== 'all'
                            ? 'No matches on this page.'
                            : 'Your story starts here.'
                    }
                >
                    {search || filter !== 'all'
                        ? 'Try another filter or browse the next page.'
                        : admin
                          ? 'Completed transactions will appear here.'
                          : 'Add money or make a transfer. Every move will have a place here.'}
                </Empty>
            ) : (
                <div className="transaction-list">
                    <div className="transaction-header">
                        <span>TRANSACTION</span>
                        <span>DATE</span>
                        <span>AMOUNT</span>
                        <span />
                    </div>
                    {(compact ? rows.slice(0, 5) : rows).map((transaction) => {
                        const incoming = isIncoming(transaction, account);
                        const title =
                            transaction.type === 'deposit'
                                ? 'Money added'
                                : transaction.type === 'withdrawal'
                                  ? 'Withdrawal'
                                  : admin
                                    ? 'Transfer'
                                    : incoming
                                      ? 'Transfer received'
                                      : 'Transfer sent';
                        const counterpart = incoming
                            ? transaction.source_account
                            : transaction.destination_account;
                        return (
                            <button
                                className="transaction-row"
                                key={transaction.reference}
                                onClick={() => onReceipt(transaction)}
                                aria-label={`${title}, ${formatMoney(transaction.amount)} ${transaction.currency}, ${dateLabel(transaction.created_at)}. View receipt`}
                            >
                                <span
                                    className={`transaction-icon ${incoming ? 'incoming' : 'outgoing'}`}
                                >
                                    <Icon
                                        name={
                                            transaction.type === 'deposit'
                                                ? 'plus'
                                                : incoming
                                                  ? 'down'
                                                  : 'send'
                                        }
                                        size={19}
                                    />
                                </span>
                                <span className="transaction-description">
                                    <strong>{title}</strong>
                                    <small>
                                        {transaction.type === 'transfer' &&
                                        counterpart
                                            ? `${incoming ? 'From' : 'To'} ${shortReference(counterpart)}`
                                            : transaction.type === 'deposit'
                                              ? 'A little more possibility'
                                              : 'From your everyday account'}
                                    </small>
                                </span>
                                <span className="transaction-date">
                                    {dateLabel(transaction.created_at)}
                                </span>
                                <span
                                    className={`transaction-amount ${incoming ? 'incoming-text' : ''}`}
                                >
                                    <strong>
                                        {admin ? '' : incoming ? '+' : '−'}
                                        {formatMoney(transaction.amount)}
                                    </strong>
                                    <small>{transaction.currency}</small>
                                </span>
                                <Icon name="chevron" size={15} />
                            </button>
                        );
                    })}
                </div>
            )}
            {!compact && onPage && (
                <Pagination
                    meta={transactions?.meta}
                    onChange={onPage}
                    busy={loading}
                />
            )}
        </section>
    );
}

function ActivitySummary({
    transactions,
    account,
    hidden,
    unavailable,
}: {
    transactions: Transaction[];
    account?: Account;
    hidden: boolean;
    unavailable: boolean;
}) {
    const incoming = transactions
        .filter((item) => isIncoming(item, account))
        .reduce((total, item) => total + minorUnits(item.amount), 0n);
    const outgoing = transactions
        .filter((item) => !isIncoming(item, account))
        .reduce((total, item) => total + minorUnits(item.amount), 0n);
    const total = incoming + outgoing;
    const percent = total ? Number((incoming * 100n) / total) : 50;
    return (
        <div className="activity-summary">
            <p>Recent money flow</p>
            <div className="flow-bar" aria-hidden="true">
                <span style={{ width: `${percent}%` }} />
            </div>
            <div className="flow-stat">
                <span>
                    <i className="flow-dot in" />
                    Money in
                </span>
                <strong>
                    {unavailable
                        ? '—'
                        : hidden
                          ? '••••'
                          : formatMinor(incoming)}{' '}
                    <small>EGP</small>
                </strong>
            </div>
            <div className="flow-stat">
                <span>
                    <i className="flow-dot out" />
                    Money out
                </span>
                <strong>
                    {unavailable
                        ? '—'
                        : hidden
                          ? '••••'
                          : formatMinor(outgoing)}{' '}
                    <small>EGP</small>
                </strong>
            </div>
        </div>
    );
}

createRoot(document.getElementById('app')!).render(<App />);
