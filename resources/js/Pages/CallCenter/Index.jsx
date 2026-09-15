import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Input } from '@/Components/ui/input';
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

function suggestionLabel(item, unknown) {
    const name = item.name || unknown;
    return item.phone ? `${name} · ${item.phone}` : name;
}

export default function CallCenterIndex({ calls = {}, selectedCall = null, filters = {} }) {
    const { locale = 'en' } = usePage().props;
    const t = copy[locale] ?? copy.en;
    const rows = Array.isArray(calls.data) ? calls.data : [];
    const currentPage = calls.current_page ?? 1;
    const lastPage = calls.last_page ?? 1;
    const activePhone = filters.phone || '';
    const [query, setQuery] = useState(filters.label || filters.q || filters.phone || '');
    const [suggestions, setSuggestions] = useState([]);
    const [openSuggestions, setOpenSuggestions] = useState(false);
    const [activeIndex, setActiveIndex] = useState(-1);
    const boxRef = useRef(null);
    const skipSuggestRef = useRef(false);

    useEffect(() => {
        setQuery(filters.label || filters.q || filters.phone || '');
    }, [filters.label, filters.q, filters.phone]);

    useEffect(() => {
        const onClick = (event) => {
            if (boxRef.current && !boxRef.current.contains(event.target)) {
                setOpenSuggestions(false);
            }
        };
        document.addEventListener('mousedown', onClick);
        return () => document.removeEventListener('mousedown', onClick);
    }, []);

    useEffect(() => {
        if (skipSuggestRef.current) {
            skipSuggestRef.current = false;
            return undefined;
        }

        const term = query.trim();
        if (term.length < 1 || term === (filters.label || '')) {
            setSuggestions([]);
            setOpenSuggestions(false);
            return undefined;
        }

        const handle = window.setTimeout(async () => {
            try {
                const url = route('call-center.contacts', { q: term });
                const response = await fetch(url, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    return;
                }
                const payload = await response.json();
                const items = Array.isArray(payload.contacts) ? payload.contacts : [];
                setSuggestions(items);
                setActiveIndex(items.length > 0 ? 0 : -1);
                setOpenSuggestions(items.length > 0);
            } catch {
                setSuggestions([]);
            }
        }, 200);

        return () => window.clearTimeout(handle);
    }, [query, filters.label]);

    const listParams = (extra = {}) => {
        const params = { ...extra };
        if (params.phone) {
            delete params.q;
        } else if (activePhone) {
            params.phone = activePhone;
        } else if (filters.q) {
            params.q = filters.q;
        }
        Object.keys(params).forEach((key) => {
            if (params[key] === undefined || params[key] === null || params[key] === '') {
                delete params[key];
            }
        });
        return params;
    };

    const applyFilter = (params) => {
        router.get(route('call-center.index'), params, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const selectContact = (item) => {
        skipSuggestRef.current = true;
        setQuery(suggestionLabel(item, t.unknown));
        setSuggestions([]);
        setOpenSuggestions(false);
        applyFilter({ phone: item.phone_normalized || item.phone });
    };

    const submitSearch = () => {
        const term = query.trim();
        setOpenSuggestions(false);
        if (term === '') {
            applyFilter({});
            return;
        }
        applyFilter({ q: term });
    };

    const clearFilter = () => {
        skipSuggestRef.current = true;
        setQuery('');
        setSuggestions([]);
        setOpenSuggestions(false);
        applyFilter({});
    };

    const openRow = (row) => {
        applyFilter(listParams({ call: row.id, page: currentPage }));
    };

    const closeSheet = () => {
        applyFilter(listParams({ page: currentPage }));
    };

    const showAllFromNumber = () => {
        const phone = selectedCall?.contact?.phone_normalized || selectedCall?.phone_normalized || selectedCall?.phone;
        if (!phone) {
            return;
        }
        skipSuggestRef.current = true;
        setOpenSuggestions(false);
        applyFilter({ phone });
    };

    const goToPage = (url) => {
        if (!url) {
            return;
        }
        router.get(url, {}, { preserveScroll: true, preserveState: true });
    };

    const onSearchKeyDown = (event) => {
        if (event.key === 'ArrowDown' && suggestions.length > 0) {
            event.preventDefault();
            setOpenSuggestions(true);
            setActiveIndex((current) => (current + 1) % suggestions.length);
            return;
        }
        if (event.key === 'ArrowUp' && suggestions.length > 0) {
            event.preventDefault();
            setOpenSuggestions(true);
            setActiveIndex((current) => (current <= 0 ? suggestions.length - 1 : current - 1));
            return;
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            if (openSuggestions && activeIndex >= 0 && suggestions[activeIndex]) {
                selectContact(suggestions[activeIndex]);
                return;
            }
            submitSearch();
            return;
        }
        if (event.key === 'Escape') {
            setOpenSuggestions(false);
        }
    };

    const emptyMessage = useMemo(() => (
        (activePhone || filters.q) ? t.emptyFilter : t.empty
    ), [activePhone, filters.q, t.empty, t.emptyFilter]);

    const filterActive = Boolean(activePhone || filters.q);

    return (
        <AdminLayout title={t.title}>
            <Head title={t.title} />
            <div className="app-widget overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4">
                    <h1 className="text-xl font-semibold text-slate-900">{t.title}</h1>
                    <p className="mt-1 text-sm text-slate-500">{t.subtitle}</p>
                    <div className="relative mt-4 max-w-xl" ref={boxRef}>
                        <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500" htmlFor="voice-call-search">
                            {t.search}
                        </label>
                        <div className="flex gap-2">
                            <Input
                                id="voice-call-search"
                                value={query}
                                placeholder={t.searchPlaceholder}
                                autoComplete="off"
                                onChange={(event) => setQuery(event.target.value)}
                                onFocus={() => {
                                    if (suggestions.length > 0) {
                                        setOpenSuggestions(true);
                                    }
                                }}
                                onKeyDown={onSearchKeyDown}
                            />
                            {filterActive && (
                                <button type="button" className="admin-btn-secondary shrink-0" onClick={clearFilter}>
                                    {t.clear}
                                </button>
                            )}
                        </div>
                        {openSuggestions && suggestions.length > 0 && (
                            <ul className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                                {suggestions.map((item, index) => (
                                    <li key={`${item.id}-${item.phone_normalized}`}>
                                        <button
                                            type="button"
                                            className={`flex w-full flex-col items-start px-3 py-2 text-left text-sm ${index === activeIndex ? 'bg-violet-50' : 'hover:bg-slate-50'}`}
                                            onMouseEnter={() => setActiveIndex(index)}
                                            onMouseDown={(event) => event.preventDefault()}
                                            onClick={() => selectContact(item)}
                                        >
                                            <span className="font-medium text-slate-900">{item.name || t.unknown}</span>
                                            <span className="text-xs text-slate-500">{item.phone || '—'}</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
                {rows.length === 0 ? (
                    <p className="px-5 py-8 text-sm text-slate-500">{emptyMessage}</p>
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
                                {(selectedCall.contact?.phone_normalized || selectedCall.phone) && (
                                    <button type="button" className="admin-btn-secondary" onClick={showAllFromNumber}>
                                        {t.showAllFromNumber}
                                    </button>
                                )}
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
        emptyFilter: 'No calls match this filter.',
        search: 'Find a caller',
        searchPlaceholder: 'Name or phone',
        clear: 'Clear',
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
        showAllFromNumber: 'Show all calls from this number',
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
        emptyFilter: 'По этому фильтру звонков нет.',
        search: 'Найти собеседника',
        searchPlaceholder: 'Имя или телефон',
        clear: 'Сбросить',
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
        showAllFromNumber: 'Показать все звонки с этого номера',
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
        emptyFilter: 'За цим фільтром дзвінків немає.',
        search: 'Знайти співрозмовника',
        searchPlaceholder: 'Імʼя або телефон',
        clear: 'Скинути',
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
        showAllFromNumber: 'Показати всі дзвінки з цього номера',
        conversation: 'Розмова',
        summary: 'Коротко',
        recording: 'Запис',
        openRecording: 'Відкрити запис',
        emptyTranscript: 'Транскрипта для цього дзвінка немає.',
        client: 'Клієнт',
        assistant: 'YFS Assistant',
    },
};
