import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function statusClass(status) {
    if (status === 'connected') return 'bg-emerald-100 text-emerald-800';
    if (status === 'failed') return 'bg-red-100 text-red-700';
    return 'bg-slate-100 text-slate-700';
}

export default function TelegramSettingsTab({ telegram = {} }) {
    const { flash = {}, errors = {}, locale = 'en' } = usePage().props;
    const [botToken, setBotToken] = useState('');
    const [channel, setChannel] = useState('');
    const [thread, setThread] = useState('');
    const [busy, setBusy] = useState('');

    const t = {
        en: {
            botTitle: 'Telegram bot',
            botNote: 'Paste the token from BotFather and click Bind. The bot will start answering /start.',
            token: 'Bot token',
            bind: 'Bind',
            unbind: 'Unbind',
            webhook: 'Webhook URL',
            username: 'Bot',
            channelTitle: 'Telegram channel or group',
            channelNote: 'Add the bot to the group (or channel). For a company group with topics, create a dedicated topic, then paste the group and the topic ID — or a message link from that topic. Case alerts go only there.',
            channelField: 'Channel/group @username, ID, or message link',
            threadField: 'Topic ID (optional)',
            threadPlaceholder: '12, or a message link from the topic',
            bindChannel: 'Bind chat',
            unbindChannel: 'Unbind chat',
            notConfigured: 'not configured',
            connected: 'connected',
            failed: 'failed',
        },
        ru: {
            botTitle: 'Telegram-бот',
            botNote: 'Вставьте токен из BotFather и нажмите «Привязать». Бот начнёт отвечать на /start.',
            token: 'Токен бота',
            bind: 'Привязать',
            unbind: 'Отвязать',
            webhook: 'Webhook URL',
            username: 'Бот',
            channelTitle: 'Telegram-канал или группа',
            channelNote: 'Добавьте бота в группу (или канал). Если это корпоративная группа с ветками — создайте отдельную тему, затем вставьте группу и ID ветки или ссылку на сообщение из этой темы. Кейсы будут писаться только туда.',
            channelField: 'Канал/группа: @username, ID или ссылка',
            threadField: 'ID ветки (необязательно)',
            threadPlaceholder: '12 или ссылка на сообщение из ветки',
            bindChannel: 'Привязать чат',
            unbindChannel: 'Отвязать чат',
            notConfigured: 'не настроено',
            connected: 'подключено',
            failed: 'ошибка',
        },
        uk: {
            botTitle: 'Telegram-бот',
            botNote: 'Вставте токен з BotFather і натисніть «Привʼязати». Бот почне відповідати на /start.',
            token: 'Токен бота',
            bind: 'Привʼязати',
            unbind: 'Відвʼязати',
            webhook: 'Webhook URL',
            username: 'Бот',
            channelTitle: 'Telegram-канал або група',
            channelNote: 'Додайте бота в групу (або канал). Якщо це корпоративна група з гілками — створіть окрему тему, потім вставте групу та ID гілки або посилання на повідомлення з цієї теми. Кейси писатимуться лише туди.',
            channelField: 'Канал/група: @username, ID або посилання',
            threadField: 'ID гілки (необовʼязково)',
            threadPlaceholder: '12 або посилання на повідомлення з гілки',
            bindChannel: 'Привʼязати чат',
            unbindChannel: 'Відвʼязати чат',
            notConfigured: 'не налаштовано',
            connected: 'підключено',
            failed: 'помилка',
        },
    }[locale] ?? {
        botTitle: 'Telegram bot',
        botNote: 'Paste the token from BotFather and click Bind. The bot will start answering /start.',
        token: 'Bot token',
        bind: 'Bind',
        unbind: 'Unbind',
        webhook: 'Webhook URL',
        username: 'Bot',
        channelTitle: 'Telegram channel or group',
        channelNote: 'Add the bot to the group (or channel). For a company group with topics, create a dedicated topic, then paste the group and the topic ID — or a message link from that topic. Case alerts go only there.',
        channelField: 'Channel/group @username, ID, or message link',
        threadField: 'Topic ID (optional)',
        threadPlaceholder: '12, or a message link from the topic',
        bindChannel: 'Bind chat',
        unbindChannel: 'Unbind chat',
        notConfigured: 'not configured',
        connected: 'connected',
        failed: 'failed',
    };

    const statusLabel = (status) => t[status] ?? t.notConfigured;

    const submit = (key, url, data = {}) => {
        setBusy(key);
        router.post(url, data, {
            preserveScroll: true,
            onFinish: () => setBusy(''),
            onSuccess: () => {
                if (key === 'bot') {
                    setBotToken('');
                }
                if (key === 'channel') {
                    setChannel('');
                    setThread('');
                }
            },
        });
    };

    return (
        <div className="space-y-6">
            {flash.telegram_status && (
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {flash.telegram_status}
                </div>
            )}

            <section className="app-widget p-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 className="admin-section-title">{t.botTitle}</h2>
                        <p className="mt-1 text-sm text-slate-600">{t.botNote}</p>
                    </div>
                    <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(telegram.bot_status)}`}>
                        {statusLabel(telegram.bot_status)}
                    </span>
                </div>

                {telegram.bot_username && (
                    <p className="mt-3 text-sm text-slate-700">
                        {t.username}: <strong>@{telegram.bot_username}</strong>
                        {telegram.bot_name ? ` · ${telegram.bot_name}` : ''}
                    </p>
                )}

                <label className="mt-4 block text-sm font-medium text-slate-700">{t.token}</label>
                <input
                    type="password"
                    value={botToken}
                    onChange={(event) => setBotToken(event.target.value)}
                    placeholder={telegram.has_bot_token ? '••••••••' : '123456:ABC...'}
                    className="admin-input mt-1"
                />

                <div className="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        disabled={busy !== '' || !botToken.trim()}
                        onClick={() => submit('bot', route('telegram.bot.connect'), { bot_token: botToken })}
                        className="admin-btn-primary h-9 px-3 text-sm disabled:opacity-60"
                    >
                        {t.bind}
                    </button>
                    {telegram.bot_status === 'connected' && (
                        <button
                            type="button"
                            disabled={busy !== ''}
                            onClick={() => submit('bot-off', route('telegram.bot.disconnect'))}
                            className="admin-btn-secondary h-9 px-3 text-sm disabled:opacity-60"
                        >
                            {t.unbind}
                        </button>
                    )}
                </div>

                <div className="mt-4">
                    <label className="mb-1 block text-sm font-medium text-slate-600">{t.webhook}</label>
                    <p className="break-all rounded-lg border border-[var(--admin-outline-variant)] bg-[var(--admin-surface-container-low)] px-3 py-2 font-mono text-sm text-slate-900">
                        {telegram.webhook_url}
                    </p>
                </div>

                {errors.telegram && <p className="mt-3 text-sm text-red-600">{errors.telegram}</p>}
            </section>

            <section className="app-widget p-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 className="admin-section-title">{t.channelTitle}</h2>
                        <p className="mt-1 text-sm text-slate-600">{t.channelNote}</p>
                    </div>
                    <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(telegram.channel_status)}`}>
                        {statusLabel(telegram.channel_status)}
                    </span>
                </div>

                {telegram.channel_title && (
                    <p className="mt-3 text-sm text-slate-700">
                        {telegram.channel_title}
                        {telegram.channel_username ? ` · ${telegram.channel_username}` : ''}
                        {telegram.channel_thread_id ? ` · #${telegram.channel_thread_id}` : ''}
                    </p>
                )}

                <label className="mt-4 block text-sm font-medium text-slate-700">{t.channelField}</label>
                <input
                    type="text"
                    value={channel}
                    onChange={(event) => setChannel(event.target.value)}
                    placeholder="@youngfashionshow"
                    className="admin-input mt-1"
                />

                <label className="mt-4 block text-sm font-medium text-slate-700">{t.threadField}</label>
                <input
                    type="text"
                    value={thread}
                    onChange={(event) => setThread(event.target.value)}
                    placeholder={t.threadPlaceholder}
                    className="admin-input mt-1"
                />

                <div className="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        disabled={busy !== '' || !channel.trim() || telegram.bot_status !== 'connected'}
                        onClick={() => submit('channel', route('telegram.channel.connect'), { channel, thread })}
                        className="admin-btn-primary h-9 px-3 text-sm disabled:opacity-60"
                    >
                        {t.bindChannel}
                    </button>
                    {telegram.channel_status === 'connected' && (
                        <button
                            type="button"
                            disabled={busy !== ''}
                            onClick={() => submit('channel-off', route('telegram.channel.disconnect'))}
                            className="admin-btn-secondary h-9 px-3 text-sm disabled:opacity-60"
                        >
                            {t.unbindChannel}
                        </button>
                    )}
                </div>

                {errors.telegram_channel && <p className="mt-3 text-sm text-red-600">{errors.telegram_channel}</p>}
                {telegram.last_error && (telegram.bot_status !== 'connected' || telegram.channel_status === 'failed') && (
                    <p className="mt-3 text-sm text-red-600">{telegram.last_error}</p>
                )}
            </section>
        </div>
    );
}
