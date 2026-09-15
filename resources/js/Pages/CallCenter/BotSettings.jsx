import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const SECTION_KEYS = [
    'general',
    'rehearsals',
    'app',
    'brands',
    'tickets',
    'packages',
    'show_day',
    'backstage',
    'workshop',
    'payments',
    'service_tiers',
    'sales_support',
    'escalation',
];

export default function CallCenterBotSettings({ tab: initialTab = 'general', sections = [] }) {
    const { locale = 'en', flash = {}, errors = {} } = usePage().props;
    const t = copy[locale] ?? copy.en;
    const activeTab = SECTION_KEYS.includes(initialTab) ? initialTab : 'general';
    const section = useMemo(
        () => sections.find((item) => item.key === activeTab) ?? sections[0] ?? null,
        [sections, activeTab],
    );

    const [instructions, setInstructions] = useState(section?.instructions ?? '');
    const [enabled, setEnabled] = useState(Boolean(section?.enabled ?? true));
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setInstructions(section?.instructions ?? '');
        setEnabled(Boolean(section?.enabled ?? true));
    }, [section?.key, section?.instructions, section?.enabled]);

    const switchTab = (nextTab) => {
        router.get(route('call-center.bot-settings'), { tab: nextTab }, {
            preserveState: false,
            preserveScroll: true,
            replace: true,
        });
    };

    const save = () => {
        if (!section) {
            return;
        }

        setBusy(true);
        router.patch(route('call-center.bot-settings.update', section.key), {
            instructions,
            enabled,
        }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <AdminLayout title={t.pageTitle}>
            <Head title={t.pageTitle} />

            <div className="mb-6 flex flex-wrap gap-2 border-b border-[var(--admin-outline-variant)] pb-4">
                {SECTION_KEYS.map((key) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => switchTab(key)}
                        className={activeTab === key ? 'admin-tab-active' : 'admin-tab'}
                    >
                        {t.tabs[key]}
                    </button>
                ))}
            </div>

            {flash.voice_bot_status && (
                <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {flash.voice_bot_status}
                </div>
            )}

            {section && (
                <div className="app-widget p-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 className="admin-section-title">{t.tabs[section.key] ?? section.title}</h2>
                            <p className="mt-1 text-sm text-[var(--admin-on-surface-variant)]">
                                {t.descriptions[section.key]}
                            </p>
                        </div>
                        <label className="flex items-center gap-3">
                            <input
                                type="checkbox"
                                checked={enabled}
                                onChange={(event) => setEnabled(event.target.checked)}
                                className="rounded border-slate-300"
                            />
                            <span className="text-sm font-semibold text-slate-900">{t.enabled}</span>
                        </label>
                    </div>

                    <label className="mt-4 mb-1 block text-sm font-medium text-slate-600">{t.instructions}</label>
                    <textarea
                        value={instructions}
                        onChange={(event) => setInstructions(event.target.value)}
                        className="admin-input min-h-[320px] font-sans"
                        rows={16}
                    />
                    {errors.instructions && <p className="mt-2 text-sm text-red-600">{errors.instructions}</p>}

                    <div className="mt-4">
                        <button
                            type="button"
                            className="admin-btn-primary disabled:opacity-60"
                            disabled={busy}
                            onClick={save}
                        >
                            {t.save}
                        </button>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}

const copy = {
    en: {
        pageTitle: 'Bot settings',
        enabled: 'Enabled',
        instructions: 'Instructions for the voice assistant',
        save: 'Save',
        tabs: {
            general: 'General rules',
            rehearsals: 'Rehearsals and schedule',
            app: 'App',
            brands: 'Brands / looks / fitting',
            tickets: 'Tickets and guests',
            packages: 'Packages and services',
            show_day: 'Show Day',
            backstage: 'Backstage',
            workshop: 'Workshop',
            payments: 'Payments',
            service_tiers: 'Basic / Premium / VIP',
            sales_support: 'Sales / Customer Support',
            escalation: 'Escalation',
        },
        descriptions: {
            general: 'How the voice assistant should treat standard questions, the app, and human support. Not a place to invent show facts.',
            rehearsals: 'Rehearsal and schedule inquiries. Do not invent times or locations; those come from the app / YFS Core later.',
            app: 'YFS App and Help Center questions. Technical steps that are not written here must not be invented.',
            brands: 'Brands, looks, fitting, and shoes. Package-specific facts stay in the app until YFS Core tools exist.',
            tickets: 'Tickets, QR codes, and guests. Do not invent ticket counts or handover steps.',
            packages: 'What clients ask about packages and extras. Service level is in Basic / Premium / VIP; contents stay in the app.',
            show_day: 'Show Day arrival, entrance, parking. No addresses or times unless they appear in these instructions or future tools.',
            backstage: 'Backstage and child accompaniment. Do not invent pass or access rules.',
            workshop: 'Workshop timing and conflicts. Buying a workshop is a Sales opportunity, not an org-support loop.',
            payments: 'Payments and one-off individual questions. Do not invent payment methods.',
            service_tiers: 'Editable Basic / Premium / VIP support model from the client policy.',
            sales_support: 'After contract, org questions stay with Customer Support. Return to Sales only for a new purchase.',
            escalation: 'When to involve a human, when to call back, and when not to send the caller to Sales.',
        },
    },
    ru: {
        pageTitle: 'Настройки бота',
        enabled: 'Включено',
        instructions: 'Инструкции для голосового ассистента',
        save: 'Сохранить',
        tabs: {
            general: 'Общие правила',
            rehearsals: 'Репетиции и расписание',
            app: 'Приложение',
            brands: 'Бренды / образы / fitting',
            tickets: 'Билеты и гости',
            packages: 'Пакеты и услуги',
            show_day: 'Show Day',
            backstage: 'Backstage',
            workshop: 'Workshop',
            payments: 'Оплата',
            service_tiers: 'Basic / Premium / VIP',
            sales_support: 'Sales / Customer Support',
            escalation: 'Эскалация',
        },
        descriptions: {
            general: 'Как голосовой сотрудник отвечает на стандартные вопросы и когда нужна живая поддержка. Не место для выдуманных фактов шоу.',
            rehearsals: 'Репетиции и расписание. Не выдумывать время и место — они будут из приложения / YFS Core.',
            app: 'Вопросы по YFS App и Help Center. Технические шаги, которых нет в инструкции, не выдумывать.',
            brands: 'Бренды, образы, fitting, обувь. Конкретные факты пакета — в приложении, пока нет YFS Core.',
            tickets: 'Билеты, QR и гости. Не выдумывать количество билетов и процедуру передачи.',
            packages: 'Вопросы о составе пакетов и доп. услугах. Уровень сервиса — в Basic / Premium / VIP.',
            show_day: 'Приезд, вход, парковка. Без адресов и времени, если их нет в инструкции или в будущих tools.',
            backstage: 'Backstage и сопровождение ребёнка. Правила пропуска не выдумывать.',
            workshop: 'Workshop и пересечения с репетицией. Покупка workshop — это Sales, не оргвопрос.',
            payments: 'Оплата и единичные индивидуальные вопросы. Способы оплаты не выдумывать.',
            service_tiers: 'Редактируемая модель поддержки Basic / Premium / VIP из клиентской политики.',
            sales_support: 'После договора оргвопросы остаются у Customer Support. В Sales — только новая покупка.',
            escalation: 'Когда подключать человека, когда перезванивать и когда не отправлять звонящего в Sales.',
        },
    },
    uk: {
        pageTitle: 'Налаштування бота',
        enabled: 'Увімкнено',
        instructions: 'Інструкції для голосового асистента',
        save: 'Зберегти',
        tabs: {
            general: 'Загальні правила',
            rehearsals: 'Репетиції та розклад',
            app: 'Застосунок',
            brands: 'Бренди / образи / fitting',
            tickets: 'Квитки та гості',
            packages: 'Пакети та послуги',
            show_day: 'Show Day',
            backstage: 'Backstage',
            workshop: 'Workshop',
            payments: 'Оплата',
            service_tiers: 'Basic / Premium / VIP',
            sales_support: 'Sales / Customer Support',
            escalation: 'Ескалація',
        },
        descriptions: {
            general: 'Як голосовий співробітник ставиться до стандартних питань і коли потрібна людина. Не місце для вигаданих фактів шоу.',
            rehearsals: 'Репетиції та розклад. Не вигадувати час і місце — вони будуть із застосунку / YFS Core.',
            app: 'Питання щодо YFS App і Help Center. Технічні кроки, яких немає в інструкції, не вигадувати.',
            brands: 'Бренди, образи, fitting, взуття. Конкретні факти пакета — в застосунку, поки немає YFS Core.',
            tickets: 'Квитки, QR і гості. Не вигадувати кількість квитків і процедуру передачі.',
            packages: 'Питання про наповнення пакетів і додаткові послуги. Рівень сервісу — у Basic / Premium / VIP.',
            show_day: 'Приїзд, вхід, паркування. Без адрес і часу, якщо їх немає в інструкції або в майбутніх tools.',
            backstage: 'Backstage і супровід дитини. Правила перепустки не вигадувати.',
            workshop: 'Workshop і перетини з репетицією. Купівля workshop — це Sales, не організаційне питання.',
            payments: 'Оплата та поодинокі індивідуальні питання. Способи оплати не вигадувати.',
            service_tiers: 'Редагована модель підтримки Basic / Premium / VIP з клієнтської політики.',
            sales_support: 'Після договору оргпитання залишаються в Customer Support. У Sales — лише нова покупка.',
            escalation: 'Коли підключати людину, коли передзвонювати і коли не відправляти абонента в Sales.',
        },
    },
};
