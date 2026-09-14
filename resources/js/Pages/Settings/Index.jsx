import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, usePage } from '@inertiajs/react';
import AiSettingsTab from './AiSettingsTab';
import InstagramSettingsTab from './InstagramSettingsTab';
import ElevenLabsSettingsTab from './ElevenLabsSettingsTab';
import TelegramSettingsTab from './TelegramSettingsTab';
import UsersTab from './UsersTab';

const TABS = ['ai', 'users', 'instagram', 'telegram', 'elevenlabs'];

export default function SettingsIndex({
    tab: initialTab = 'users',
    users = [],
    providers = [],
    roleConnections = {},
    overview = {},
    labels = {},
    metaSettings = {},
    telegram = {},
    elevenlabs = {},
}) {
    const { locale = 'en', auth } = usePage().props;
    const activeTab = TABS.includes(initialTab) ? initialTab : 'users';

    const text = {
        en: {
            pageTitle: 'Settings',
            ai: 'AI Settings',
            users: 'Users',
            instagram: 'Instagram',
            telegram: 'Telegram bot',
            elevenlabs: 'ElevenLabs',
        },
        ru: {
            pageTitle: 'Настройки',
            ai: 'AI настройки',
            users: 'Пользователи',
            instagram: 'Instagram',
            telegram: 'Telegram bot',
            elevenlabs: 'ElevenLabs',
        },
        uk: {
            pageTitle: 'Налаштування',
            ai: 'AI налаштування',
            users: 'Користувачі',
            instagram: 'Instagram',
            telegram: 'Telegram bot',
            elevenlabs: 'ElevenLabs',
        },
    };
    const t = text[locale] ?? text.en;

    const switchTab = (nextTab) => {
        router.get(route('settings.index'), { tab: nextTab }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <AdminLayout title={t.pageTitle}>
            <Head title={t.pageTitle} />

            <div className="mb-6 flex flex-wrap gap-2 border-b border-[var(--admin-outline-variant)] pb-4">
                {TABS.map((tabKey) => (
                    <button
                        key={tabKey}
                        type="button"
                        onClick={() => switchTab(tabKey)}
                        className={activeTab === tabKey ? 'admin-tab-active' : 'admin-tab'}
                    >
                        {t[tabKey]}
                    </button>
                ))}
            </div>

            {activeTab === 'ai' && (
                <AiSettingsTab
                    providers={providers}
                    roleConnections={roleConnections}
                    locale={locale}
                />
            )}
            {activeTab === 'users' && <UsersTab users={users} auth={auth} locale={locale} />}
            {activeTab === 'instagram' && (
                <InstagramSettingsTab
                    overview={overview}
                    labels={labels}
                    metaSettings={metaSettings}
                />
            )}
            {activeTab === 'telegram' && <TelegramSettingsTab telegram={telegram} />}
            {activeTab === 'elevenlabs' && <ElevenLabsSettingsTab elevenlabs={elevenlabs} />}
        </AdminLayout>
    );
}
