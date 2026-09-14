import { router, usePage } from '@inertiajs/react';
import { Bot, BrainCircuit, CheckCircle2, ChevronDown, KeyRound, Loader2, PlugZap } from 'lucide-react';
import { useMemo, useState } from 'react';

const PROVIDERS = [
    { provider: 'openai', title: 'ChatGPT / OpenAI' },
    { provider: 'anthropic', title: 'Claude / Anthropic' },
    { provider: 'gemini', title: 'Gemini / Google' },
];

const ROLES = [
    { key: 'bot_runtime', icon: Bot },
    { key: 'prompt_analysis', icon: BrainCircuit },
];

export default function AiSettingsTab({ providers = [], roleConnections = {}, locale = 'en' }) {
    const { errors = {} } = usePage().props;
    const [processingProvider, setProcessingProvider] = useState(null);
    const [apiKeys, setApiKeys] = useState({});
    const [selectedModels, setSelectedModels] = useState({});
    const [openProviders, setOpenProviders] = useState({});

    const text = {
        en: {
            subtitle: 'You may connect several provider keys. Exactly one model is active for the chatbot and one for AI analysis.',
            apiKey: 'API key',
            saveKey: 'Save key',
            check: 'Check connection',
            activate: 'Activate',
            activeModel: 'Active model',
            modelSelect: 'Available models',
            noModels: 'No models loaded yet',
            notConnected: 'Not connected',
            connected: 'Connected',
            active: 'Active',
            error: 'Error',
            savedMask: 'Saved key',
            roles: { bot_runtime: 'Chatbot', prompt_analysis: 'AI analysis' },
            assignedElsewhere: 'Currently assigned',
            switchConfirm: 'Another model is active for this role. Switch it off and activate the selected model?',
            disconnectRole: 'Disable',
        },
        ru: {
            subtitle: 'Можно подключить несколько ключей провайдеров. Для чат-бота активна одна модель и для AI-анализа — одна.',
            apiKey: 'API-ключ',
            saveKey: 'Сохранить ключ',
            check: 'Проверить',
            activate: 'Активировать',
            activeModel: 'Активная модель',
            modelSelect: 'Доступные модели',
            noModels: 'Модели еще не загружены',
            notConnected: 'Не подключено',
            connected: 'Подключено',
            active: 'Активный',
            error: 'Ошибка',
            savedMask: 'Сохраненный ключ',
            roles: { bot_runtime: 'Чат-бот', prompt_analysis: 'AI-анализ' },
            assignedElsewhere: 'Сейчас назначен',
            switchConfirm: 'Для этой роли уже активна другая модель. Выключить её и включить выбранную?',
            disconnectRole: 'Отключить',
        },
        uk: {
            subtitle: 'Можна підключити кілька ключів провайдерів. Для чат-бота активна одна модель і для AI-аналізу — одна.',
            apiKey: 'API-ключ',
            saveKey: 'Зберегти ключ',
            check: 'Перевірити',
            activate: 'Активувати',
            activeModel: 'Активна модель',
            modelSelect: 'Доступні моделі',
            noModels: 'Моделі ще не завантажені',
            notConnected: 'Не підключено',
            connected: 'Підключено',
            active: 'Активний',
            error: 'Помилка',
            savedMask: 'Збережений ключ',
            roles: { bot_runtime: 'Чат-бот', prompt_analysis: 'AI-аналіз' },
            assignedElsewhere: 'Зараз призначено',
            switchConfirm: 'Для цієї ролі вже активна інша модель. Вимкнути її та активувати вибрану?',
            disconnectRole: 'Вимкнути',
        },
    };
    const t = text[locale] ?? text.en;

    const providerMap = useMemo(
        () => Object.fromEntries(providers.map((item) => [item.provider, item])),
        [providers],
    );

    const statusChip = (item) => {
        if (item?.is_connected) {
            return { label: t.connected, className: 'bg-emerald-100 text-emerald-800' };
        }
        if (item?.last_error) {
            return { label: t.error, className: 'bg-red-100 text-red-700' };
        }

        return { label: t.notConnected, className: 'bg-slate-100 text-slate-700' };
    };

    const submitWithLock = (provider, callback) => {
        setProcessingProvider(provider);
        callback({
            preserveScroll: true,
            onFinish: () => setProcessingProvider(null),
        });
    };

    const activateRole = (provider, role, model) => {
        const current = roleConnections[role];
        const isSwitch = current?.is_connected
            && (current.provider !== provider || current.model !== model);
        if (isSwitch && !window.confirm(t.switchConfirm)) {
            return;
        }

        submitWithLock(provider, (opts) =>
            router.post(
                route('ai-settings.roles.activate', { provider, role }),
                {
                    provider,
                    role,
                    model,
                    confirmed_switch: isSwitch,
                },
                opts,
            ),
        );
    };

    return (
        <div className="space-y-6">
            <div className="app-widget p-4">
                <p className="text-sm text-slate-600">{t.subtitle}</p>
            </div>

            <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-3">
                {PROVIDERS.map(({ provider, title }) => {
                    const item = providerMap[provider] ?? {};
                    const chip = statusChip(item);
                    const models = item.available_models ?? [];
                    const busy = processingProvider === provider;
                    const isOpen = Boolean(openProviders[provider]);

                    return (
                        <div key={provider} className="app-widget h-fit self-start p-4">
                            <button
                                type="button"
                                onClick={() => setOpenProviders((prev) => ({ ...prev, [provider]: !prev[provider] }))}
                                className="flex w-full items-center justify-between gap-2 text-left"
                                aria-expanded={isOpen}
                            >
                                <h2 className="admin-section-title">{title}</h2>
                                <span className="flex items-center gap-2">
                                    <span className={`rounded-full px-2 py-1 text-xs font-semibold ${chip.className}`}>
                                        {chip.label}
                                    </span>
                                    <ChevronDown className={`h-4 w-4 shrink-0 text-slate-500 transition ${isOpen ? 'rotate-180' : ''}`} />
                                </span>
                            </button>

                            {isOpen && (
                            <div className="mt-4 space-y-3">
                                <label className="block text-sm font-medium text-slate-700">{t.apiKey}</label>
                                <input
                                    type="password"
                                    placeholder={item.has_api_key ? '••••••••' : ''}
                                    value={apiKeys[provider] ?? ''}
                                    onChange={(e) => setApiKeys((prev) => ({ ...prev, [provider]: e.target.value }))}
                                    className="admin-input"
                                />
                                {item.api_key_masked && (
                                    <p className="text-xs text-slate-500">
                                        {t.savedMask}: <span className="font-medium">{item.api_key_masked}</span>
                                    </p>
                                )}

                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        disabled={busy || !(apiKeys[provider] ?? '').trim()}
                                        onClick={() =>
                                            submitWithLock(provider, (opts) =>
                                                router.post(
                                                    route('ai-settings.save-key', provider),
                                                    { provider, api_key: apiKeys[provider] },
                                                    {
                                                        ...opts,
                                                        onSuccess: () => setApiKeys((prev) => ({ ...prev, [provider]: '' })),
                                                    },
                                                ),
                                            )
                                        }
                                        className="admin-btn-primary h-9 px-3 text-sm font-medium disabled:opacity-60"
                                    >
                                        {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <KeyRound className="h-4 w-4" />}
                                        {t.saveKey}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={busy || !item.has_api_key}
                                        onClick={() =>
                                            submitWithLock(provider, (opts) =>
                                                router.post(
                                                    route('ai-settings.check', provider),
                                                    { provider },
                                                    opts,
                                                ),
                                            )
                                        }
                                        className="admin-btn-secondary h-9 px-3 disabled:opacity-60"
                                    >
                                        <PlugZap className="h-4 w-4" />
                                        {t.check}
                                    </button>
                                </div>

                                <div className="space-y-3 border-t border-slate-200 pt-4">
                                    {ROLES.map(({ key: role, icon: RoleIcon }) => {
                                        const connection = roleConnections[role] ?? {};
                                        const isActive = connection.is_connected && connection.provider === provider;
                                        const selectionKey = `${provider}:${role}`;
                                        const selectedModel = selectedModels[selectionKey]
                                            ?? (isActive ? connection.model : models[0]?.id)
                                            ?? '';
                                        const selectedIsActive = isActive && selectedModel === connection.model;

                                        return (
                                            <section
                                                key={role}
                                                className={`rounded-xl border p-3 ${
                                                    role === 'bot_runtime'
                                                        ? 'border-sky-200 bg-sky-50/50'
                                                        : 'border-violet-200 bg-violet-50/50'
                                                }`}
                                            >
                                                <div className="flex items-center justify-between gap-2">
                                                    <div className="flex items-center gap-2 font-semibold text-slate-800">
                                                        <RoleIcon className={`h-4 w-4 ${
                                                            role === 'bot_runtime' ? 'text-sky-700' : 'text-violet-700'
                                                        }`} />
                                                        {t.roles[role]}
                                                    </div>
                                                    <span className={`rounded-full px-2 py-1 text-[11px] font-semibold ${
                                                        isActive
                                                            ? 'bg-emerald-100 text-emerald-700'
                                                            : 'bg-slate-100 text-slate-500'
                                                    }`}>
                                                        {isActive ? t.active : t.notConnected}
                                                    </span>
                                                </div>

                                                {!isActive && connection.is_connected && (
                                                    <p className="mt-2 text-xs text-slate-500">
                                                        {t.assignedElsewhere}: {connection.provider} / {connection.model}
                                                    </p>
                                                )}

                                                <label className="mt-3 block text-xs font-medium text-slate-600">
                                                    {t.modelSelect}
                                                </label>
                                                <select
                                                    value={selectedModel}
                                                    onChange={(event) => setSelectedModels((previous) => ({
                                                        ...previous,
                                                        [selectionKey]: event.target.value,
                                                    }))}
                                                    className="admin-input mt-1"
                                                    disabled={models.length === 0 || busy || !item.is_connected}
                                                >
                                                    <option value="">{t.noModels}</option>
                                                    {models.map((model) => (
                                                        <option key={model.id} value={model.id}>
                                                            {model.name ?? model.id}
                                                        </option>
                                                    ))}
                                                </select>

                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        disabled={busy || !selectedModel || !item.is_connected || selectedIsActive}
                                                        onClick={() => activateRole(provider, role, selectedModel)}
                                                        className="inline-flex h-9 items-center gap-2 rounded-lg bg-emerald-600 px-3 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                                                    >
                                                        <CheckCircle2 className="h-4 w-4" />
                                                        {selectedIsActive ? t.active : t.activate}
                                                    </button>
                                                    {isActive && (
                                                        <button
                                                            type="button"
                                                            disabled={busy}
                                                            onClick={() => router.post(
                                                                route('ai-settings.roles.deactivate', role),
                                                                {},
                                                                { preserveScroll: true },
                                                            )}
                                                            className="admin-btn-secondary h-9 px-3 text-xs"
                                                        >
                                                            {t.disconnectRole}
                                                        </button>
                                                    )}
                                                </div>
                                            </section>
                                        );
                                    })}
                                </div>
                                {item.last_error && (
                                    <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                                        {item.last_error}
                                    </div>
                                )}
                            </div>
                            )}
                        </div>
                    );
                })}
            </div>

            {errors.ai && <p className="text-sm text-red-600">{errors.ai}</p>}
        </div>
    );
}
