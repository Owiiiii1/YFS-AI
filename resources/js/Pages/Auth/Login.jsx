import AuthLayout from '@/Layouts/AuthLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, ChevronDown, Eye, EyeOff, Globe } from 'lucide-react';
import { useState } from 'react';

export default function Login({ status, canResetPassword }) {
    const { locale = 'en' } = usePage().props;
    const [showPassword, setShowPassword] = useState(false);
    const [language, setLanguage] = useState(locale);

    const translations = {
        ru: {
            title: 'Вход | YoungFashionShow AI',
            brandTagline: 'AI-сервисы YoungFashionShow',
            welcome: 'С возвращением',
            subtitle: 'Войдите, чтобы управлять AI-сервисами YoungFashionShow.',
            email: 'Email',
            emailPlaceholder: 'admin@youngfashionshow.com',
            password: 'Пароль',
            passwordPlaceholder: '••••••••',
            forgotPassword: 'Забыли?',
            signingIn: 'Входим...',
            signIn: 'Войти',
            bakersNote:
                '«Единая платформа для Instagram-ассистента и будущих AI-сервисов YoungFashionShow.»',
            bakersSignature: '— YoungFashionShow AI',
        },
        en: {
            title: 'Login | YoungFashionShow AI',
            brandTagline: 'YoungFashionShow AI services',
            welcome: 'Welcome back',
            subtitle: 'Sign in to manage YoungFashionShow AI services.',
            email: 'Email address',
            emailPlaceholder: 'admin@youngfashionshow.com',
            password: 'Password',
            passwordPlaceholder: '••••••••',
            forgotPassword: 'Forgot?',
            signingIn: 'Signing in...',
            signIn: 'Sign in',
            bakersNote:
                '"A single backend for the Instagram assistant and upcoming YoungFashionShow AI services."',
            bakersSignature: '— YoungFashionShow AI',
        },
        uk: {
            title: 'Вхід | YoungFashionShow AI',
            brandTagline: 'AI-сервіси YoungFashionShow',
            welcome: 'З поверненням',
            subtitle: 'Увійдіть, щоб керувати AI-сервісами YoungFashionShow.',
            email: 'Email',
            emailPlaceholder: 'admin@youngfashionshow.com',
            password: 'Пароль',
            passwordPlaceholder: '••••••••',
            forgotPassword: 'Забули?',
            signingIn: 'Входимо...',
            signIn: 'Увійти',
            bakersNote:
                '«Єдина платформа для Instagram-асистента та майбутніх AI-сервісів YoungFashionShow.»',
            bakersSignature: '— YoungFashionShow AI',
        },
    };

    const t = translations[language] ?? translations.en;
    const languageLabels = { uk: 'Українська', en: 'English', ru: 'Русский' };

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout>
            <Head title={t.title} />

            <div className="absolute right-6 top-6 z-20 sm:right-10 sm:top-10 md:right-16">
                <label htmlFor="language" className="sr-only">Language</label>
                <div className="relative">
                    <div className="font-label inline-flex h-10 items-center gap-2 rounded-full border border-[var(--login-outline-variant)]/40 bg-[var(--login-surface-container-low)] px-4 pr-9 text-sm font-medium text-[var(--login-on-surface-variant)]">
                        <Globe className="h-4 w-4" />
                        <span>{languageLabels[language] ?? languageLabels.en}</span>
                        <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2" />
                    </div>
                    <select
                        id="language"
                        value={language}
                        onChange={(e) => setLanguage(e.target.value)}
                        className="absolute inset-0 h-10 w-full cursor-pointer opacity-0"
                    >
                        <option value="uk">Українська</option>
                        <option value="en">English</option>
                        <option value="ru">Русский</option>
                    </select>
                </div>
            </div>

            <div className="md:hidden mb-10 text-center">
                <h1 className="font-display text-2xl font-extrabold tracking-tighter text-[var(--login-primary)]">
                    YoungFashionShow AI
                </h1>
                <p className="font-label mt-1 text-xs font-semibold uppercase tracking-widest text-[var(--login-secondary)]">
                    {t.brandTagline}
                </p>
            </div>

            <div className="mb-10 text-center md:text-left">
                <h2 className="font-display text-3xl font-semibold text-[var(--login-primary)]">{t.welcome}</h2>
                <p className="mt-2 text-base text-[var(--login-on-surface-variant)]">{t.subtitle}</p>
            </div>

            {status && (
                <div className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-6">
                <div className="space-y-2">
                    <label htmlFor="email" className="font-label ml-1 block text-sm font-medium text-[var(--login-on-surface-variant)]">
                        {t.email}
                    </label>
                    <input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className="login-input w-full rounded-xl border border-[var(--login-outline-variant)]/30 bg-[var(--login-surface-container-low)] px-5 py-4 text-[var(--login-on-surface)] transition-all duration-200"
                        placeholder={t.emailPlaceholder}
                        autoComplete="username"
                        required
                    />
                    {errors.email && <p className="text-sm text-red-700">{errors.email}</p>}
                </div>

                <div className="space-y-2">
                    <div className="ml-1 flex items-center justify-between">
                        <label htmlFor="password" className="font-label text-sm font-medium text-[var(--login-on-surface-variant)]">
                            {t.password}
                        </label>
                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="font-label text-xs font-semibold uppercase tracking-wide text-[var(--login-secondary)] transition hover:text-[var(--login-primary)]"
                            >
                                {t.forgotPassword}
                            </Link>
                        )}
                    </div>
                    <div className="relative">
                        <input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className="login-input w-full rounded-xl border border-[var(--login-outline-variant)]/30 bg-[var(--login-surface-container-low)] px-5 py-4 pr-12 text-[var(--login-on-surface)] transition-all duration-200"
                            placeholder={t.passwordPlaceholder}
                            autoComplete="current-password"
                            required
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword((prev) => !prev)}
                            className="absolute right-4 top-1/2 -translate-y-1/2 text-[var(--login-on-surface-variant)] transition hover:text-[var(--login-primary)]"
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                        >
                            {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                        </button>
                    </div>
                    {errors.password && <p className="text-sm text-red-700">{errors.password}</p>}
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="group ambient-shadow flex w-full items-center justify-center gap-2 rounded-full bg-[var(--login-primary)] px-6 py-4 text-sm font-medium text-[var(--login-pastry-cream)] transition hover:bg-[var(--login-primary-container)] active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70"
                >
                    <span>{processing ? t.signingIn : t.signIn}</span>
                    <ArrowRight className="h-4 w-4 transition group-hover:translate-x-0.5" />
                </button>
            </form>

            <div className="bakers-note relative mt-16 overflow-hidden p-6">
                <p className="relative z-10 text-sm leading-relaxed text-[var(--login-on-surface-variant)]">
                    {t.bakersNote}
                </p>
                <p className="relative z-10 mt-3 text-xs font-bold uppercase tracking-widest text-[var(--login-primary)]">
                    {t.bakersSignature}
                </p>
            </div>
        </AuthLayout>
    );
}
