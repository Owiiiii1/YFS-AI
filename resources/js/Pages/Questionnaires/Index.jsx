import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/Components/ui/sheet';

const typeLabels = {
    en: {
        form_sent: 'Form sent',
        manager_request: 'Manager request',
        operator_needed: 'Operator needed',
        client_found: 'Client found',
        client_not_found: 'Client not found',
    },
    ru: {
        form_sent: 'Отправлена форма',
        manager_request: 'Запрос менеджеру',
        operator_needed: 'Нужен оператор',
        client_found: 'Клиент найден',
        client_not_found: 'Клиент не найден',
    },
    uk: {
        form_sent: 'Надіслано форму',
        manager_request: 'Запит менеджеру',
        operator_needed: 'Потрібен оператор',
        client_found: 'Клієнта знайдено',
        client_not_found: 'Клієнта не знайдено',
    },
};

function personLabel(row) {
    return row.customer_name
        || (row.participant_username ? `@${row.participant_username}` : null)
        || (row.conversation_id ? `#${row.conversation_id}` : '—');
}

function Detail({ label, children }) {
    if (children === null || children === undefined || children === '') {
        return null;
    }

    return (
        <div>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 whitespace-pre-wrap text-sm text-slate-900">{children}</div>
        </div>
    );
}

export default function QuestionnairesIndex({ replies = [] }) {
    const { locale = 'en' } = usePage().props;
    const [selectedId, setSelectedId] = useState(null);
    const [clearedIds, setClearedIds] = useState([]);
    const t = {
        en: {
            title: 'Bot replies',
            empty: 'No bot case replies yet.',
            type: 'Type',
            person: 'Person',
            channel: 'Channel',
            date: 'Created',
            telegram: 'Telegram',
            details: 'Case details',
            summary: 'Summary',
            question: 'Question',
            email: 'Email',
            form: 'Form',
            dialog: 'Open dialog',
            client: 'JFS client',
            children: 'Children',
            topics: 'Topics',
            lookup: 'Lookup',
            yes: 'Sent',
            no: 'Not sent',
            close: 'Close',
        },
        ru: {
            title: 'Ответы бота',
            empty: 'Обращений бота пока нет.',
            type: 'Тип',
            person: 'Человек',
            channel: 'Канал',
            date: 'Создано',
            telegram: 'Telegram',
            details: 'Подробности обращения',
            summary: 'Резюме',
            question: 'Вопрос',
            email: 'Email',
            form: 'Форма',
            dialog: 'Открыть диалог',
            client: 'Клиент JFS',
            children: 'Дети',
            topics: 'Темы',
            lookup: 'Поиск',
            yes: 'Отправлено',
            no: 'Не отправлено',
            close: 'Закрыть',
        },
        uk: {
            title: 'Відповіді бота',
            empty: 'Звернень бота поки немає.',
            type: 'Тип',
            person: 'Людина',
            channel: 'Канал',
            date: 'Створено',
            telegram: 'Telegram',
            details: 'Подробиці звернення',
            summary: 'Резюме',
            question: 'Питання',
            email: 'Email',
            form: 'Форма',
            dialog: 'Відкрити діалог',
            client: 'Клієнт JFS',
            children: 'Діти',
            topics: 'Теми',
            lookup: 'Пошук',
            yes: 'Надіслано',
            no: 'Не надіслано',
            close: 'Закрити',
        },
    }[locale] ?? {
        title: 'Bot replies',
        empty: 'No bot case replies yet.',
        type: 'Type',
        person: 'Person',
        channel: 'Channel',
        date: 'Created',
        telegram: 'Telegram',
        details: 'Case details',
        summary: 'Summary',
        question: 'Question',
        email: 'Email',
        form: 'Form',
        dialog: 'Open dialog',
        client: 'JFS client',
        children: 'Children',
        topics: 'Topics',
        lookup: 'Lookup',
        yes: 'Sent',
        no: 'Not sent',
        close: 'Close',
    };
    const types = typeLabels[locale] ?? typeLabels.en;
    const selected = useMemo(
        () => replies.find((row) => row.id === selectedId) ?? null,
        [replies, selectedId],
    );
    const payload = selected?.payload && typeof selected.payload === 'object' ? selected.payload : {};
    const client = payload.client && typeof payload.client === 'object' ? payload.client : null;
    const children = Array.isArray(payload.children) ? payload.children : [];
    const topics = Array.isArray(payload.topics) ? payload.topics : [];
    const isUnread = (row) => Boolean(row.unread) && !clearedIds.includes(row.id);

    const openRow = (row) => {
        setSelectedId(row.id);
        if (!isUnread(row)) {
            return;
        }

        setClearedIds((current) => (current.includes(row.id) ? current : [...current, row.id]));
        router.post(route('questionnaires.read', row.id), {}, {
            preserveScroll: true,
            preserveState: true,
            preserveUrl: true,
        });
    };

    return (
        <AdminLayout title={t.title}>
            <Head title={t.title} />
            <div className="app-widget overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4">
                    <h1 className="text-xl font-semibold text-slate-900">{t.title}</h1>
                </div>
                {replies.length === 0 ? (
                    <p className="px-5 py-8 text-sm text-slate-500">{t.empty}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-slate-500">
                                <tr>
                                    <th className="px-5 py-3 font-medium">{t.type}</th>
                                    <th className="px-5 py-3 font-medium">{t.person}</th>
                                    <th className="px-5 py-3 font-medium">{t.summary}</th>
                                    <th className="px-5 py-3 font-medium">{t.telegram}</th>
                                    <th className="px-5 py-3 font-medium">{t.date}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {replies.map((row) => (
                                    <tr
                                        key={row.id}
                                        className={`cursor-pointer border-t border-slate-100 hover:bg-slate-50 ${selectedId === row.id ? 'bg-violet-50' : ''} ${isUnread(row) ? 'font-semibold' : ''}`}
                                        onClick={() => openRow(row)}
                                    >
                                        <td className="px-5 py-3 whitespace-nowrap">{types[row.type] ?? row.type}</td>
                                        <td className="px-5 py-3 whitespace-nowrap">{personLabel(row)}</td>
                                        <td className="max-w-xl px-5 py-3 font-normal text-slate-800">{row.summary || '—'}</td>
                                        <td className="px-5 py-3">{row.telegram_sent ? t.yes : t.no}</td>
                                        <td className="px-5 py-3 whitespace-nowrap">{row.created_at || '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Sheet open={selected !== null} onOpenChange={(open) => { if (!open) setSelectedId(null); }}>
                <SheetContent
                    side="right"
                    className="admin-theme w-full gap-0 overflow-y-auto p-0 sm:max-w-lg"
                >
                    {selected && (
                        <>
                            <SheetHeader className="border-b border-slate-200 px-5 py-4 pr-12">
                                <SheetTitle>{types[selected.type] ?? selected.type}</SheetTitle>
                                <SheetDescription>{personLabel(selected)}</SheetDescription>
                            </SheetHeader>

                            <div className="space-y-4 px-5 py-4">
                                <Detail label={t.channel}>{selected.channel || '—'}</Detail>
                                <Detail label={t.date}>{selected.created_at || '—'}</Detail>
                                <Detail label={t.telegram}>{selected.telegram_sent ? t.yes : t.no}</Detail>
                                <Detail label={t.summary}>{selected.summary}</Detail>
                                <Detail label={t.question}>{payload.question}</Detail>
                                <Detail label={t.email}>{payload.email}</Detail>
                                {payload.form_url && (
                                    <Detail label={t.form}>
                                        <a
                                            href={payload.form_url}
                                            className="text-violet-700 hover:underline"
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            {payload.form_url}
                                        </a>
                                    </Detail>
                                )}
                                {client && (
                                    <Detail label={t.client}>
                                        {[
                                            client.id ? `#${client.id}` : null,
                                            client.name,
                                            client.phone ? `tel. ${client.phone}` : null,
                                            client.role,
                                        ].filter(Boolean).join(' · ') || '—'}
                                    </Detail>
                                )}
                                {children.length > 0 && (
                                    <Detail label={t.children}>
                                        {children.map((child, index) => {
                                            if (!child || typeof child !== 'object') {
                                                return null;
                                            }
                                            const name = `${child.first_name ?? ''} ${child.last_name ?? ''}`.trim() || '—';
                                            return (
                                                <div key={`${name}-${index}`}>
                                                    {name}{child.birthdate ? ` (${child.birthdate})` : ''}
                                                </div>
                                            );
                                        })}
                                    </Detail>
                                )}
                                {topics.length > 0 && (
                                    <Detail label={t.topics}>{topics.join(', ')}</Detail>
                                )}
                                {payload.lookup_status && (
                                    <Detail label={t.lookup}>{payload.lookup_status}</Detail>
                                )}
                            </div>

                            {selected.conversation_id && (
                                <SheetFooter className="border-t border-slate-200 px-5 py-4">
                                    <Link
                                        href={route('dialogs.instagram', { conversation: selected.conversation_id })}
                                        className="admin-btn-primary inline-flex h-9 items-center justify-center px-3 text-sm"
                                    >
                                        {t.dialog}
                                    </Link>
                                </SheetFooter>
                            )}
                        </>
                    )}
                </SheetContent>
            </Sheet>
        </AdminLayout>
    );
}
