import AdminLayout from '@/Layouts/AdminLayout';
import { Head, usePage } from '@inertiajs/react';

export default function CallCenterIndex() {
    const { locale = 'en' } = usePage().props;
    const text = {
        en: {
            title: 'Call center',
            body: 'The voice assistant lives here. Telephony settings stay in Settings → ElevenLabs.',
        },
        ru: {
            title: 'Колл-центр',
            body: 'Здесь будет голосовой ассистент. Настройки телефонии — в Настройках → ElevenLabs.',
        },
        uk: {
            title: 'Кол-центр',
            body: 'Тут буде голосовий асистент. Налаштування телефонії — у Налаштуваннях → ElevenLabs.',
        },
    };
    const t = text[locale] ?? text.en;

    return (
        <AdminLayout title={t.title}>
            <Head title={t.title} />
            <div className="app-widget p-4">
                <p className="text-sm text-[var(--admin-on-surface-variant)]">{t.body}</p>
            </div>
        </AdminLayout>
    );
}
