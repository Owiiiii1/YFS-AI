import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';

function statusBadgeClass(status) {
    if (status === 'connected') return 'success';
    if (['failed', 'disabled', 'needs_reconnect'].includes(status)) return 'warn';
    return 'gray';
}

function tokenBadgeClass(tokenStatus) {
    if (tokenStatus === 'ok') return 'success';
    if (['refresh_due', 'failed', 'expired'].includes(tokenStatus)) return 'warn';
    return 'gray';
}

export default function InstagramIndex({ overview, settings, labels }) {
    const { flash = {} } = usePage().props;
    const t = labels?.instagram ?? {};
    const statuses = labels?.statuses ?? {};
    const statusMessage = flash?.instagram_status;

    const flow = overview.oauth_flow ?? 'instagram_login';
    const isInstagramFlow = flow === 'instagram_login';
    const tokenStatus = overview.token_status ?? 'missing';
    const status = overview.status ?? 'not_configured';
    const showConnectedActions = status === 'connected' && !['expired', 'failed'].includes(tokenStatus);

    const form = useForm({
        bot_enabled: overview.bot_enabled ?? true,
        auto_reply_enabled: overview.auto_reply_enabled ?? true,
        handoff_stops_ai: overview.handoff_stops_ai ?? true,
        max_ai_replies_per_conversation: settings?.max_ai_replies_per_conversation ?? '',
        max_ai_replies_per_day: settings?.max_ai_replies_per_day ?? '',
    });

    const submitSettings = (e) => {
        e.preventDefault();
        form.post(route('instagram.update'), { preserveScroll: true });
    };

    return (
        <AdminLayout title={t.title ?? 'Instagram'}>
            <Head title={t.title ?? 'Instagram'} />

            <div className="instagram-portal">
                {statusMessage && (
                    <div className="cp-widget mb-2.5">
                        <p className="m-0 text-[13px] text-emerald-800">{statusMessage}</p>
                    </div>
                )}

                <div className="cp-grid mt-0">
                    <div className="cp-widget">
                        <h3>{t.overview_title}</h3>
                        <p className="mt-2 mb-0">
                            <span className={`cp-badge ${statusBadgeClass(status)}`}>
                                {statuses[status] ?? status}
                            </span>
                        </p>
                        <div className="cp-empty mt-2 leading-relaxed">
                            <div>
                                {t.connected_username}: <strong>{overview.instagram_username || '-'}</strong>
                            </div>
                            {!isInstagramFlow && (
                                <>
                                    <div>
                                        {t.connected_page}: <strong>{overview.facebook_page_name || '-'}</strong>
                                    </div>
                                    <div>
                                        {t.connected_page_id}: <strong>{overview.facebook_page_id || '-'}</strong>
                                    </div>
                                </>
                            )}
                            <div>
                                {t.last_success}: <strong>{overview.last_success || '-'}</strong>
                            </div>
                            <div>
                                {t.token_status}:{' '}
                                <span className={`cp-badge ${tokenBadgeClass(tokenStatus)}`}>
                                    {t.token_states?.[tokenStatus] ?? tokenStatus}
                                </span>
                            </div>
                            <div>
                                {t.token_valid_until}: <strong>{overview.token_expires_at || '-'}</strong>
                            </div>
                            <div>
                                {t.token_last_refreshed}: <strong>{overview.token_refreshed_at || '-'}</strong>
                            </div>
                        </div>
                        {isInstagramFlow && (
                            <p className="cp-empty mt-2 mb-0">{t.instagram_login_page_optional}</p>
                        )}
                        {overview.last_error && (
                            <p className="cp-empty mt-2 mb-0 text-red-700">{overview.last_error}</p>
                        )}
                        {overview.outbound_warning && (
                            <p className="cp-empty mt-2 mb-0 text-amber-700">{overview.outbound_warning}</p>
                        )}
                    </div>

                    <div className="cp-widget">
                        <h3>{t.bot_status_title}</h3>
                        <div className="cp-empty m-0 leading-relaxed">
                            <div>
                                {t.bot_enabled}:{' '}
                                <strong>{overview.bot_enabled ? t.setting_enabled : t.setting_disabled}</strong>
                            </div>
                            <div>
                                {t.auto_reply_enabled}:{' '}
                                <strong>{overview.auto_reply_enabled ? t.setting_enabled : t.setting_disabled}</strong>
                            </div>
                            <div>
                                {t.handoff_stops_ai}:{' '}
                                <strong>{overview.handoff_stops_ai ? t.setting_enabled : t.setting_disabled}</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="cp-table-wrap mt-2.5 p-3">
                    <h3 className="m-0 mb-2.5">{t.primary_actions}</h3>
                    {showConnectedActions ? (
                        <>
                            <div className="cp-meta-connect-spotlight">
                                <a
                                    className="cp-btn cp-btn-primary cp-meta-connect-spotlight-btn"
                                    href={route('instagram.meta.redirect')}
                                >
                                    {t.reconnect_meta}
                                </a>
                            </div>
                            <div className="mt-2.5 flex flex-wrap justify-center gap-2">
                                <button
                                    type="button"
                                    className="cp-btn cp-btn-secondary"
                                    onClick={() => router.post(route('instagram.disconnect'))}
                                >
                                    {t.disconnect}
                                </button>
                                <button
                                    type="button"
                                    className="cp-btn cp-btn-secondary"
                                    onClick={() => router.post(route('instagram.test'))}
                                >
                                    {t.test_connection}
                                </button>
                            </div>
                        </>
                    ) : (
                        <div className="cp-meta-connect-spotlight">
                            <a
                                className="cp-btn cp-btn-primary cp-meta-connect-spotlight-btn"
                                href={route('instagram.meta.redirect')}
                            >
                                {t.connect_meta}
                            </a>
                        </div>
                    )}
                </div>

                <form onSubmit={submitSettings} className="cp-table-wrap mt-2.5 p-3">
                    <h3 className="m-0 mb-2.5">{t.safety_settings_title}</h3>
                    <div className="cp-grid">
                        <label className="cp-remember-row m-0">
                            <input
                                type="checkbox"
                                checked={form.data.bot_enabled}
                                onChange={(e) => form.setData('bot_enabled', e.target.checked)}
                            />
                            <span>{t.bot_enabled}</span>
                        </label>
                        <label className="cp-remember-row m-0">
                            <input
                                type="checkbox"
                                checked={form.data.auto_reply_enabled}
                                onChange={(e) => form.setData('auto_reply_enabled', e.target.checked)}
                            />
                            <span>{t.auto_reply_enabled}</span>
                        </label>
                        <label className="cp-remember-row m-0">
                            <input
                                type="checkbox"
                                checked={form.data.handoff_stops_ai}
                                onChange={(e) => form.setData('handoff_stops_ai', e.target.checked)}
                            />
                            <span>{t.handoff_stops_ai}</span>
                        </label>
                        <div className="cp-empty col-span-full m-0 leading-snug">{t.handoff_stops_ai_hint}</div>
                        <div>
                            <label className="mb-1.5 block text-xs">{t.max_ai_replies_per_conversation}</label>
                            <input
                                className="cp-input"
                                type="number"
                                min="1"
                                value={form.data.max_ai_replies_per_conversation}
                                onChange={(e) => form.setData('max_ai_replies_per_conversation', e.target.value)}
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-xs">{t.max_ai_replies_per_day}</label>
                            <input
                                className="cp-input"
                                type="number"
                                min="1"
                                value={form.data.max_ai_replies_per_day}
                                onChange={(e) => form.setData('max_ai_replies_per_day', e.target.value)}
                            />
                        </div>
                    </div>
                    <div className="mt-3">
                        <button
                            type="submit"
                            className="cp-btn cp-btn-primary h-[38px] w-auto px-3.5"
                            disabled={form.processing}
                        >
                            {t.save_settings}
                        </button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
