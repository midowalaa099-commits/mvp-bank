import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { ApiError, errorMessage, request } from './api';
import type { Session } from './bank';
import { Brand, Button, Field, Icon, Notice } from './ui';

export function Auth({
    onSession,
    message,
}: {
    onSession: (session: Session) => void;
    message: string;
}) {
    const [register, setRegister] = useState(false);
    const [busy, setBusy] = useState(false);
    const lock = useRef(false);
    const [error, setError] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (lock.current) return;
        lock.current = true;
        setBusy(true);
        setError('');
        setErrors({});
        const body = Object.fromEntries(new FormData(event.currentTarget));
        try {
            const result = await request<{ data: Session }>(
                `/auth/${register ? 'register' : 'login'}`,
                undefined,
                body,
            );
            onSession(result.data);
        } catch (error) {
            setError(errorMessage(error));
            if (error instanceof ApiError) setErrors(error.errors);
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    return (
        <main className="auth-page">
            <section className="auth-story">
                <Brand />
                <div className="story-copy">
                    <span className="story-kicker">
                        <span /> A LITTLE MORE FREEDOM
                    </span>
                    <h1>
                        Your money.
                        <br />
                        Your pace.
                        <br />
                        <em>Your possibilities.</em>
                    </h1>
                    <p>
                        A simpler space for your everyday money.
                        <br />
                        Make your next move with MVP Bank.
                    </p>
                </div>
                <div className="orbit-art" aria-hidden="true">
                    <div className="orbit orbit-one" />
                    <div className="orbit orbit-two" />
                    <div className="orbit orbit-three" />
                    <div className="orbit-center">
                        <Icon name="spark" size={72} />
                    </div>
                    <div className="orbit-dot" />
                    <div className="floating-note">
                        <span className="note-icon">
                            <Icon name="check" size={18} />
                        </span>
                        <span>
                            Made for your next move.
                            <small>Simple. Clear. In your control.</small>
                        </span>
                    </div>
                </div>
                <footer className="story-footer">
                    <span>EVERYDAY BANKING, REIMAGINED.</span>
                    <span>01 — ∞</span>
                </footer>
            </section>
            <section className="auth-form-side">
                <div className="auth-top">
                    <span className="demo-badge">
                        <span /> Demo banking
                    </span>
                    <span className="auth-top-note">
                        A fresh perspective on money
                    </span>
                </div>
                <div className="auth-form-wrap">
                    <div className="auth-mobile-brand">
                        <Brand />
                    </div>
                    <span className="eyebrow">YOUR NEXT CHAPTER</span>
                    <h2>
                        {register
                            ? 'A good place to start.'
                            : 'Welcome to your space.'}
                    </h2>
                    <p className="subtitle">
                        {register
                            ? 'A few details. A whole new way to bank.'
                            : 'Sign in and pick up where you left off.'}
                    </p>
                    <div
                        className="auth-tabs"
                        role="group"
                        aria-label="Account access"
                    >
                        <button
                            className={!register ? 'selected' : ''}
                            disabled={busy}
                            onClick={() => {
                                setRegister(false);
                                setErrors({});
                                setError('');
                            }}
                        >
                            Sign in
                        </button>
                        <button
                            className={register ? 'selected' : ''}
                            disabled={busy}
                            onClick={() => {
                                setRegister(true);
                                setErrors({});
                                setError('');
                            }}
                        >
                            Create account
                        </button>
                    </div>
                    {message && <Notice tone="info">{message}</Notice>}
                    {error && <Notice>{error}</Notice>}
                    <form
                        key={String(register)}
                        onSubmit={submit}
                        className="flex flex-col gap-5"
                    >
                        {register && (
                            <Field
                                label="Full name"
                                name="name"
                                autoComplete="name"
                                placeholder="Your full name"
                                required
                                maxLength={255}
                                error={errors.name?.[0]}
                            />
                        )}
                        <Field
                            label="Email address"
                            name="email"
                            type="email"
                            autoComplete="email"
                            placeholder="you@example.com"
                            required
                            maxLength={255}
                            error={errors.email?.[0]}
                        />
                        <Field
                            label="Password"
                            name="password"
                            type="password"
                            autoComplete={
                                register ? 'new-password' : 'current-password'
                            }
                            placeholder={
                                register
                                    ? 'At least 12 characters'
                                    : 'Enter your password'
                            }
                            required
                            minLength={register ? 12 : undefined}
                            hint={
                                register
                                    ? 'Use at least 12 characters.'
                                    : undefined
                            }
                            error={errors.password?.[0]}
                        />
                        {register && (
                            <Field
                                label="Confirm password"
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                placeholder="One more time"
                                required
                                error={errors.password_confirmation?.[0]}
                            />
                        )}
                        <Button disabled={busy} className="auth-submit">
                            {busy
                                ? 'Just a moment…'
                                : register
                                  ? 'Create your account'
                                  : 'Sign in to your account'}
                            <Icon name="arrow" />
                        </Button>
                    </form>
                    <div className="auth-assurance">
                        <Icon name="shield" size={19} />
                        <span>Your own space. Your money in view.</span>
                    </div>
                </div>
                <footer className="auth-footer">
                    <span>© {new Date().getFullYear()} MVP Bank</span>
                    <span>Simulated banking. No real money.</span>
                </footer>
            </section>
        </main>
    );
}
