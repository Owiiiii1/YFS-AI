import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/Components/ui/sheet';

function formatDuration(seconds) {
    if (seconds === null || seconds === undefined || seconds === '') {
        return '—';
    }
    const total = Math.max(0, Number(seconds));
    if (Number.isNaN(total)) {
        return '—';
    }
    const mins = Math.floor(total / 60);
    const secs = total % 60;
    return `${mins}:${String(secs).padStart(2, '0')}`;
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

export default function CallCenterIndex({ calls = {}, selectedCall = null }) {
    const { locale = 'en' } = usePage().props;
    const t = copy[locale] ?? copy.en;
    const rows = Array.isArray(calls.data) ? calls.data : [];
    const currentPage = calls.current_page ?? 1;
    const lastPage = calls.last_page ?? 1;

    const openRow = (row) => {
        router.get(route('call-center.index'), { call: row.id, page: currentPage }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const closeSheet = () => {
        router.get(route('call-center.index'), { page: currentPage }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const goToPage = (url) => {
        if (!url) {
            return;
        }
        router.get(url, {}, { preserveScroll: true, preserveState: true });
    };

    return (
        <AdminLayout title={t.title}>
            <Head title={t.title} />
            <div className="app-widget overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4">
                    <h1 className="text-xl font-semibold text-slate-900">{t.title}</h1>
                    <p className="mt-1 text-sm text-slate-500">{t.subtitle}</p>
                </div>
                {rows.length === 0 ? (
                    <p className="px-5 py-8 text-sm text-slate-500">{t.empty}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-slate-500">
                                <tr>
                                    <th className="px-5 py-3 font-medium">{t.date}</th>
                                    <th className="px-5 py-3 font-medium">{t.contact}</th>
                                    <th className="px-5 py-3 font-medium">{t.phone}</th>
                                    <th className="px-5 py-3 font-medium">{t.language}</th>
                                    <th className="px-5 py-3 font-medium">{t.duration}</th>
                                    <th className="px-5 py-3 font-medium">{t.status}</th>
                                    <th className="px-5 py-3 font-medium">{t.brief}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr
                                        key={row.id}
                                        className={`cursor-pointer border-t border-slate-100 hover:bg-slate-50 ${selectedCall?.id === row.id ? 'bg-violet-50' : ''}`}
                                        onClick={() => openRow(row)}
                                    >
                                        <td className="px-5 py-3 whitespace-nowrap">{row.started_at || '—'}</td>
                                        <td className="px-5 py-3 whitespace-nowrap">{row.contact_name || t.unknown}</td>
                                        <td className="px-5 py-3 whitespace-nowrap">{row.phone || '—'}</td>
                                        <td className="px-5 py-3 whitespace-nowrap">{row.language || '—'}</td>
                                        <td className="px-5 py-3 whitespace-nowrap">{formatDuration(row.duration_seconds)}</td>
                                        <td className="px-5 py-3 whitespace-nowrap">{row.status || '—'}</td>
                                        <td className="max-w-xl px-5 py-3 font-normal text-slate-800">{row.brief || '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {lastPage > 1 && (
                    <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-sm text-slate-600">
                        <button
                            type="button"
                            className="admin-btn-secondary disabled:opacity-40"
                            disabled={!calls.prev_page_url}
                            onClick={() => goToPage(calls.prev_page_url)}
                        >
                            {t.prev}
                        </button>
                        <span>
                            {calls.from ?? 0}–{calls.to ?? 0} / {calls.total ?? 0}
                        </span>
                        <button
                            type="button"
                            className="admin-btn-secondary disabled:opacity-40"
                            disabled={!calls.next_page_url}
                            onClick={() => goToPage(calls.next_page_url)}
                        >
                            {t.next}
                        </button>
                    </div>
                )}
            </div>

            <Sheet open={selectedCall !== null} onOpenChange={(open) => { if (!open) closeSheet(); }}>
                <SheetContent
                    side="right"
                    className="admin-theme w-full gap-0 overflow-y-auto p-0 sm:max-w-xl"
                >
                    {selectedCall && (
                        <>
                            <SheetHeader className="border-b border-slate-200 px-5 py-4 pr-12">
                                <SheetTitle>{t.details}</SheetTitle>
                                <SheetDescription>
                                    {selectedCall.contact?.name || t.unknown}
                                    {selectedCall.phone ? ` · ${selectedCall.phone}` : ''}
                                </SheetDescription>
                            </SheetHeader>

                            <div className="space-y-4 border-b border-slate-200 px-5 py-4">
                                <h2 className="text-sm font-semibold text-slate-900">{t.interlocutor}</h2>
                                <Detail label={t.contact}>{selectedCall.contact?.name || t.unknown}</Detail>
                                <Detail label={t.phone}>{selectedCall.contact?.phone || selectedCall.phone}</Detail>
                                <Detail label={t.preferredLanguage}>{selectedCall.contact?.preferred_language || '—'}</Detail>
                                <Detail label={t.callLanguage}>{selectedCall.call_language || '—'}</Detail>
                                <Detail label={t.callsCount}>{selectedCall.contact?.calls_count ?? 0}</Detail>
                                <Detail label={t.firstCall}>{selectedCall.contact?.first_called_at || '—'}</Detail>
                                <Detail label={t.lastCall}>{selectedCall.contact?.last_called_at || '—'}</Detail>
                            </div>

                            <div className="space-y-4 px-5 py-4">
                                <h2 className="text-sm font-semibold text-slate-900">{t.conversation}</h2>
                                <Detail label={t.status}>{selectedCall.status}</Detail>
                                <Detail label={t.duration}>{formatDuration(selectedCall.duration_seconds)}</Detail>
                                {selectedCall.summary && (
                                    <Detail label={t.summary}>{selectedCall.summary}</Detail>
                                )}
                                {selectedCall.recording_url && (
                                    <Detail label={t.recording}>
                                        <a
                                            href={selectedCall.recording_url}
                                            className="text-violet-700 hover:underline"
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            {t.openRecording}
                                        </a>
                                    </Detail>
                                )}
                                {(!selectedCall.transcript || selectedCall.transcript.length === 0) ? (
                                    <p className="text-sm text-slate-500">{t.emptyTranscript}</p>
                                ) : (
                                    <div className="space-y-3">
                                        {selectedCall.transcript.map((turn, index) => (
                                            <div key={`${turn.speaker}-${index}`}>
                                                <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                                    {turn.speaker === 'assistant' ? t.assistant : t.client}
                                                </div>
                                                <div className="mt-1 whitespace-pre-wrap text-sm text-slate-900">
                                                    {turn.message}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </SheetContent>
            </Sheet>
        </AdminLayout>
    );
}

const copy = {
    en: {
        title: 'Voice assistant',
        subtitle: 'Completed inbound calls. Telephony settings stay in Settings → ElevenLabs.',
        empty: 'No voice calls yet.',
        date: 'Date / time',
        contact: 'Caller',
        phone: 'Phone',
        language: 'Language',
        duration: 'Duration',
        status: 'Status',
        brief: 'Brief',
        unknown: 'Unknown',
        prev: 'Previous',
        next: 'Next',
        details: 'Call details',
        interlocutor: 'Caller',
        preferredLanguage: 'Preferred language',
        callLanguage: 'Call language',
        callsCount: 'Calls',
        firstCall: 'First call',
        lastCall: 'Last call',
        conversation: 'Conversation',
        summary: 'Summary',
        recording: 'Recording',
        openRecording: 'Open recording',
        emptyTranscript: 'No transcript for this call.',
        client: 'Client',
        assistant: 'YFS Assistant',
    },
    ru: {
        title: 'Голосовой ассистент',
        subtitle: 'Завершённые входящие звонки. Настройки телефонии — в Настройках → ElevenLabs.',
        empty: 'Голосовых звонков пока нет.',
        date: 'Дата/время',
        contact: 'Собеседник',
        phone: 'Телефон',
        language: 'Язык',
        duration: 'Длительность',
        status: 'Статус',
        brief: 'Кратко',
        unknown: 'Неизвестный',
        prev: 'Назад',
        next: 'Вперёд',
        details: 'Детали звонка',
        interlocutor: 'Собеседник',
        preferredLanguage: 'Предпочитаемый язык',
        callLanguage: 'Язык этого звонка',
        callsCount: 'Количество звонков',
        firstCall: 'Первый звонок',
        lastCall: 'Последний звонок',
        conversation: 'Разговор',
        summary: 'Кратко',
        recording: 'Запись',
        openRecording: 'Открыть запись',
        emptyTranscript: 'Транскрипта для этого звонка нет.',
        client: 'Клиент',
        assistant: 'YFS Assistant',
    },
    uk: {
        title: 'Голосовий асистент',
        subtitle: 'Завершені вхідні дзвінки. Налаштування телефонії — у Налаштуваннях → ElevenLabs.',
        empty: 'Голосових дзвінків поки немає.',
        date: 'Дата/час',
        contact: 'Співрозмовник',
        phone: 'Телефон',
        language: 'Мова',
        duration: 'Тривалість',
        status: 'Статус',
        brief: 'Коротко',
        unknown: 'Невідомий',
        prev: 'Назад',
        next: 'Далі',
        details: 'Деталі дзвінка',
        interlocutor: 'Співрозмовник',
        preferredLanguage: 'Бажана мова',
        callLanguage: 'Мова цього дзвінка',
        callsCount: 'Кількість дзвінків',
        firstCall: 'Перший дзвінок',
        lastCall: 'Останній дзвінок',
        conversation: 'Розмова',
        summary: 'Коротко',
        recording: 'Запис',
        openRecording: 'Відкрити запис',
        emptyTranscript: 'Транскрипта для цього дзвінка немає.',
        client: 'Клієнт',
        assistant: 'YFS Assistant',
    },
};
