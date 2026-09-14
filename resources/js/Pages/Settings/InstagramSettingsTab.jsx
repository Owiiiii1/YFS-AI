import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';

function statusBadgeClass(status) {
    if (status === 'connected') return 'bg-emerald-100 text-emerald-700';
    if (['failed', 'disabled', 'needs_reconnect'].includes(status)) return 'bg-amber-100 text-amber-700';
    return 'bg-slate-100 text-slate-700';
}

function tokenBadgeClass(tokenStatus) {
    if (tokenStatus === 'ok') return 'bg-emerald-100 text-emerald-700';
    if (['refresh_due', 'failed', 'expired'].includes(tokenStatus)) return 'bg-amber-100 text-amber-700';
    return 'bg-slate-100 text-slate-700';
}

function ReadOnlyUrl({ label, value, hint }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-slate-600">{label}</label>
            <p className="break-all rounded-lg border border-[var(--admin-outline-variant)] bg-[var(--admin-surface-container-low)] px-3 py-2 font-mono text-sm text-slate-900">
                {value || '—'}
            </p>
            {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
        </div>
    );
}

export default function InstagramSettingsTab({ overview, labels, metaSettings = {} }) {
    const { flash = {}, locale = 'en' } = usePage().props;
    const t = labels?.instagram ?? {};
    const statuses = labels?.statuses ?? {};
    const statusMessage = flash?.instagram_status;

    const flow = overview?.oauth_flow ?? 'instagram_login';
    const isInstagramFlow = flow === 'instagram_login';
    const tokenStatus = overview?.token_status ?? 'missing';
    const status = overview?.status ?? 'not_configured';
    const showConnectedActions = status === 'connected' && !['expired', 'failed'].includes(tokenStatus);

    const metaForm = useForm({
        instagram_app_id: metaSettings.instagram_app_id ?? '',
        instagram_app_secret: '',
        webhook_verify_token: metaSettings.webhook_verify_token ?? '',
        oauth_redirect_uri: metaSettings.oauth_redirect_uri ?? '',
    });

    const submitMetaSettings = (e) => {
        e.preventDefault();
        metaForm.post(route('instagram.update'), {
            preserveScroll: true,
            onSuccess: () => metaForm.setData('instagram_app_secret', ''),
        });
    };

    const metaUi = {
        en: {
            metaTitle: 'Meta App credentials',
            metaNote: 'Enter values from Meta Developer Console → your app → Instagram API. These are stored in the app, not in server .env.',
            appId: 'Instagram App ID',
            appIdHint: 'Not the main Facebook App ID from the dashboard top. Use the Instagram App ID from Meta → Instagram → API setup with Instagram login → Business login settings.',
            appSecret: 'Instagram App Secret',
            webhookToken: 'Webhook verify token',
            redirectUri: 'OAuth redirect URI',
            redirectHint: 'Must match Meta exactly — character by character, including a trailing slash if Meta added one. Copy from Meta → Instagram → Business login settings → OAuth redirect URIs.',
            webhookUrl: 'Webhook callback URL',
            webhookHint: 'Use when configuring the Instagram webhook in Meta (after webhook endpoint is enabled).',
            saveMeta: 'Save Meta credentials',
            savedSecret: 'Saved secret',
            connectHint: 'Save App ID and App Secret in the Meta section below before connecting.',
        },
        ru: {
            metaTitle: 'Данные Meta App',
            metaNote: 'Укажите значения из Meta Developer Console → ваше приложение → Instagram API. Сохраняются в приложении, не в .env на сервере.',
            appId: 'ID приложения Instagram',
            appIdHint: 'Не основной Facebook App ID сверху дашборда. Нужен Instagram App ID из Meta → Instagram → API setup with Instagram login → Business login settings.',
            appSecret: 'Секрет приложения Instagram',
            webhookToken: 'Токен проверки webhook',
            redirectUri: 'URI перенаправления OAuth',
            redirectHint: 'Должен совпадать с Meta посимвольно, включая слэш в конце, если Meta его добавила. Скопируйте из Meta → Instagram → Business login settings → OAuth redirect URIs.',
            webhookUrl: 'URL обратного вызова webhook',
            webhookHint: 'Укажите в настройках webhook в Meta (когда endpoint будет включён).',
            saveMeta: 'Сохранить данные Meta',
            savedSecret: 'Сохранённый секрет',
            connectHint: 'Сначала сохраните App ID и App Secret в блоке Meta ниже, затем подключайте Instagram.',
        },
        uk: {
            metaTitle: 'Дані Meta App',
            metaNote: 'Вкажіть значення з Meta Developer Console → ваш застосунок → Instagram API. Зберігаються в застосунку, не в .env на сервері.',
            appId: 'ID застосунку Instagram',
            appIdHint: 'Не основний Facebook App ID зверху дашборда. Потрібен Instagram App ID з Meta → Instagram → API setup with Instagram login → Business login settings.',
            appSecret: 'Секрет застосунку Instagram',
            webhookToken: 'Токен перевірки webhook',
            redirectUri: 'URI перенаправлення OAuth',
            redirectHint: 'Має збігатися з Meta посимвольно, включно зі слешем в кінці, якщо Meta його додала. Скопіюйте з Meta → Instagram → Business login settings → OAuth redirect URIs.',
            webhookUrl: 'URL зворотного виклику webhook',
            webhookHint: 'Вкажіть у налаштуваннях webhook у Meta (коли endpoint буде увімкнено).',
            saveMeta: 'Зберегти дані Meta',
            savedSecret: 'Збережений секрет',
            connectHint: 'Спочатку збережіть App ID і Secret у блоці Meta нижче, потім підключайте Instagram.',
        },
    };
    const mt = metaUi[locale] ?? metaUi.en;
    const [metaOpen, setMetaOpen] = useState(false);

    return (
        <div className="space-y-6">
            {statusMessage && (
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {statusMessage}
                </div>
            )}

            <div className="app-widget p-4">
                <h3 className="admin-section-title">{t.overview_title}</h3>
                <p className="mt-2">
                    <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass(status)}`}>
                        {statuses[status] ?? status}
                    </span>
                </p>
                <div className="mt-3 space-y-1 text-sm text-slate-600">
                    <div>{t.connected_username}: <strong className="text-slate-900">{overview?.instagram_username || '—'}</strong></div>
                    {!isInstagramFlow && (
                        <>
                            <div>{t.connected_page}: <strong className="text-slate-900">{overview?.facebook_page_name || '—'}</strong></div>
                            <div>{t.connected_page_id}: <strong className="text-slate-900">{overview?.facebook_page_id || '—'}</strong></div>
                        </>
                    )}
                    <div>{t.last_success}: <strong className="text-slate-900">{overview?.last_success || '—'}</strong></div>
                    <div>
                        {t.token_status}:{' '}
                        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${tokenBadgeClass(tokenStatus)}`}>
                            {t.token_states?.[tokenStatus] ?? tokenStatus}
                        </span>
                    </div>
                    <div>{t.token_valid_until}: <strong className="text-slate-900">{overview?.token_expires_at || '—'}</strong></div>
                    <div>{t.token_last_refreshed}: <strong className="text-slate-900">{overview?.token_refreshed_at || '—'}</strong></div>
                </div>
                {isInstagramFlow && (
                    <p className="mt-2 text-xs text-slate-500">{t.instagram_login_page_optional}</p>
                )}
                {overview?.last_error && (
                    <p className="mt-2 text-sm text-red-700">{overview.last_error}</p>
                )}
                {overview?.outbound_warning && (
                    <p className="mt-2 text-sm text-amber-700">{overview.outbound_warning}</p>
                )}
            </div>

            <div className="app-widget mx-auto w-full p-4 md:w-1/2">
                <div className="flex flex-wrap justify-center gap-2">
                    {showConnectedActions ? (
                        <>
                            <a
                                href={route('instagram.meta.redirect')}
                                className="admin-btn-primary"
                            >
                                {t.reconnect_meta}
                            </a>
                            <button
                                type="button"
                                className="admin-btn-secondary"
                                onClick={() => router.post(route('instagram.disconnect'))}
                            >
                                {t.disconnect}
                            </button>
                            <button
                                type="button"
                                className="admin-btn-secondary"
                                onClick={() => router.post(route('instagram.test'))}
                            >
                                {t.test_connection}
                            </button>
                        </>
                    ) : (
                        <a
                            href={route('instagram.meta.redirect')}
                            className={`admin-btn-primary ${!metaSettings.configured ? 'pointer-events-none opacity-50' : ''}`}
                            aria-disabled={!metaSettings.configured}
                            title={!metaSettings.configured ? mt.metaNote : undefined}
                        >
                            {t.connect_meta}
                        </a>
                    )}
                </div>
                {!metaSettings.configured && !showConnectedActions && (
                    <p className="mt-2 text-center text-sm text-amber-700">{mt.connectHint}</p>
                )}
            </div>

            <form onSubmit={submitMetaSettings} className="app-widget p-4">
                <button
                    type="button"
                    onClick={() => setMetaOpen((open) => !open)}
                    className="flex w-full items-center justify-between gap-2 text-left"
                    aria-expanded={metaOpen}
                >
                    <h2 className="admin-section-title">{mt.metaTitle}</h2>
                    <ChevronDown className={`h-4 w-4 shrink-0 text-slate-500 transition ${metaOpen ? 'rotate-180' : ''}`} />
                </button>

                {metaOpen && (
                <>
                <p className="mt-1 text-sm text-slate-600">{mt.metaNote}</p>

                <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-600">{mt.appId}</label>
                        <input
                            type="text"
                            value={metaForm.data.instagram_app_id}
                            onChange={(e) => metaForm.setData('instagram_app_id', e.target.value)}
                            className="admin-input font-mono text-sm"
                            placeholder="1784..."
                            autoComplete="off"
                        />
                        {mt.appIdHint && (
                            <p className="mt-1 text-xs text-amber-700">{mt.appIdHint}</p>
                        )}
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-600">{mt.appSecret}</label>
                        <input
                            type="password"
                            value={metaForm.data.instagram_app_secret}
                            onChange={(e) => metaForm.setData('instagram_app_secret', e.target.value)}
                            className="admin-input"
                            placeholder={metaSettings.has_app_secret ? '••••••••' : ''}
                            autoComplete="new-password"
                        />
                        {metaSettings.app_secret_masked && (
                            <p className="mt-1 text-xs text-slate-500">
                                {mt.savedSecret}: <span className="font-medium">{metaSettings.app_secret_masked}</span>
                            </p>
                        )}
                    </div>
                    <div className="md:col-span-2">
                        <label className="mb-1 block text-sm font-medium text-slate-600">{mt.webhookToken}</label>
                        <input
                            type="text"
                            value={metaForm.data.webhook_verify_token}
                            onChange={(e) => metaForm.setData('webhook_verify_token', e.target.value)}
                            className="admin-input font-mono text-sm"
                            autoComplete="off"
                        />
                        {t.webhook_verify_token_hint && (
                            <p className="mt-1 text-xs text-slate-500">{t.webhook_verify_token_hint}</p>
                        )}
                    </div>
                    <div className="md:col-span-2">
                        <label className="mb-1 block text-sm font-medium text-slate-600">{mt.redirectUri}</label>
                        <input
                            type="url"
                            value={metaForm.data.oauth_redirect_uri}
                            onChange={(e) => metaForm.setData('oauth_redirect_uri', e.target.value)}
                            className="admin-input font-mono text-sm"
                            placeholder="https://ai.youngfashionshow.com/instagram/connect/meta/callback"
                            autoComplete="off"
                        />
                        {mt.redirectHint && (
                            <p className="mt-1 text-xs text-amber-700">{mt.redirectHint}</p>
                        )}
                    </div>
                    <ReadOnlyUrl
                        label={mt.webhookUrl}
                        value={metaSettings.webhook_callback_url}
                        hint={mt.webhookHint}
                    />
                </div>

                <div className="mt-4">
                    <button
                        type="submit"
                        disabled={metaForm.processing}
                        className="admin-btn-primary disabled:opacity-60"
                    >
                        {mt.saveMeta}
                    </button>
                </div>
                </>
                )}
            </form>
        </div>
    );
}
