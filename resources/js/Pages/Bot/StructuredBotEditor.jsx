import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Link2, Plus, Save, SlidersHorizontal, Sparkles, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import AiAnalysisWorkflowModal from './AiAnalysisWorkflowModal';
import BotSettingsModal from './BotSettingsModal';
import FormLinksModal from './FormLinksModal';

const copy = {
    en: {
        title: 'Bot management',
        subtitle: 'Topics and the fixed messages bound to each topic.',
        enabled: 'Bot enabled',
        instructions: 'Instructions for this topic',
        templates: 'Fixed customer messages',
        templateLanguage: { en: 'English', ru: 'Russian', uk: 'Ukrainian' },
        save: 'Save and activate',
        saving: 'Saving…',
        active: 'Changes become active immediately after saving.',
        revision: 'Revision',
        noConfig: 'Add the first topic to start training the bot.',
        addTopic: 'Add topic',
        topicName: 'Topic name',
        deleteTopic: 'Delete topic',
        deleteTopicConfirm: 'Delete this topic and all of its fixed messages?',
        lastTopic: 'Keep at least one topic.',
        addTemplate: 'Add fixed message',
        templateName: 'Message name',
        deleteTemplate: 'Delete',
        deleteTemplateConfirm: 'Delete this fixed message?',
        templatesHint: 'These messages stay inside this topic and are sent to the bot as the official wording.',
        emptyTemplates: 'No fixed messages in this topic yet.',
        alwaysInclude: 'Load on every reply',
        formLinks: 'Form links',
    },
    ru: {
        title: 'Управление ботом',
        subtitle: 'Темы обучения и фиксированные сообщения, привязанные к каждой теме.',
        enabled: 'Бот включён',
        instructions: 'Инструкции по теме',
        templates: 'Фиксированные сообщения клиенту',
        templateLanguage: { en: 'Английский', ru: 'Русский', uk: 'Украинский' },
        save: 'Сохранить и применить',
        saving: 'Сохраняем…',
        active: 'После сохранения изменения сразу применяются к следующим ответам.',
        revision: 'Ревизия',
        noConfig: 'Добавьте первую тему, чтобы начать обучение бота.',
        addTopic: 'Добавить тему',
        topicName: 'Название темы',
        deleteTopic: 'Удалить тему',
        deleteTopicConfirm: 'Удалить эту тему и все её фиксированные сообщения?',
        lastTopic: 'Должна остаться хотя бы одна тема.',
        addTemplate: 'Добавить сообщение',
        templateName: 'Название сообщения',
        deleteTemplate: 'Удалить',
        deleteTemplateConfirm: 'Удалить это фиксированное сообщение?',
        templatesHint: 'Сообщения хранятся внутри темы и не пропадают при переключении разделов.',
        emptyTemplates: 'В этой теме пока нет фиксированных сообщений.',
        alwaysInclude: 'Подгружать в каждый ответ',
        formLinks: 'Ссылки на анкеты',
    },
    uk: {
        title: 'Керування ботом',
        subtitle: 'Теми навчання та фіксовані повідомлення, привʼязані до кожної теми.',
        enabled: 'Бот увімкнено',
        instructions: 'Інструкції за темою',
        templates: 'Фіксовані повідомлення клієнту',
        templateLanguage: { en: 'Англійська', ru: 'Російська', uk: 'Українська' },
        save: 'Зберегти й застосувати',
        saving: 'Зберігаємо…',
        active: 'Після збереження зміни одразу застосовуються до наступних відповідей.',
        revision: 'Ревізія',
        noConfig: 'Додайте першу тему, щоб почати навчання бота.',
        addTopic: 'Додати тему',
        topicName: 'Назва теми',
        deleteTopic: 'Видалити тему',
        deleteTopicConfirm: 'Видалити цю тему та всі її фіксовані повідомлення?',
        lastTopic: 'Має залишитися хоча б одна тема.',
        addTemplate: 'Додати повідомлення',
        templateName: 'Назва повідомлення',
        deleteTemplate: 'Видалити',
        deleteTemplateConfirm: 'Видалити це фіксоване повідомлення?',
        templatesHint: 'Повідомлення зберігаються всередині теми і не зникають при перемиканні розділів.',
        emptyTemplates: 'У цій темі ще немає фіксованих повідомлень.',
        alwaysInclude: 'Підвантажувати в кожну відповідь',
        formLinks: 'Посилання на анкети',
    },
};

function nextId(prefix, used) {
    const base = `${prefix}_`.replace(/[^a-z0-9_]/g, '') || 'item_';
    let candidate = `${base}${Math.random().toString(36).slice(2, 8)}`;
    while (used.includes(candidate)) {
        candidate = `${base}${Math.random().toString(36).slice(2, 8)}`;
    }

    return candidate.slice(0, 64);
}

function topicLabel(topic, locale) {
    return topic?.labels?.[locale] ?? topic?.labels?.en ?? topic?.label ?? topic?.id ?? '';
}

export default function StructuredBotEditor({ bot }) {
    const { locale = 'en', flash = {} } = usePage().props;
    const t = copy[locale] ?? copy.en;
    const [activeKey, setActiveKey] = useState(bot.prompt_config?.topics?.[0]?.id ?? '');
    const [templateLanguage, setTemplateLanguage] = useState(locale === 'uk' ? 'uk' : locale === 'ru' ? 'ru' : 'en');
    const [analysisOpen, setAnalysisOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [formLinksOpen, setFormLinksOpen] = useState(false);
    const [newTopicName, setNewTopicName] = useState('');
    const [newTemplateName, setNewTemplateName] = useState('');

    const [enabledSaving, setEnabledSaving] = useState(false);

    const form = useForm({
        bot_enabled: bot.bot_enabled ?? true,
        prompt_revision: bot.prompt_revision ?? 0,
        prompt_config: bot.prompt_config ?? { schema_version: 2, topics: [], business_values: {} },
    });

    const topics = form.data.prompt_config?.topics ?? [];
    const usedTopicIds = topics.map((topic) => topic.id);
    const usedTemplateIds = topics.flatMap((topic) => (topic.templates ?? []).map((item) => item.id));

    useEffect(() => {
        if (topics.length === 0) {
            if (activeKey !== '') {
                setActiveKey('');
            }
            return;
        }
        if (!topics.some((topic) => topic.id === activeKey)) {
            setActiveKey(topics[0].id);
        }
    }, [topics, activeKey]);

    const activeTopic = useMemo(
        () => topics.find((topic) => topic.id === activeKey) ?? topics[0] ?? null,
        [activeKey, topics],
    );
    const activeIndex = topics.findIndex((topic) => topic.id === activeTopic?.id);

    const setPromptConfig = (next) => {
        form.setData('prompt_config', next);
    };

    const updateTopics = (nextTopics) => {
        setPromptConfig({
            ...form.data.prompt_config,
            topics: nextTopics,
        });
    };

    const updateActiveTopic = (patch) => {
        if (activeIndex < 0) {
            return;
        }
        const next = [...topics];
        next[activeIndex] = { ...next[activeIndex], ...patch };
        updateTopics(next);
    };

    const addTopic = () => {
        const name = newTopicName.trim() || t.topicName;
        const id = nextId('topic', usedTopicIds);
        updateTopics([
            ...topics,
            {
                id,
                always_include: false,
                labels: { en: name, ru: name, uk: name },
                descriptions: { en: '', ru: '', uk: '' },
                text: '',
                templates: [],
            },
        ]);
        setActiveKey(id);
        setNewTopicName('');
    };

    const deleteTopic = () => {
        if (!activeTopic) {
            return;
        }
        if (topics.length <= 1) {
            window.alert(t.lastTopic);
            return;
        }
        if (!window.confirm(t.deleteTopicConfirm)) {
            return;
        }
        const remaining = topics.filter((topic) => topic.id !== activeTopic.id);
        updateTopics(remaining);
        setActiveKey(remaining[0]?.id ?? '');
    };

    const addTemplate = () => {
        if (activeIndex < 0) {
            return;
        }
        const name = newTemplateName.trim() || t.templateName;
        const id = nextId('msg', usedTemplateIds);
        const next = [...topics];
        next[activeIndex] = {
            ...next[activeIndex],
            templates: [
                ...(next[activeIndex].templates ?? []),
                { id, label: name, texts: { en: '', ru: '', uk: '' } },
            ],
        };
        updateTopics(next);
        setNewTemplateName('');
    };

    const updateTemplate = (templateIndex, patch) => {
        if (activeIndex < 0) {
            return;
        }
        const next = [...topics];
        const templates = [...(next[activeIndex].templates ?? [])];
        templates[templateIndex] = { ...templates[templateIndex], ...patch };
        next[activeIndex] = { ...next[activeIndex], templates };
        updateTopics(next);
    };

    const deleteTemplate = (templateIndex) => {
        if (!window.confirm(t.deleteTemplateConfirm)) {
            return;
        }
        if (activeIndex < 0) {
            return;
        }
        const next = [...topics];
        next[activeIndex] = {
            ...next[activeIndex],
            templates: (next[activeIndex].templates ?? []).filter((_, index) => index !== templateIndex),
        };
        updateTopics(next);
    };

    const toggleBotEnabled = (checked) => {
        form.setData('bot_enabled', checked);
        setEnabledSaving(true);
        router.post(route('bot-management.enabled.update'), {
            bot_enabled: checked,
        }, {
            preserveScroll: true,
            onError: () => form.setData('bot_enabled', Boolean(bot.bot_enabled)),
            onFinish: () => setEnabledSaving(false),
        });
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('bot-management.update'), {
            preserveScroll: true,
            onSuccess: (page) => {
                const revision = page.props?.bot?.prompt_revision;
                if (revision !== undefined) {
                    form.setData('prompt_revision', revision);
                }
            },
        });
    };

    return (
        <AdminLayout title={t.title}>
            <Head title={t.title} />

            {flash?.bot_status && (
                <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {flash.bot_status}
                </div>
            )}

            <form onSubmit={submit}>
                <div className="mb-4 grid items-center gap-3 lg:grid-cols-[1fr_auto_1fr]">
                    <div>
                        <h1 className="text-xl font-semibold text-slate-900">{t.title}</h1>
                        <p className="mt-1 text-sm text-slate-600">{t.subtitle}</p>
                    </div>
                    <div className="flex flex-wrap gap-2 justify-self-start lg:justify-self-center">
                        <button type="button" onClick={() => setAnalysisOpen(true)} className="admin-btn-secondary">
                            <Sparkles className="h-4 w-4 text-violet-600" />
                            AI-анализ
                        </button>
                        <button type="button" onClick={() => setFormLinksOpen(true)} className="admin-btn-secondary">
                            <Link2 className="h-4 w-4 text-violet-600" />
                            {t.formLinks}
                        </button>
                        <button type="button" onClick={() => setSettingsOpen(true)} className="admin-btn-secondary">
                            <SlidersHorizontal className="h-4 w-4 text-slate-600" />
                            Настройки бота
                        </button>
                    </div>
                    <div className="text-left text-xs text-slate-500 lg:justify-self-end lg:text-right">
                        <div>{t.revision}: {form.data.prompt_revision}</div>
                        <div>{t.active}</div>
                    </div>
                </div>

                <section className="app-widget mb-4 p-4">
                    <label className="flex items-center gap-3">
                        <input
                            type="checkbox"
                            checked={Boolean(form.data.bot_enabled)}
                            disabled={enabledSaving}
                            onChange={(event) => toggleBotEnabled(event.target.checked)}
                            className="rounded border-slate-300"
                        />
                        <span className="text-sm font-semibold text-slate-900">{t.enabled}</span>
                    </label>
                </section>

                <div className="grid grid-cols-1 gap-4 xl:grid-cols-[280px_minmax(0,1fr)]">
                    <nav className="app-widget flex max-h-[75vh] flex-col p-2">
                        <div className="min-h-0 flex-1 overflow-y-auto">
                            {topics.map((topic) => (
                                <button
                                    key={topic.id}
                                    type="button"
                                    onClick={() => setActiveKey(topic.id)}
                                    className={`mb-1 w-full rounded-lg px-3 py-2 text-left text-sm transition ${
                                        activeTopic?.id === topic.id
                                            ? 'bg-[var(--admin-primary)] font-semibold text-white'
                                            : 'text-slate-700 hover:bg-[var(--admin-surface-container-low)]'
                                    }`}
                                >
                                    <span className="block truncate">{topicLabel(topic, locale)}</span>
                                    {(topic.templates ?? []).length > 0 && (
                                        <span className={`mt-0.5 block text-[11px] ${
                                            activeTopic?.id === topic.id ? 'text-white/80' : 'text-slate-400'
                                        }`}>
                                            {(topic.templates ?? []).length}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>
                        <div className="mt-2 border-t border-slate-200 pt-2">
                            <input
                                value={newTopicName}
                                onChange={(event) => setNewTopicName(event.target.value)}
                                placeholder={t.topicName}
                                className="admin-input mb-2"
                            />
                            <button type="button" onClick={addTopic} className="admin-btn-secondary w-full">
                                <Plus className="h-4 w-4" />
                                {t.addTopic}
                            </button>
                        </div>
                    </nav>

                    <div className="space-y-4">
                        {!activeTopic ? (
                            <section className="app-widget p-6 text-sm text-slate-600">{t.noConfig}</section>
                        ) : (
                            <>
                                <section className="app-widget p-5">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <label className="min-w-0 flex-1">
                                            <span className="mb-1 block text-xs font-medium text-slate-500">{t.topicName}</span>
                                            <input
                                                value={topicLabel(activeTopic, locale)}
                                                onChange={(event) => {
                                                    const value = event.target.value;
                                                    updateActiveTopic({
                                                        labels: {
                                                            ...(activeTopic.labels ?? {}),
                                                            [locale]: value,
                                                            en: activeTopic.labels?.en || value,
                                                        },
                                                    });
                                                }}
                                                className="admin-input text-base font-semibold"
                                            />
                                        </label>
                                        <button
                                            type="button"
                                            onClick={deleteTopic}
                                            className="admin-btn-secondary text-red-600"
                                        >
                                            <Trash2 className="h-4 w-4" />
                                            {t.deleteTopic}
                                        </button>
                                    </div>

                                    <label className="mt-4 flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(activeTopic.always_include)}
                                            onChange={(event) => updateActiveTopic({ always_include: event.target.checked })}
                                        />
                                        {t.alwaysInclude}
                                    </label>

                                    <label className="mt-5 block">
                                        <span className="mb-2 block text-sm font-semibold text-slate-800">{t.instructions}</span>
                                        <textarea
                                            rows={12}
                                            value={activeTopic.text ?? ''}
                                            onChange={(event) => updateActiveTopic({ text: event.target.value })}
                                            className="admin-input min-h-64 py-3 font-mono text-sm leading-relaxed"
                                        />
                                    </label>
                                    {form.errors[`prompt_config.topics.${activeIndex}.text`] && (
                                        <p className="mt-2 text-sm text-red-600">
                                            {form.errors[`prompt_config.topics.${activeIndex}.text`]}
                                        </p>
                                    )}
                                </section>

                                <section className="app-widget p-5">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <h3 className="admin-section-title">{t.templates}</h3>
                                            <p className="mt-1 text-sm text-slate-500">{t.templatesHint}</p>
                                        </div>
                                        <div className="flex gap-1 rounded-lg bg-slate-100 p-1">
                                            {['en', 'ru', 'uk'].map((language) => (
                                                <button
                                                    key={language}
                                                    type="button"
                                                    onClick={() => setTemplateLanguage(language)}
                                                    className={`rounded-md px-3 py-1.5 text-xs font-medium ${
                                                        templateLanguage === language ? 'bg-white text-slate-900' : 'text-slate-500'
                                                    }`}
                                                >
                                                    {t.templateLanguage[language]}
                                                </button>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="mt-4 space-y-4">
                                        {(activeTopic.templates ?? []).length === 0 && (
                                            <p className="text-sm text-slate-500">{t.emptyTemplates}</p>
                                        )}
                                        {(activeTopic.templates ?? []).map((template, templateIndex) => (
                                            <div key={template.id} className="rounded-xl border border-slate-200 p-3">
                                                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                                    <input
                                                        value={template.label ?? ''}
                                                        onChange={(event) => updateTemplate(templateIndex, { label: event.target.value })}
                                                        className="admin-input max-w-md"
                                                    />
                                                    <button
                                                        type="button"
                                                        onClick={() => deleteTemplate(templateIndex)}
                                                        className="text-sm text-red-600 hover:underline"
                                                    >
                                                        {t.deleteTemplate}
                                                    </button>
                                                </div>
                                                <textarea
                                                    rows={4}
                                                    value={template.texts?.[templateLanguage] ?? ''}
                                                    onChange={(event) => updateTemplate(templateIndex, {
                                                        texts: {
                                                            ...(template.texts ?? { en: '', ru: '', uk: '' }),
                                                            [templateLanguage]: event.target.value,
                                                        },
                                                    })}
                                                    className="admin-input py-2 text-sm"
                                                />
                                            </div>
                                        ))}
                                    </div>

                                    <div className="mt-4 flex flex-col gap-2 sm:flex-row">
                                        <input
                                            value={newTemplateName}
                                            onChange={(event) => setNewTemplateName(event.target.value)}
                                            placeholder={t.templateName}
                                            className="admin-input"
                                        />
                                        <button type="button" onClick={addTemplate} className="admin-btn-secondary shrink-0">
                                            <Plus className="h-4 w-4" />
                                            {t.addTemplate}
                                        </button>
                                    </div>
                                </section>
                            </>
                        )}
                    </div>
                </div>

                {Object.keys(form.errors).length > 0 && (
                    <div className="mt-4 space-y-2">
                        {Object.entries(form.errors).map(([key, message]) => (
                            <p key={key} className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                                {message}
                            </p>
                        ))}
                    </div>
                )}

                <div className="sticky bottom-4 mt-5 flex justify-end">
                    <button type="submit" disabled={form.processing} className="admin-btn-primary disabled:opacity-60">
                        <Save className="h-4 w-4" />
                        {form.processing ? t.saving : t.save}
                    </button>
                </div>
            </form>
            <AiAnalysisWorkflowModal
                open={analysisOpen}
                onOpenChange={setAnalysisOpen}
                ready={Boolean(bot.ai_analysis_ready)}
            />
            <BotSettingsModal
                open={settingsOpen}
                onOpenChange={setSettingsOpen}
                reenableDays={bot.manual_mode_reenable_days ?? 3}
            />
            <FormLinksModal
                open={formLinksOpen}
                onOpenChange={setFormLinksOpen}
                links={bot.form_links ?? []}
                locale={locale}
            />
        </AdminLayout>
    );
}
